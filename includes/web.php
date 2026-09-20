<?php
/**
 * Pinning images from any page on the web, via the browser extension.
 *
 * The extension sends one image at a time, so each one becomes its own pin,
 * dated the moment it was pinned. It sends the file itself when it can
 * (fetched in the browser, which gets past sites that refuse the server) and
 * otherwise just the address, for the server to download.
 */

defined( 'ABSPATH' ) || exit;

const INSPIRATION_BOARD_META_PAGE = '_inspiration_board_page_title';

add_action( 'rest_api_init', 'inspiration_board_register_web_routes' );
add_action( 'wp_ajax_inspiration_board_session', 'inspiration_board_ajax_session' );
add_action( 'wp_ajax_nopriv_inspiration_board_session', 'inspiration_board_ajax_session_denied' );

/**
 * Where the board itself lives, for linking to after a pin.
 *
 * @return string Permalink, or '' if no page holds the board yet.
 */
function inspiration_board_board_link() {
	if ( ! function_exists( 'inspiration_board_find_page' ) ) {
		return '';
	}

	$page = inspiration_board_find_page();

	return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
}

/**
 * Who may add pins: the same bar as the other importers.
 */
function inspiration_board_can_pin() {
	return current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
}

function inspiration_board_register_web_routes() {
	register_rest_route(
		'inspiration-board/v1',
		'/pin',
		array(
			'methods'             => 'POST',
			'callback'            => 'inspiration_board_rest_pin',
			'permission_callback' => 'inspiration_board_can_pin',
			'args'                => array(
				'source' => array(
					'type'     => 'string',
					'required' => true,
				),
				'url'    => array(
					'type'     => 'string',
					'required' => false,
				),
				'title'  => array(
					'type'     => 'string',
					'required' => false,
				),
				'alt'    => array(
					'type'     => 'string',
					'required' => false,
				),
			),
		)
	);

	register_rest_route(
		'inspiration-board/v1',
		'/pinned',
		array(
			// A read, but the addresses are too long and too many for a query
			// string, so they travel in the body.
			'methods'             => 'POST',
			'callback'            => 'inspiration_board_rest_pinned',
			'permission_callback' => 'inspiration_board_can_pin',
			'args'                => array(
				'urls' => array(
					'type'     => 'array',
					'required' => true,
				),
			),
		)
	);
}

/**
 * The extension can't read a WordPress login cookie, but the browser sends
 * one: this hands back a REST nonce for whoever is signed in, which is how
 * the extension posts as you without storing a password.
 *
 * admin-ajax is used rather than a REST route because REST ignores the login
 * cookie until a nonce is already in hand. Ordinary web pages can't read this
 * response (no CORS headers), so the nonce stays between you and the
 * extension.
 */
function inspiration_board_ajax_session() {
	if ( ! inspiration_board_can_pin() ) {
		wp_send_json_error(
			array( 'message' => __( 'That account is not allowed to add pins.', 'inspiration-board' ) ),
			403
		);
	}

	$user = wp_get_current_user();

	wp_send_json_success(
		array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'user'    => $user->user_login,
			'name'    => $user->display_name,
			'rest'    => esc_url_raw( rest_url( 'inspiration-board/v1/' ) ),
			'board'   => inspiration_board_board_link(),
			'version' => INSPIRATION_BOARD_VERSION,
		)
	);
}

function inspiration_board_ajax_session_denied() {
	wp_send_json_error(
		array( 'message' => __( 'Not signed in.', 'inspiration-board' ) ),
		401
	);
}

/**
 * REST handler: which of these image addresses are already on the board.
 *
 * Lets the extension grey out what you've pinned before, so you don't have to
 * remember.
 *
 * @return array Map of address to 'pinned' or 'deleted'.
 */
function inspiration_board_rest_pinned( WP_REST_Request $request ) {
	$known = array();

	foreach ( (array) $request->get_param( 'urls' ) as $url ) {
		$url = (string) $url;
		$key = inspiration_board_web_key( $url );
		if ( ! $key ) {
			continue;
		}
		if ( inspiration_board_was_deleted( $key ) ) {
			$known[ $url ] = 'deleted';
		} elseif ( inspiration_board_find_pin( $key ) ) {
			$known[ $url ] = 'pinned';
		}
	}

	return rest_ensure_response( $known );
}

