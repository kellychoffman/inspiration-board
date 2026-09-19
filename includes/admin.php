<?php
/**
 * Tools → Inspiration Board: the snippet, the importer, and board page setup.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'inspiration_board_admin_menu' );
add_action( 'admin_post_inspiration_board_create_page', 'inspiration_board_create_page' );

function inspiration_board_admin_menu() {
	$hook = add_management_page(
		__( 'Inspiration Board', 'inspiration-board' ),
		__( 'Inspiration Board', 'inspiration-board' ),
		'publish_posts',
		'inspiration-board',
		'inspiration_board_render_admin'
	);

	add_action( 'admin_print_scripts-' . $hook, 'inspiration_board_admin_assets' );
}

function inspiration_board_admin_assets() {
	wp_enqueue_script(
		'inspiration-board-admin',
		INSPIRATION_BOARD_URL . 'assets/admin.js',
		array( 'wp-api-fetch' ),
		INSPIRATION_BOARD_VERSION,
		true
	);
	wp_enqueue_style(
		'inspiration-board-admin',
		INSPIRATION_BOARD_URL . 'assets/admin.css',
		array(),
		INSPIRATION_BOARD_VERSION
	);
}

/**
 * Finds a published page that contains the board shortcode.
 *
 * @return WP_Post|null
 */
function inspiration_board_find_page() {
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			's'              => '[inspiration_board',
			'posts_per_page' => 1,
		)
	);
	return $pages ? $pages[0] : null;
}

function inspiration_board_create_page() {
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_die( esc_html__( 'You are not allowed to create pages.', 'inspiration-board' ) );
	}
	check_admin_referer( 'inspiration_board_create_page' );

	$page = inspiration_board_find_page();
	if ( ! $page ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Inspiration', 'inspiration-board' ),
				'post_name'    => 'inspiration-board',
				'post_content' => "<!-- wp:shortcode -->\n[inspiration_board]\n<!-- /wp:shortcode -->",
				'page_template' => function_exists( 'register_block_template' ) ? INSPIRATION_BOARD_TEMPLATE : '',
			)
		);
	} else {
		$page_id = $page->ID;
	}

	wp_safe_redirect( admin_url( 'tools.php?page=inspiration-board&page_ready=' . (int) $page_id ) );
	exit;
}

function inspiration_board_render_admin() {
	$category_id = inspiration_board_ensure_category();
	$count       = $category_id ? (int) get_category( $category_id )->count : 0;
	$page        = inspiration_board_find_page();
	$snippet     = file_get_contents( INSPIRATION_BOARD_DIR . 'assets/collect-bookmarks.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	?>
	<div class="wrap inspiration-board-admin">
		<h1><?php esc_html_e( 'Inspiration Board', 'inspiration-board' ); ?></h1>

		<p class="ib-summary">
			<?php
			printf(
				/* translators: %s: number of posts */
				esc_html( _n( '%s post in Inspiration.', '%s posts in Inspiration.', $count, 'inspiration-board' ) ),
				'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>'
			);
			?>
			<?php if ( $page ) : ?>
				<a href="<?php echo esc_url( get_permalink( $page ) ); ?>"><?php esc_html_e( 'View the board', 'inspiration-board' ); ?></a>
				·
				<a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php esc_html_e( 'Edit board page', 'inspiration-board' ); ?></a>
			<?php endif; ?>
		</p>

		<div class="ib-step">
			<h2><span class="ib-num">1</span><?php esc_html_e( 'Collect your bookmarks', 'inspiration-board' ); ?></h2>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: link to X bookmarks */
						esc_html__( 'Open %s (or x.com/i/history) in your browser while logged in.', 'inspiration-board' ),
						'<a href="https://x.com/i/bookmarks" target="_blank" rel="noreferrer noopener">x.com/i/bookmarks</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Open the developer console (Cmd+Option+J in Chrome, Cmd+Option+C in Safari).', 'inspiration-board' ); ?></li>
				<li><?php esc_html_e( 'Paste the snippet below and press Return. It scrolls to the end of your bookmarks and downloads inspiration-bookmarks.json.', 'inspiration-board' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Chrome may ask you to type "allow pasting" the first time. The snippet only reads the page; it sends nothing anywhere.', 'inspiration-board' ); ?></p>
			<div class="ib-snippet">
				<textarea id="ib-snippet" readonly rows="8" spellcheck="false"><?php echo esc_textarea( $snippet ); ?></textarea>
				<button type="button" class="button" id="ib-copy"><?php esc_html_e( 'Copy snippet', 'inspiration-board' ); ?></button>
			</div>
		</div>

		<div class="ib-step">
			<h2><span class="ib-num">2</span><?php esc_html_e( 'Import', 'inspiration-board' ); ?></h2>
			<p><?php esc_html_e( 'Choose the downloaded file (or paste its contents). Each image becomes its own post in the Inspiration category. Images you have already imported are skipped, so you can re-run this any time you bookmark more.', 'inspiration-board' ); ?></p>
			<p><input type="file" id="ib-file" accept="application/json,.json"></p>
			<details>
				<summary><?php esc_html_e( 'Or paste JSON', 'inspiration-board' ); ?></summary>
				<textarea id="ib-json" rows="6" class="large-text code" spellcheck="false"></textarea>
			</details>
			<p><button type="button" class="button button-primary" id="ib-import"><?php esc_html_e( 'Import images', 'inspiration-board' ); ?></button></p>
			<div id="ib-progress" hidden>
				<progress id="ib-bar" max="100" value="0"></progress>
				<p id="ib-status" aria-live="polite"></p>
				<ul id="ib-errors"></ul>
			</div>
		</div>

		<div class="ib-step">
			<h2><span class="ib-num">3</span><?php esc_html_e( 'Show the board', 'inspiration-board' ); ?></h2>
			<?php if ( $page ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: page title link */
						esc_html__( 'Your board lives on %s.', 'inspiration-board' ),
						'<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="inspiration_board_create_page">
					<?php wp_nonce_field( 'inspiration_board_create_page' ); ?>
					<p><button type="submit" class="button"><?php esc_html_e( 'Create an "Inspiration" page', 'inspiration-board' ); ?></button></p>
				</form>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Or add the shortcode to any page yourself:', 'inspiration-board' ); ?>
				<code>[inspiration_board]</code>
				<?php esc_html_e( 'Options: columns="4" per_page="60".', 'inspiration-board' ); ?>
			</p>
		</div>
	</div>
	<?php
}
