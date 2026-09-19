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
const INSPIRATION_BOARD_META_ORDER  = '_inspiration_board_order';
const INSPIRATION_BOARD_META_DATE   = '_inspiration_board_tweet_date';
const INSPIRATION_BOARD_META_TYPE   = '_inspiration_board_type';
const INSPIRATION_BOARD_META_VIDEO  = '_inspiration_board_video';

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
 * Each pin's post date is its tweet's date; its place on the board is a
 * separate sort value (META_ORDER). The cursor walks down the list: a pin
 * already on the board moves the cursor to its sort value, and each new pin
 * gets a sort value one below the cursor. That slots new pins into the right
 * place whether they are newer than everything so far, older bookmarks being
 * filled in later, or missed ones that belong between existing pins
 * (existing pins shift down to make room). The client passes the returned
 * cursor into the next batch.
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

		foreach ( $tweet['media'] as $item ) {
			$existing = inspiration_board_find_pin( inspiration_board_media_key( $item ) );
			if ( $existing ) {
				$order = (int) get_post_meta( $existing, INSPIRATION_BOARD_META_ORDER, true );
				if ( $order && $order < $cursor ) {
					$cursor = $order;
				} else {
					// No room above this pin for what was just added: move it
					// down one. Repeats down the list only as far as needed.
					$cursor--;
					update_post_meta( $existing, INSPIRATION_BOARD_META_ORDER, $cursor );
				}
				$results['skipped']++;
				continue;
			}

			$cursor--;
			$outcome = inspiration_board_import_media( $tweet, $item, $cursor );

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

	$media = array();
	foreach ( (array) ( $tweet['media'] ?? array() ) as $item ) {
		$clean = inspiration_board_sanitize_media( $item );
		if ( $clean ) {
			$media[] = $clean;
		}
	}
	// Files from earlier versions list photo URLs under "images".
	foreach ( (array) ( $tweet['images'] ?? array() ) as $image ) {
		$clean = inspiration_board_sanitize_media( array( 'type' => 'photo', 'url' => $image ) );
		if ( $clean ) {
			$media[] = $clean;
		}
	}

	$seen  = array();
	$media = array_values(
		array_filter(
			$media,
			static function ( $item ) use ( &$seen ) {
				$key = inspiration_board_media_key( $item );
				if ( isset( $seen[ $key ] ) ) {
					return false;
				}
				$seen[ $key ] = true;
				return true;
			}
		)
	);
	if ( ! $media ) {
		return null;
	}

	return array(
		'url'    => $url,
		'handle' => sanitize_text_field( ltrim( (string) ( $tweet['handle'] ?? '' ), '@' ) ),
		'name'   => sanitize_text_field( (string) ( $tweet['name'] ?? '' ) ),
		'text'   => sanitize_textarea_field( (string) ( $tweet['text'] ?? '' ) ),
		'date'   => sanitize_text_field( (string) ( $tweet['date'] ?? '' ) ),
		'media'  => $media,
	);
}

/**
 * Validates one media item: a photo, a GIF (silent looping mp4 plus its
 * still frame), or a video (still frame only, since X streams the video).
 *
 * @return array|null array( type, url, poster ), or null if unusable.
 */
function inspiration_board_sanitize_media( $item ) {
	if ( is_string( $item ) ) {
		$item = array( 'type' => 'photo', 'url' => $item );
	}
	if ( ! is_array( $item ) ) {
		return null;
	}

	$type   = in_array( $item['type'] ?? '', array( 'photo', 'gif', 'video' ), true ) ? $item['type'] : 'photo';
	$url    = inspiration_board_normalize_image_url( (string) ( $item['url'] ?? '' ) );
	$poster = inspiration_board_normalize_image_url( (string) ( $item['poster'] ?? '' ) );
	$mp4    = inspiration_board_normalize_video_url( (string) ( $item['url'] ?? '' ) );

	if ( 'photo' === $type ) {
		return $url ? array( 'type' => 'photo', 'url' => $url, 'poster' => '' ) : null;
	}
	if ( ! $poster ) {
		return null;
	}
	// A GIF without a usable mp4 is imported as a still, like a video.
	if ( 'gif' === $type && $mp4 ) {
		return array( 'type' => 'gif', 'url' => $mp4, 'poster' => $poster );
	}
	return array( 'type' => 'video', 'url' => '', 'poster' => $poster );
}

