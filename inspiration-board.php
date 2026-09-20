<?php
/**
 * Plugin Name:       Inspiration Board
 * Plugin URI:        https://github.com/kellychoffman/inspiration-board
 * Description:       A Pinterest-style board built from your bookmarked tweets. Imports tweet images as posts in an "Inspiration" category and shows them as a gallery with the [inspiration_board] shortcode.
 * Version:           0.14.3
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            kellychoffman
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inspiration-board
 */

defined( 'ABSPATH' ) || exit;

define( 'INSPIRATION_BOARD_VERSION', '0.14.3' );
define( 'INSPIRATION_BOARD_FILE', __FILE__ );
define( 'INSPIRATION_BOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'INSPIRATION_BOARD_URL', plugin_dir_url( __FILE__ ) );

require_once INSPIRATION_BOARD_DIR . 'includes/category.php';
require_once INSPIRATION_BOARD_DIR . 'includes/importer.php';
require_once INSPIRATION_BOARD_DIR . 'includes/admin.php';
require_once INSPIRATION_BOARD_DIR . 'includes/gallery.php';
require_once INSPIRATION_BOARD_DIR . 'includes/local.php';
require_once INSPIRATION_BOARD_DIR . 'includes/upgrade.php';

register_activation_hook( __FILE__, 'inspiration_board_ensure_category' );
