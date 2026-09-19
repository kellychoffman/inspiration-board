<?php
/**
 * The [inspiration_board] gallery, plus small touches on single
 * Inspiration posts.
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'inspiration_board', 'inspiration_board_shortcode' );
add_action( 'wp_enqueue_scripts', 'inspiration_board_register_styles' );
add_filter( 'body_class', 'inspiration_board_body_class' );
add_filter( 'post_thumbnail_html', 'inspiration_board_hide_duplicate_thumbnail', 10, 2 );

/**
 * A pin's media is in its content, so skip the theme's featured image on the
 * pin's own page. The featured image still serves the board and sharing
 * previews, and featured images elsewhere are left alone.
 */
function inspiration_board_hide_duplicate_thumbnail( $html, $post_id ) {
	if ( is_singular() && (int) $post_id === get_queried_object_id() && inspiration_board_is_pin( $post_id ) ) {
		return '';
	}
	return $html;
}
add_filter( 'document_title_parts', 'inspiration_board_document_title' );
add_action( 'init', 'inspiration_board_register_template' );

const INSPIRATION_BOARD_TEMPLATE = 'inspiration-board';

/**
 * A full-width page template without the theme's sidebar or header: just the
 * page content and the theme's footer. Needs WordPress 6.7+ (block themes).
 */
function inspiration_board_register_template() {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}
	register_block_template(
		'inspiration-board//' . INSPIRATION_BOARD_TEMPLATE,
		array(
			'title'       => __( 'Inspiration Board (no sidebar)', 'inspiration-board' ),
			'description' => __( 'Full width, no sidebar or header. Just the page content and the footer.', 'inspiration-board' ),
			'content'     => file_get_contents( INSPIRATION_BOARD_DIR . 'templates/inspiration-board.html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'post_types'  => array( 'page' ),
		)
	);
}

function inspiration_board_register_styles() {
	wp_register_style(
		'inspiration-board',
		INSPIRATION_BOARD_URL . 'assets/board.css',
		array(),
		INSPIRATION_BOARD_VERSION
	);
	wp_register_script(
		'inspiration-board',
		INSPIRATION_BOARD_URL . 'assets/board.js',
		array(),
		INSPIRATION_BOARD_VERSION,
		true
	);

	// Enqueue early on the board page so the theme's title is hidden before paint.
	if ( inspiration_board_is_board_page() || ( is_singular( 'post' ) && inspiration_board_is_pin( get_queried_object_id() ) ) ) {
		wp_enqueue_style( 'inspiration-board' );
	}
}

/**
 * Whether the current request is a page that shows the board.
 */
function inspiration_board_is_board_page() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_queried_object();
	return $post instanceof WP_Post && has_shortcode( $post->post_content, 'inspiration_board' );
}

/**
 * The board draws its own full-width heading, so the page gets a class that
 * lets board.css hide the theme's title.
 */
function inspiration_board_body_class( $classes ) {
	if ( inspiration_board_is_board_page() ) {
		$classes[] = 'inspiration-board-page';
	}
	return $classes;
}

function inspiration_board_is_pin( $post_id ) {
	return (bool) get_post_meta( $post_id, INSPIRATION_BOARD_META_IMAGE, true );
}

/**
 * Label for a pin: its title if it has one, otherwise the source's @handle.
 */
function inspiration_board_caption( $post_id ) {
	$title = get_the_title( $post_id );
	if ( '' !== trim( $title ) ) {
		return $title;
	}
	$source = (string) get_post_meta( $post_id, INSPIRATION_BOARD_META_SOURCE, true );
	if ( preg_match( '#^https://(?:x|twitter)\.com/([A-Za-z0-9_]+)/status/#', $source, $m ) ) {
		return '@' . $m[1];
	}
	return '';
}

/**
 * Untitled pins would otherwise get an empty browser tab title.
 */
function inspiration_board_document_title( $parts ) {
	if ( is_singular( 'post' ) && inspiration_board_is_pin( get_queried_object_id() ) && '' === trim( (string) ( $parts['title'] ?? '' ) ) ) {
		$term           = get_term( inspiration_board_ensure_category(), 'category' );
		$parts['title'] = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( 'Inspiration', 'inspiration-board' );
	}
	return $parts;
}

/**
 * Whether the current page uses the plugin's no-sidebar template.
 */