/**
 * Stable key for an image found on the web, so pinning the same picture
 * twice never makes two pins. Ignores the fragment and the scheme, since
 * those don't change which file it is.
 *
 * @return string Key, or '' if the address isn't a web address.
 */
function inspiration_board_web_key( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
		return '';
	}

	$path  = $parts['path'] ?? '/';
	$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

	return 'web:' . md5( strtolower( $parts['host'] ) . $path . $query );
}

/**
 * REST handler: turns one image from a web page into one pin.
 *
 * @return WP_REST_Response|WP_Error
 */
function inspiration_board_rest_pin( WP_REST_Request $request ) {
	inspiration_board_quiet_publishing();

	$source = esc_url_raw( (string) $request->get_param( 'source' ) );
	if ( ! $source || ! in_array( wp_parse_url( $source, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
		return new WP_Error(
			'inspiration_board_bad_source',
			__( 'The page address is missing or is not a web address.', 'inspiration-board' ),
			array( 'status' => 400 )
		);
	}

	$files     = $request->get_file_params();
	$upload    = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
	$image_url = esc_url_raw( (string) $request->get_param( 'url' ) );

	// The key normally comes from the image's address. An image with no
	// address of its own (drawn into the page, or embedded in it) is keyed by
	// what it contains instead.
	$key = $image_url ? inspiration_board_web_key( $image_url ) : '';
	if ( ! $key && $upload && ! empty( $upload['tmp_name'] ) && is_readable( $upload['tmp_name'] ) ) {
		$key = 'web:' . md5_file( $upload['tmp_name'] );
	}
	if ( ! $key ) {
		return new WP_Error(
			'inspiration_board_bad_image',
			__( 'That image had no usable address and no file came with it.', 'inspiration-board' ),
			array( 'status' => 400 )
		);
	}

	if ( inspiration_board_was_deleted( $key ) ) {
		return rest_ensure_response(
			array(
				'status'  => 'deleted',
				'message' => __( 'You removed this one from the board before, so it was left off.', 'inspiration-board' ),
			)
		);
	}

	$existing = inspiration_board_find_pin( $key );
	if ( $existing ) {
		return rest_ensure_response(
			array(
				'status'  => 'pinned',
				'post_id' => (int) $existing,
				'link'    => get_permalink( $existing ),
				'board'   => inspiration_board_board_link(),
				'message' => __( 'Already on the board.', 'inspiration-board' ),
			)
		);
	}

	$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
	$alt   = sanitize_text_field( (string) $request->get_param( 'alt' ) );
	$alt   = $alt ? $alt : $title;

	// The date is the moment of pinning, which is what puts new finds at the
	// top of the board.
	$date_gmt    = gmdate( 'Y-m-d H:i:s' );
	$date        = get_date_from_gmt( $date_gmt );
	$category_id = inspiration_board_ensure_category();

	// Drafted first, so the no-email / no-share flags are in place before the
	// pin is ever published.
	$post_id = wp_insert_post(
		array(
			'post_title'    => '',
			// Replaced below, once the image is in the library. A post with
			// nothing in it at all is refused, so the source link goes in now.
			'post_content'  => inspiration_board_build_web_content( 0, $source, $title, $alt ),
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

	$attachment_id = $upload
		? inspiration_board_attach_upload( $post_id, $alt )
		: inspiration_board_attach_remote( $image_url, $post_id, $alt );

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_post( $post_id, true );
		return new WP_Error(
			'inspiration_board_image_failed',
			$attachment_id->get_error_message(),
			array( 'status' => 422 )
		);
	}

	set_post_thumbnail( $post_id, $attachment_id );

	update_post_meta( $post_id, INSPIRATION_BOARD_META_IMAGE, $key );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_SOURCE, $source );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_TYPE, 'photo' );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_ORDER, time() );
	if ( $title ) {
		update_post_meta( $post_id, INSPIRATION_BOARD_META_PAGE, $title );
	}

	// Jetpack / WordPress.com: never email subscribers or auto-share pins.
	update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
	update_post_meta( $post_id, '_wpas_done_all', 1 );

	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_content'  => inspiration_board_build_web_content( $attachment_id, $source, $title, $alt ),
			'post_status'   => 'publish',
			'post_date'     => $date,
			'post_date_gmt' => $date_gmt,
			'edit_date'     => true,
		)
	);

	return rest_ensure_response(
		array(
			'status'  => 'created',
			'post_id' => (int) $post_id,
			'link'    => get_permalink( $post_id ),
			'board'   => inspiration_board_board_link(),
			'thumb'   => wp_get_attachment_image_url( $attachment_id, 'medium' ),
		)
	);
}