/**
 * Stable key for a media item, so re-running the import never duplicates.
 */
function inspiration_board_media_key( array $item ) {
	$source = 'photo' === $item['type'] ? $item['url'] : $item['poster'];
	return (string) wp_parse_url( $source, PHP_URL_PATH );
}

/**
 * Only accepts X's video CDN, and only plain mp4 files (its GIFs).
 *
 * @return string Normalized URL, or '' if not one.
 */
function inspiration_board_normalize_video_url( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || 'video.twimg.com' !== $parts['host'] || empty( $parts['path'] ) ) {
		return '';
	}
	if ( ! preg_match( '#^/tweet_video/[A-Za-z0-9_-]+\.mp4$#', $parts['path'] ) ) {
		return '';
	}
	return 'https://video.twimg.com' . $parts['path'];
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
	// Photos live under /media/; GIF and video still frames under *_thumb/.
	if ( ! preg_match( '#^/(media|tweet_video_thumb|amplify_video_thumb|ext_tw_video_thumb)/#', $parts['path'] ) ) {
		return '';
	}

	parse_str( $parts['query'] ?? '', $query );
	$path = $parts['path'];

	// Old-style URLs carry the extension in the path (/media/ID.jpg).
	if ( preg_match( '#^(/[a-z_]+/[^.]+)\.(jpg|jpeg|png|webp|gif)$#i', $path, $m ) ) {
		$path            = $m[1];
		$query['format'] = strtolower( $m[2] );
	}

	$format = in_array( $query['format'] ?? '', array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ? $query['format'] : 'jpg';

	return 'https://pbs.twimg.com' . $path . '?format=' . $format . '&name=large';
}

/**
 * The pin already imported for a media key, if any.
 *
 * @return int Post ID, or 0.
 */
function inspiration_board_find_pin( $key ) {
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
	return $existing ? (int) $existing[0] : 0;
}

/**
 * Creates one untitled Inspiration post for one photo, GIF or video, dated
 * like its tweet and placed on the board at sort value $order.
 *
 * @return int|WP_Error Post ID or an error.
 */
function inspiration_board_import_media( array $tweet, array $item, $order ) {
	$key       = inspiration_board_media_key( $item );
	$still     = 'photo' === $item['type'] ? $item['url'] : $item['poster'];
	$tweeted   = $tweet['date'] ? strtotime( $tweet['date'] ) : false;
	$date_gmt  = gmdate( 'Y-m-d H:i:s', ( $tweeted && $tweeted <= time() ) ? $tweeted : time() );
	$date      = get_date_from_gmt( $date_gmt );

	$category_id = inspiration_board_ensure_category();

	// Start as a draft so the no-email / no-share flags are in place before
	// the post is ever published.
	$post_id = wp_insert_post(
		array(
			'post_title'    => '',
			'post_content'  => inspiration_board_build_content( $tweet ), // Replaced below, once the media is in the library.
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

	// The still frame: the board tile, the featured image, and what social
	// networks show when a pin is shared.
	$attachment_id = inspiration_board_sideload( $still, $post_id, $tweet );
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_post( $post_id, true );
		return $attachment_id;
	}

	set_post_thumbnail( $post_id, $attachment_id );

	// A GIF also brings its silent looping mp4. If that fails, the pin stays
	// as the still frame rather than failing the whole import.
	$video_id = 0;
	if ( 'gif' === $item['type'] ) {
		$video_id = inspiration_board_sideload( $item['url'], $post_id, $tweet );
		if ( is_wp_error( $video_id ) ) {
			$video_id = 0;
		}
	}

	$type = $video_id ? 'gif' : ( 'photo' === $item['type'] ? 'photo' : 'video' );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_TYPE, $type );
	if ( $video_id ) {
		update_post_meta( $post_id, INSPIRATION_BOARD_META_VIDEO, (int) $video_id );
	}

	update_post_meta( $post_id, INSPIRATION_BOARD_META_IMAGE, $key );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_SOURCE, $tweet['url'] );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_ORDER, (int) $order );
	if ( $tweet['date'] ) {
		update_post_meta( $post_id, INSPIRATION_BOARD_META_DATE, $tweet['date'] );
	}

	// Jetpack / WordPress.com: never email subscribers or auto-share pins.
	update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
	update_post_meta( $post_id, '_wpas_done_all', 1 );

	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_content'  => inspiration_board_build_content( $tweet, $attachment_id, $video_id, $type ),
			'post_status'   => 'publish',
			'post_date'     => $date,
			'post_date_gmt' => $date_gmt,
			'edit_date'     => true,
		)
	);

	return $post_id;
}

