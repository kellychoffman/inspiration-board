<?php
/**
 * Importing images from posts already on this site.
 *
 * Unlike tweets, the files are here already: a pin reuses the same
 * attachment rather than copying it, and links back to the post it came
 * from. One pin per image.
 */

defined( 'ABSPATH' ) || exit;

const INSPIRATION_BOARD_META_POST = '_inspiration_board_from_post';

add_action( 'rest_api_init', 'inspiration_board_register_local_route' );

function inspiration_board_register_local_route() {
	register_rest_route(
		'inspiration-board/v1',
		'/local-images',
		array(
			'methods'             => 'GET',
			'callback'            => 'inspiration_board_rest_local_images',
			'permission_callback' => static function () {
				return current_user_can( 'publish_posts' );
			},
			'args'                => array(
				'category' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		'inspiration-board/v1',
		'/import-local',
		array(
			'methods'             => 'POST',
			'callback'            => 'inspiration_board_rest_import_local',
			'permission_callback' => static function () {
				return current_user_can( 'publish_posts' ) && current_user_can( 'upload_files' );
			},
			'args'                => array(
				'category' => array(
					'type'     => 'string',
					'required' => true,
				),
				'images'   => array(
					'type'     => 'array',
					'required' => true,
				),
			),
		)
	);
}

/**
 * REST handler: the category's images that aren't on the board yet, so they
 * can be shown as a pick list.
 */
function inspiration_board_rest_local_images( WP_REST_Request $request ) {
	$images = array();

	foreach ( inspiration_board_local_pending( sanitize_title( (string) $request->get_param( 'category' ) ) ) as $image ) {
		$images[] = array(
			'attachment_id' => $image['attachment_id'],
			'post_id'       => $image['post_id'],
			'post_title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $image['post_id'] ) ) ),
			'date'          => get_the_date( 'j M Y', $image['post_id'] ),
			'thumb'         => wp_get_attachment_image_url( $image['attachment_id'], 'medium' ),
		);
	}

	return rest_ensure_response( $images );
}

/**
 * REST handler: turns the chosen images into pins.
 */
function inspiration_board_rest_import_local( WP_REST_Request $request ) {
	inspiration_board_quiet_publishing();

	$slug      = sanitize_title( (string) $request->get_param( 'category' ) );
	$chosen    = (array) $request->get_param( 'images' );
	$available = inspiration_board_local_pending( $slug );
	$created   = 0;

	// Only images the category actually offers, so a stray id can't pull in
	// something from elsewhere on the site.
	foreach ( $available as $image ) {
		if ( ! in_array( (int) $image['attachment_id'], array_map( 'intval', $chosen ), true ) ) {
			continue;
		}
		if ( inspiration_board_create_local_pin( $image ) ) {
			$created++;
		}
	}

	if ( $created ) {
		inspiration_board_resequence_by_date();
	}

	return rest_ensure_response( array( 'created' => $created ) );
}

/**
 * Images in a category that aren't on the board yet.
 *
 * @return array[] Each: array( attachment_id, post_id ).
 */
function inspiration_board_local_pending( $slug ) {
	$term = get_term_by( 'slug', $slug, 'category' );
	if ( ! $term || is_wp_error( $term ) ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'cat'            => (int) $term->term_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	$pending = array();
	foreach ( $posts as $post ) {
		// Skip the board's own pins, so a pin never breeds another pin.
		if ( get_post_meta( $post->ID, INSPIRATION_BOARD_META_IMAGE, true ) ) {
			continue;
		}
		foreach ( inspiration_board_images_in_post( $post ) as $attachment_id ) {
			$key = inspiration_board_local_key( $attachment_id );
			if ( inspiration_board_was_deleted( $key ) || inspiration_board_find_pin( $key ) ) {
				continue;
			}
			$pending[] = array(
				'attachment_id' => $attachment_id,
				'post_id'       => $post->ID,
			);
		}
	}

	return $pending;
}

/**
 * Attachment IDs for a post's images: its featured image first, then the
 * images in its content, in the order they appear.
 *
 * @return int[]
 */
function inspiration_board_images_in_post( WP_Post $post ) {
	$ids = array();

	$thumbnail = (int) get_post_thumbnail_id( $post );
	if ( $thumbnail ) {
		$ids[] = $thumbnail;
	}

	// Blocks and the classic editor both leave a wp-image-<id> class behind.
	if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
		foreach ( $matches[1] as $id ) {
			$ids[] = (int) $id;
		}
	}

	// Anything else: match the file back to its attachment.
	if ( preg_match_all( '/<img[^>]+src="([^"]+)"/i', $post->post_content, $matches ) ) {
		foreach ( $matches[1] as $src ) {
			$id = inspiration_board_attachment_from_src( $src );
			if ( $id ) {
				$ids[] = $id;
			}
		}
	}

	$ids = array_values( array_unique( array_filter( $ids ) ) );

	return array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				return wp_attachment_is_image( $id );
			}
		)
	);
}

