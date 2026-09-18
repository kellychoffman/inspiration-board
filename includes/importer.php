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
			),
		)
	);
}

/**
 * REST handler: imports a small batch of tweets and reports what happened.
 */
function inspiration_board_rest_import( WP_REST_Request $request ) {
	inspiration_board_quiet_publishing();

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

		// Last image first, so the board (newest first) shows a tweet's
		// images in their original 1, 2, 3 order.
		$total = count( $tweet['images'] );
		foreach ( array_reverse( $tweet['images'], true ) as $index => $image_url ) {
			$outcome = inspiration_board_import_image( $tweet, $image_url, $index, $total );

			if ( is_wp_error( $outcome ) ) {
				$results['errors'][] = sprintf( '%s: %s', $tweet['url'], $outcome->get_error_message() );
			} elseif ( 'skipped' === $outcome ) {
				$results['skipped']++;
			} else {
				$results['created']++;
			}
		}
	}

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
 * Creates one Inspiration post for one image.
 *
 * @return int|string|WP_Error Post ID, 'skipped' if it already exists, or an error.
 */
function inspiration_board_import_image( array $tweet, $image_url, $index, $total ) {
	$key = inspiration_board_image_key( $image_url );

	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => INSPIRATION_BOARD_META_IMAGE,
			'meta_value'     => $key,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	if ( $existing ) {
		return 'skipped';
	}

	$category_id = inspiration_board_ensure_category();

	// Start as a draft so the no-email / no-share flags are in place before
	// the post is ever published.
	$post_id = wp_insert_post(
		array(
			'post_title'    => inspiration_board_build_title( $tweet, $index, $total ),
			'post_status'   => 'draft',
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
			'ID'           => $post_id,
			'post_status'  => 'publish',
			'post_content' => inspiration_board_build_content( $tweet ),
		)
	);

	return $post_id;
}

function inspiration_board_build_title( array $tweet, $index, $total ) {
	$who   = $tweet['handle'] ? '@' . $tweet['handle'] : __( 'X', 'inspiration-board' );
	$text  = trim( preg_replace( '#https?://\S+#', '', $tweet['text'] ) );
	$title = $text ? wp_trim_words( $text, 10, '…' ) : sprintf( __( 'Image from %s', 'inspiration-board' ), $who );

	if ( $total > 1 ) {
		$title .= sprintf( ' (%d/%d)', $index + 1, $total );
	}

	return $title;
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