function inspiration_board_uses_template() {
	return is_singular() && INSPIRATION_BOARD_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

function inspiration_board_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'columns'  => 4,
			'per_page' => 60,
		),
		$atts,
		'inspiration_board'
	);

	$category_id = inspiration_board_ensure_category();
	if ( ! $category_id ) {
		return '';
	}

	wp_enqueue_style( 'inspiration-board' );
	wp_enqueue_script( 'inspiration-board' );

	$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

	$args = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'cat'                 => $category_id,
		'posts_per_page'      => max( 1, (int) $atts['per_page'] ),
		'paged'               => $paged,
		// Bookmark order (see importer), not post date: post dates are
		// the tweets' own dates.
		'meta_query'          => array(
			'relation' => 'AND',
			'order'    => array(
				'key'  => INSPIRATION_BOARD_META_ORDER,
				'type' => 'NUMERIC',
			),
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'EXISTS',
			),
		),
		'orderby'             => array(
			'order' => 'DESC',
			'ID'    => 'DESC',
		),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => false,
	);

	// Until the one-time upgrade has run, pins have no sort value yet: fall
	// back to the old date order so the board never shows up empty.
	if ( (int) get_option( INSPIRATION_BOARD_OPTION_SCHEMA ) < INSPIRATION_BOARD_SCHEMA ) {
		unset( $args['meta_query']['order'] );
		$args['orderby'] = array(
			'date' => 'DESC',
			'ID'   => 'DESC',
		);
	}

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		return '<p class="inspiration-board-empty">' . esc_html__( 'Nothing on the board yet.', 'inspiration-board' ) . '</p>';
	}

	$columns = min( 8, max( 1, (int) $atts['columns'] ) );

	$heading = inspiration_board_is_board_page() ? get_the_title( get_queried_object_id() ) : __( 'Inspiration', 'inspiration-board' );

	ob_start();
	?>
	<div class="inspiration-board-wrap">
	<header class="inspiration-board__header">
		<?php if ( inspiration_board_uses_template() ) : // The template has no site header, so link home. ?>
			<a class="inspiration-board__home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		<?php endif; ?>
		<h1 class="inspiration-board__title"><?php echo esc_html( $heading ); ?></h1>
	</header>
	<div class="inspiration-board" style="--ib-columns: <?php echo (int) $columns; ?>">
		<?php
		while ( $query->have_posts() ) :
			$query->the_post();
			?>
			<?php
			// No visible caption: the image's alt text names the link. Pins
			// without alt text fall back to the source's @handle.
			$has_alt = '' !== trim( (string) get_post_meta( get_post_thumbnail_id(), '_wp_attachment_image_alt', true ) );
			?>
			<a class="inspiration-board__pin" href="<?php the_permalink(); ?>"<?php echo $has_alt ? '' : ' aria-label="' . esc_attr( inspiration_board_caption( get_the_ID() ) ) . '"'; ?>>
				<?php $type = (string) get_post_meta( get_the_ID(), INSPIRATION_BOARD_META_TYPE, true ); ?>
				<span class="inspiration-board__tile inspiration-board__tile--<?php echo esc_attr( $type ? $type : 'photo' ); ?>">
				<?php
				echo wp_get_attachment_image(
					get_post_thumbnail_id(),
					'medium_large',
					false,
					array(
						'class'   => 'inspiration-board__img',
						'loading' => 'lazy',
						'sizes'   => '(max-width: 600px) 50vw, (max-width: 1000px) 33vw, 320px',
					)
				);
				?>
				<?php
				$video_id = (int) get_post_meta( get_the_ID(), INSPIRATION_BOARD_META_VIDEO, true );
				if ( $video_id ) :
					// Loaded and played by board.js once the tile is near the
					// viewport; the file size lets it skip the heaviest ones.
					$meta = wp_get_attachment_metadata( $video_id );
					$size = (int) ( $meta['filesize'] ?? 0 );
					if ( ! $size ) {
						$file = get_attached_file( $video_id );
						$size = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
					}
					?>
					<video class="inspiration-board__video" autoplay muted loop playsinline preload="none" data-size="<?php echo (int) $size; ?>" data-src="<?php echo esc_url( wp_get_attachment_url( $video_id ) ); ?>"></video>
				<?php endif; ?>
				</span>
			</a>
		<?php endwhile; ?>
	</div>
	<?php
	if ( $query->max_num_pages > 1 ) {
		echo '<nav class="inspiration-board__pagination">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'    => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $query->max_num_pages,
			)
		);
		echo '</nav>';
	}
	echo '</div>';

	wp_reset_postdata();

	return ob_get_clean();
}