/**
 * The attachment an <img> points at.
 *
 * Handles what Jetpack's image CDN does to a URL: a different host
 * (i0.wp.com/kelly.blog/...), a query string, and a resized filename such
 * as photo-1024x576.jpg.
 *
 * @return int Attachment ID, or 0.
 */
function inspiration_board_attachment_from_src( $src ) {
	$parts = wp_parse_url( $src );
	if ( empty( $parts['path'] ) ) {
		return 0;
	}

	$path = $parts['path'];
	$host = $parts['host'] ?? '';

	// Photon puts the real host at the front of the path.
	if ( preg_match( '#^i[0-9]\.wp\.com$#', $host ) ) {
		$segments = explode( '/', ltrim( $path, '/' ) );
		array_shift( $segments );
		$path = '/' . implode( '/', $segments );
	}

	$url = set_url_scheme( home_url( $path ) );
	$id  = attachment_url_to_postid( $url );
	if ( $id ) {
		return (int) $id;
	}

	// Fall back to the full-size name: photo-1024x576.jpg -> photo.jpg.
	$full = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url );
	return $full === $url ? 0 : (int) attachment_url_to_postid( $full );
}

/**
 * Dedupe key for an image already in the media library.
 */
function inspiration_board_local_key( $attachment_id ) {
	return 'local:' . (int) $attachment_id;
}

/**
 * Creates one pin for one of this site's images.
 *
 * @return int|false Post ID, or false.
 */
function inspiration_board_create_local_pin( array $image ) {
	$attachment_id = (int) $image['attachment_id'];
	$source        = get_post( (int) $image['post_id'] );
	if ( ! $source || ! wp_attachment_is_image( $attachment_id ) ) {
		return false;
	}

	$category_id = inspiration_board_ensure_category();
	$alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	$title       = get_the_title( $source );
	/* translators: %s: title of the post the image came from */
	$label = $title ? sprintf( __( 'Source: %s', 'inspiration-board' ), $title ) : __( 'Source', 'inspiration-board' );

	$content  = '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n";
	$content .= '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'large' ) ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/></figure>' . "\n";
	$content .= "<!-- /wp:image -->\n\n";
	$content .= "<!-- wp:paragraph {\"className\":\"inspiration-board-source\"} -->\n";
	$content .= '<p class="inspiration-board-source"><a href="' . esc_url( get_permalink( $source ) ) . '">' . esc_html( $label ) . '</a></p>' . "\n";
	$content .= '<!-- /wp:paragraph -->';

	// Drafted first so the no-email / no-share flags are set before publishing.
	$post_id = wp_insert_post(
		array(
			'post_title'    => '',
			'post_content'  => $content,
			'post_status'   => 'draft',
			'post_date'     => $source->post_date,
			'post_date_gmt' => $source->post_date_gmt,
			'post_type'     => 'post',
			'post_category' => $category_id ? array( $category_id ) : array(),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return false;
	}

	set_post_thumbnail( $post_id, $attachment_id );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_IMAGE, inspiration_board_local_key( $attachment_id ) );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_TYPE, 'photo' );
	update_post_meta( $post_id, INSPIRATION_BOARD_META_POST, (int) $source->ID );
	update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
	update_post_meta( $post_id, '_wpas_done_all', 1 );

	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_status'   => 'publish',
			'post_date'     => $source->post_date,
			'post_date_gmt' => $source->post_date_gmt,
			'edit_date'     => true,
		)
	);

	return (int) $post_id;
}

/**
 * Slots pins that have no place yet into the board by date: each one goes
 * above the first pin older than it. Existing pins keep their relative
 * order, which for tweets is the order they were bookmarked.
 */
function inspiration_board_resequence_by_date() {
	$placed = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => INSPIRATION_BOARD_META_ORDER,
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		)
	);

	$unplaced = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => INSPIRATION_BOARD_META_IMAGE,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => INSPIRATION_BOARD_META_ORDER,
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);
	if ( ! $unplaced ) {
		return;
	}

	$sequence = $placed;
	foreach ( $unplaced as $pin ) {
		$at = count( $sequence );
		foreach ( $sequence as $index => $existing ) {
			if ( strtotime( $existing->post_date_gmt ) < strtotime( $pin->post_date_gmt ) ) {
				$at = $index;
				break;
			}
		}
		array_splice( $sequence, $at, 0, array( $pin ) );
	}

	$order = time();
	foreach ( $sequence as $pin ) {
		update_post_meta( $pin->ID, INSPIRATION_BOARD_META_ORDER, $order-- );
	}
}