/**
 * Downloads an image or mp4 into the media library.
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

	$path = (string) wp_parse_url( $image_url, PHP_URL_PATH );
	if ( '.mp4' === substr( $path, -4 ) ) {
		$name = basename( $path );
	} else {
		parse_str( (string) wp_parse_url( $image_url, PHP_URL_QUERY ), $query );
		$ext  = 'jpeg' === ( $query['format'] ?? '' ) ? 'jpg' : ( $query['format'] ?? 'jpg' );
		$name = basename( $path ) . '.' . $ext;
	}

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
 * Block markup for the post: the media itself, then the source link.
 * GIFs loop silently; videos show their still frame, since X streams those.
 */
function inspiration_board_build_content( array $tweet, $attachment_id = 0, $video_id = 0, $type = 'photo' ) {
	$blocks = '';

	if ( $video_id ) {
		$blocks .= '<!-- wp:video {"id":' . (int) $video_id . ',"className":"inspiration-board-gif"} -->' . "\n";
		$blocks .= '<figure class="wp-block-video inspiration-board-gif"><video autoplay loop muted playsinline';
		$blocks .= $attachment_id ? ' poster="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ) . '"' : '';
		$blocks .= ' src="' . esc_url( wp_get_attachment_url( $video_id ) ) . '"></video></figure>' . "\n";
		$blocks .= "<!-- /wp:video -->\n\n";
	} elseif ( $attachment_id ) {
		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$blocks .= '<!-- wp:image {"id":' . (int) $attachment_id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n";
		$blocks .= '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $attachment_id . '"/></figure>' . "\n";
		$blocks .= "<!-- /wp:image -->\n\n";
	}

	$who = $tweet['name'] ? $tweet['name'] : '';
	if ( $tweet['handle'] ) {
		$who = trim( $who . ' (@' . $tweet['handle'] . ')' );
	}
	if ( 'video' === $type ) {
		/* translators: %s: name and handle */
		$label = $who ? sprintf( __( 'Watch: %s on X', 'inspiration-board' ), $who ) : __( 'Watch on X', 'inspiration-board' );
	} else {
		/* translators: %s: name and handle */
		$label = $who ? sprintf( __( 'Source: %s on X', 'inspiration-board' ), $who ) : __( 'Source on X', 'inspiration-board' );
	}

	$blocks .= "<!-- wp:paragraph {\"className\":\"inspiration-board-source\"} -->\n";
	$blocks .= '<p class="inspiration-board-source"><a href="' . esc_url( $tweet['url'] ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $label ) . ' ↗</a></p>' . "\n";
	$blocks .= '<!-- /wp:paragraph -->';

	return $blocks;
}
