<?php
/**
 * Turns tweets (collected by the browser snippet) into Inspiration posts.
 *
 * Each image becomes its own post: the image is downloaded into the media
 * library and set as the featured image, and the post content is just a link
 * back to the source tweet.
 */

defined( 'ABSPATH' ) || exit;

const INSPIRATION_BOARD_META_IMAGE  = '_inspiration_board_image';
const INSPIRATION_BOARD_META_SOURCE = '_inspiration_board_source';

add_action( 'rest_api_init', 'inspiration_board_register_routes' );

function inspiration_board_register_routes() {
	register_rest_route(
		'inspiration-board/v1',
		'/import',
		array(
			'methods'             => 'POST',
			'callback'            => 'inspiration_board_rest_import',
			'permission_callback' => static function () {
				return current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
			},
			'args'                => array(
				'tweets' => array(
					'type'     => 'array',
					'required' => true,
				),
				'cursor' => array(
					'type'     => 'integer',
					'required' => false,
				),
			),
		)
	);
}

/**
 * REST handler: imports a small batch of tweets and reports what happened.
 *
 * Tweets must arrive in bookmark order, newest first, across all batches.
 * The cursor is a GMT timestamp that walks down the list: an image already
 * on the board moves the cursor to that post's date, and each new image is
 * dated one second below the cursor. That slots new pins into the right
 * place on the board (which sorts by date), whether they are newer than
 * everything so far, older bookmarks being filled in later, or missed ones
 * that belong between existing pins (existing pins shift down to make
 * room). The client passes the returned cursor into the next batch.
 */
function inspiration_board_rest_import( WP_REST_Request $request ) {
	inspiration_board_quiet_publishing();

	$cursor  = (int) $request->get_param( 'cursor' );
	$cursor  = ( $cursor > 0 && $cursor <= time() ) ? $cursor : time();
	$results = array(
		'created' => 0,
		'skipped' => 0,
		'errors'  => array(),
	);

	foreach ( (array) $request->get_param( 'tweets' ) as $tweet ) {
		$tweet = inspiration_board_sanitize_tweet( $tweet );
		if ( ! $tweet ) {
			$results['errors'][] = __( 'Skipped an item that was not a valid tweet.', 'inspiration-board' );
			continue;
		}

		foreach ( $tweet['images'] as $image_url ) {
			$existing = inspiration_board_find_pin( $image_url );
			if ( $existing ) {
				$time = (int) get_post_time( 'U', true, $existing );
				if ( $time < $cursor ) {
					$cursor = $time;
				} else {
					// No room above this pin for what was just added: move it
					// down a second. Repeats down the list only as far as needed.
					$cursor--;
					inspiration_board_set_date( $existing, $cursor );
				}
				$results['skipped']++;
				continue;
			}

			$cursor--;
			$outcome = inspiration_board_import_image( $tweet, $image_url, $cursor );

			if ( is_wp_error( $outcome ) ) {
				$results['errors'][] = sprintf( '%s: %s', $tweet['url'], $outcome->get_error_message() );
			} else {
				$results['created']++;
			}
		}
	}

	$results['cursor'] = $cursor;

	return rest_ensure_response( $results );
}

/**
 * Belt and braces alongside the per-post meta: while importing, tell Jetpack
 * not to share or email anything in the Inspiration category.
 */
function inspiration_board_quiet_publishing() {
	add_filter( 'jetpack_publicize_should_publicize_published_post', '__return_false' );
	add_filter(
		'jetpack_subscriptions_exclude_these_categories',
		static function ( $categories ) {
			$categories = (array) $categories;
			$slug       = inspiration_board_category_slug();
			if ( $slug ) {
				$categories[] = $slug;
			}
			return $categories;
		}
	);
}

/**
 * Validates one tweet from the snippet's JSON.
 *
 * @return array|null Clean tweet data, or null if unusable.
 */
function inspiration_board_sanitize_tweet( $tweet ) {
	if ( ! is_array( $tweet ) ) {
		return null;
	}

	$url = isset( $tweet['url'] ) ? esc_url_raw( $tweet['url'] ) : '';
	if ( ! preg_match( '#^https://(x|twitter)\.com/[A-Za-z0-9_]+/status/\d+#', $url ) ) {
		return null;
	}

	$images = array();
	foreach ( (array) ( $tweet['images'] ?? array() ) as $image ) {
		$image = inspiration_board_normalize_image_url( (string) $image );
		if ( $image ) {
			$images[] = $image;
		}
	}
	if ( ! $images ) {
		return null;
	}

	return array(
		'url'    => $url,
		'handle' => sanitize_text_field( ltrim( (string) ( $tweet['handle'] ?? '' ), '@' ) ),
		'name'   => sanitize_text_field( (string) ( $tweet['name'] ?? '' ) ),
		'text'   => sanitize_textarea_field( (string) ( $tweet['text'] ?? '' ) ),
		'date'   => sanitize_text_field( (string) ( $tweet['date'] ?? '' ) ),
		'images' => array_values( array_unique( $images ) ),
	);
}

/**
 * Only accepts X's image CDN and asks it for the large rendition.
 *
 * @return string Normalized URL, or '' if the URL is not an X image.
 */