/**
 * Takes the image file the extension uploaded into the media library.
 *
 * WordPress checks the file really is the kind of image its name claims, so a
 * page can't smuggle something else through.
 *
 * @return int|WP_Error Attachment ID.
 */
function inspiration_board_attach_upload( $post_id, $alt ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_handle_upload( 'file', $post_id, array(), array( 'test_form' => false ) );
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		wp_delete_attachment( $attachment_id, true );
		return new WP_Error( 'inspiration_board_not_an_image', __( 'That file is not an image.', 'inspiration-board' ) );
	}

	if ( $alt ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	return (int) $attachment_id;
}

/**
 * Downloads an image the extension couldn't fetch itself.
 *
 * The file is identified by what's inside it rather than by its name, because
 * image addresses on the web often have no file extension at all.
 *
 * @return int|WP_Error Attachment ID.
 */
function inspiration_board_attach_remote( $image_url, $post_id, $alt ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	if ( ! $image_url ) {
		return new WP_Error( 'inspiration_board_no_image', __( 'No image address to download.', 'inspiration-board' ) );
	}

	// download_url() fetches through wp_safe_remote_get(), which refuses
	// addresses inside the server's own network.
	$tmp = download_url( $image_url, 30 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$size = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$ext  = $size ? inspiration_board_extension_for( (string) ( $size['mime'] ?? '' ) ) : '';
	if ( ! $ext ) {
		wp_delete_file( $tmp );
		return new WP_Error( 'inspiration_board_not_an_image', __( 'That address did not give back an image.', 'inspiration-board' ) );
	}

	$name = sanitize_file_name( pathinfo( (string) wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
	$name = ( $name ? $name : 'inspiration' ) . '.' . $ext;

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

	return (int) $attachment_id;
}

/**
 * File extension for an image type WordPress will accept, or '' for anything
 * else. SVG is deliberately left out: it can carry scripts.
 */
function inspiration_board_extension_for( $mime ) {
	$known = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	return isset( $known[ $mime ] ) ? $known[ $mime ] : '';
}

/**
 * Block markup for a web pin: the image, then a link back to the page it
 * came from.
 */
function inspiration_board_build_web_content( $attachment_id, $source, $title, $alt ) {
	$blocks = '';

	if ( $attachment_id ) {
		$blocks .= '<!-- wp:image {"id":' . (int) $attachment_id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n";
		$blocks .= '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $attachment_id . '"/></figure>' . "\n";
		$blocks .= "<!-- /wp:image -->\n\n";
	}

	$where = $title ? $title : (string) wp_parse_url( $source, PHP_URL_HOST );
	/* translators: %s: title of the page the image came from */
	$label = $where ? sprintf( __( 'Source: %s', 'inspiration-board' ), $where ) : __( 'Source', 'inspiration-board' );

	$blocks .= "<!-- wp:paragraph {\"className\":\"inspiration-board-source\"} -->\n";
	$blocks .= '<p class="inspiration-board-source"><a href="' . esc_url( $source ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $label ) . ' ↗</a></p>' . "\n";
	$blocks .= '<!-- /wp:paragraph -->';

	return $blocks;
}
