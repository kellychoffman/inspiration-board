<?php
/**
 * The plugin's own "Inspiration" category every imported post goes into.
 */

defined( 'ABSPATH' ) || exit;

const INSPIRATION_BOARD_OPTION_CATEGORY = 'inspiration_board_category_id';

/**
 * Returns the Inspiration category ID, creating the category if needed.
 *
 * The ID is remembered in an option, so renaming the category or changing its
 * slug later is fine. A fresh install always creates its own category rather
 * than adopting an existing one that happens to share a name or slug.
 *
 * @return int Category term ID, or 0 on failure.
 */
function inspiration_board_ensure_category() {
	$saved = (int) get_option( INSPIRATION_BOARD_OPTION_CATEGORY );
	if ( $saved && term_exists( $saved, 'category' ) ) {
		return $saved;
	}

	$slug = apply_filters( 'inspiration_board_category_slug', 'inspiration-board' );
	$name = __( 'Inspiration', 'inspiration-board' );

	// Only reuse a category this plugin created on an earlier install.
	$term = get_term_by( 'slug', $slug, 'category' );
	if ( $term && 'inspiration-board' === get_term_meta( $term->term_id, 'inspiration_board_owner', true ) ) {
		update_option( INSPIRATION_BOARD_OPTION_CATEGORY, (int) $term->term_id );
		return (int) $term->term_id;
	}
	if ( $term ) {
		$slug .= '-' . wp_generate_password( 4, false, false );
	}

	$created = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
	if ( is_wp_error( $created ) && 'term_exists' === $created->get_error_code() ) {
		// Another category already uses the name "Inspiration".
		$created = wp_insert_term( __( 'Inspiration Board', 'inspiration-board' ), 'category', array( 'slug' => $slug ) );
	}
	if ( is_wp_error( $created ) ) {
		return 0;
	}

	$term_id = (int) $created['term_id'];
	update_term_meta( $term_id, 'inspiration_board_owner', 'inspiration-board' );
	update_option( INSPIRATION_BOARD_OPTION_CATEGORY, $term_id );

	return $term_id;
}

/**
 * Slug of the plugin's category, for Jetpack's category-based filters.
 */
function inspiration_board_category_slug() {
	$term = get_term( inspiration_board_ensure_category(), 'category' );
	return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
}

add_action( 'pre_get_posts', 'inspiration_board_keep_out_of_blog' );

/**
 * Pins live on the board, not in the blog: keep them off the posts page and
 * out of the main RSS feed. The category's own archive and feed still work.
 *
 * Only posts the importer created (marked with the image meta key) are
 * excluded, so no other post on the site is ever affected.
 */
function inspiration_board_keep_out_of_blog( $query ) {
	if ( is_admin() || ! $query->is_main_query() || $query->is_category() ) {
		return;
	}
	if ( ! $query->is_home() && ! $query->is_feed() ) {
		return;
	}
	if ( ! apply_filters( 'inspiration_board_hide_from_blog', true ) ) {
		return;
	}

	$meta_query   = (array) $query->get( 'meta_query' );
	$meta_query[] = array(
		'key'     => INSPIRATION_BOARD_META_IMAGE,
		'compare' => 'NOT EXISTS',
	);
	$query->set( 'meta_query', $meta_query );
}