function inspiration_board_normalize_image_url( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || 'pbs.twimg.com' !== $parts['host'] || empty( $parts['path'] ) ) {
		return '';
	}
	if ( 0 !== strpos( $parts['path'], '/media/' ) ) {
		return '';
	}

	parse_str( $parts['query'] ?? '', $query );
	$path = $parts['path'];

	// Old-style URLs carry the extension in the path (/media/ID.jpg).
	if ( preg_match( '#^(/media/[^.]+)\.(jpg|jpeg|png|webp|gif)$#i', $path, $m ) ) {
		$path            = $m[1];
		$query['format'] = strtolower( $m[2] );
	}

	$format = in_array( $query['format'] ?? '', array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ? $query['format'] : 'jpg';

	return 'https://pbs.twimg.com' . $path . '?format=' . $format . '&name=large';
}

/**
 * Stable key for an image, so re-running the import never duplicates posts.
 */
function inspiration_board_image_key( $image_url ) {
	return (string) wp_parse_url( $image_url, PHP_URL_PATH );
}

/**
 * Re-dates a pin (only used to make room when inserting between pins).
 */
function inspiration_board_set_date( $post_id, $timestamp ) {
	$date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_date'     => get_date_from_gmt( $date_gmt ),
			'post_date_gmt' => $date_gmt,
			'edit_date'     => true,
		)
	);
}

/**
 * The pin already imported for an image, if any.
 *
 * @return int Post ID, or 0.
 */
function inspiration_board_find_pin( $image_url ) {
	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => INSPIRATION_BOARD_META_IMAGE,
			'meta_value'     => inspiration_board_image_key( $image_url ),
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return $existing ? (int) $existing[0] : 0;
}

/**
 * Creates one untitled Inspiration post for one image, dated $timestamp.
 *
 * @return int|WP_Error Post ID or an error.
 */
function inspiration_board_import_image( array $tweet, $image_url, $timestamp ) {
	$key      = inspiration_board_image_key( $image_url );
	$date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
	$date     = get_date_from_gmt( $date_gmt );

	$category_id = inspiration_board_ensure_category();

	// Start as a draft so the no-email / no-share flags are in place before
	// the post is ever published.
	$post_id = wp_insert_post(
		array(
			'post_title'    => '',
			'post_content'  => inspiration_board_build_content( $tweet ),
			'post_status'   => 'draft',
			'post_date'     => $date,
			'post_date_gmt' => $date_gmt,
			'post_type'     => 'post',
			'post_category' => $category_id ? array( $category_id ) : array(),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$attachment_id = inspiration_board_sideload( $image_url, $post_id, $tweet );
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_post( $post_id, true );
		return $attachment_id;
	}

	set_post_thumbnail( $post_id, $attachment_id );

	update_post_meta( $post_id, INSPIRATION_BOARD_META_IMAGE, $key );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_SOURCE, $tweet['url'] );
	if ( $tweet['date'] ) {
		update_post_meta( $post_id, '_inspiration_board_tweet_date', $tweet['date'] );
	}

	// Jetpack / WordPress.com: never email subscribers or auto-share pins.
	update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
	update_post_meta( $post_id, '_wpas_done_all', 1 );

	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_status'   => 'publish',
			'post_date'     => $date,
			'post_date_gmt' => $date_gmt,
			'edit_date'     => true,
		)
	);

	return $post_id;
}

/**
 * Downloads the image into the media library.
 *
 * X image URLs have no file extension, so the file is named here instead of
 * relying on media_sideload_image(), which rejects extensionless URLs.
 *
 * @return int|WP_Error Attachment ID.
 */
function inspiration_board_sideload( $image_url, $post_id, array $tweet ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $image_url, 30 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	parse_str( (string) wp_parse_url( $image_url, PHP_URL_QUERY ), $query );
	$ext  = 'jpeg' === ( $query['format'] ?? '' ) ? 'jpg' : ( $query['format'] ?? 'jpg' );
	$name = basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) ) . '.' . $ext;

	$alt = $tweet['text'] ? wp_trim_words( $tweet['text'], 20, '…' ) : '';

	$attachment_id = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		$post_id,
		$alt
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp );
		return $attachment_id;
	}

	if ( $alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	return $attachment_id;
}

/**
 * Block markup for the post: just the source link. The image is the post's
 * featured image, which the theme displays.
 */
function inspiration_board_build_content( array $tweet ) {
	$who = $tweet['name'] ? $tweet['name'] : '';
	if ( $tweet['handle'] ) {
		$who = trim( $who . ' (@' . $tweet['handle'] . ')' );
	}
	$label = $who ? sprintf( __( 'Source: %s on X', 'inspiration-board' ), $who ) : __( 'Source on X', 'inspiration-board' );

	$blocks  = "<!-- wp:paragraph {\"className\":\"inspiration-board-source\"} -->\n";
	$blocks .= '<p class="inspiration-board-source"><a href="' . esc_url( $tweet['url'] ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $label ) . ' ↗</a></p>' . "\n";
	$blocks .= '<!-- /wp:paragraph -->';

	return $blocks;
}
