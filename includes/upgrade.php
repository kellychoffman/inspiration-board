<?php
/**
 * One-time data upgrades for pins created by earlier versions.
 */

defined( 'ABSPATH' ) || exit;

const INSPIRATION_BOARD_OPTION_SCHEMA = 'inspiration_board_schema';
const INSPIRATION_BOARD_SCHEMA        = 2;

add_action( 'admin_init', 'inspiration_board_maybe_upgrade' );
add_action( 'rest_api_init', 'inspiration_board_maybe_upgrade' );

function inspiration_board_maybe_upgrade() {
	if ( (int) get_option( INSPIRATION_BOARD_OPTION_SCHEMA ) >= INSPIRATION_BOARD_SCHEMA ) {
		return;
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	// Only one request runs the upgrade; a stale lock expires after 5 minutes.
	$lock = 'inspiration_board_upgrade_lock';
	if ( ! add_option( $lock, time(), '', false ) ) {
		if ( time() - (int) get_option( $lock ) < 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		update_option( $lock, time(), false );
	}

	inspiration_board_upgrade_to_tweet_dates();

	delete_option( $lock );
}

/**
 * Schema 2: board order moves from the post date to its own sort value, and
 * post dates (and so date-based permalinks) become the tweet's date. Pins
 * also get a plain numeric slug, since they no longer have titles.
 * WordPress keeps redirects from the old URLs (_wp_old_date, _wp_old_slug).
 */
function inspiration_board_upgrade_to_tweet_dates() {
	$pins = inspiration_board_all_pins();

	// Pass 1 (fast): record each pin's current place on the board, which
	// is still its post date, before any dates change.
	$converted = array();
	foreach ( $pins as $pin ) {
		if ( '' === get_post_meta( $pin->ID, INSPIRATION_BOARD_META_ORDER, true ) ) {
			update_post_meta( $pin->ID, INSPIRATION_BOARD_META_ORDER, (int) get_post_time( 'U', true, $pin ) );
			$converted[] = $pin;
		}
	}
	inspiration_board_make_orders_strict( $converted );

	// The board now reads sort values, so mark the upgrade done before the
	// slow part: re-dating can't disturb the order any more, and a request
	// that times out below never causes pass 1 to run again.
	update_option( INSPIRATION_BOARD_OPTION_SCHEMA, INSPIRATION_BOARD_SCHEMA );

	// Pass 2 (slow): tweet dates and numeric slugs, skipping pins already done.
	foreach ( $pins as $pin ) {
		inspiration_board_apply_tweet_date( $pin );
	}
}

/**
 * All pins, in current board order when sort values aren't set yet.
 *
 * @return WP_Post[]
 */
function inspiration_board_all_pins() {
	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => INSPIRATION_BOARD_META_IMAGE,
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		)
	);
}

/**
 * Dates a pin like its tweet and gives an untitled pin a numeric slug.
 * Does nothing when both are already right.
 */
function inspiration_board_apply_tweet_date( WP_Post $pin ) {
	$update  = array( 'ID' => $pin->ID );
	$tweeted = strtotime( (string) get_post_meta( $pin->ID, INSPIRATION_BOARD_META_DATE, true ) );
	if ( $tweeted && $tweeted <= time() && gmdate( 'Y-m-d H:i:s', $tweeted ) !== $pin->post_date_gmt ) {
		$date_gmt                = gmdate( 'Y-m-d H:i:s', $tweeted );
		$update['post_date']     = get_date_from_gmt( $date_gmt );
		$update['post_date_gmt'] = $date_gmt;
		$update['edit_date']     = true;
	}
	if ( '' === trim( $pin->post_title ) && (string) $pin->ID !== $pin->post_name ) {
		$update['post_name'] = (string) $pin->ID;
	}
	if ( count( $update ) > 1 ) {
		wp_update_post( $update );
	}
}

/**
 * Sort values must be strictly decreasing down the board. Pins created in
 * the same second share a timestamp, so nudge ties apart, top to bottom.
 * Only ever called with pins converted in this same run, in board order.
 *
 * @param WP_Post[] $pins Pins in current board order, top first.
 */
function inspiration_board_make_orders_strict( array $pins ) {
	$previous = PHP_INT_MAX;
	foreach ( $pins as $pin ) {
		$order = (int) get_post_meta( $pin->ID, INSPIRATION_BOARD_META_ORDER, true );
		if ( $order >= $previous ) {
			$order = $previous - 1;
			update_post_meta( $pin->ID, INSPIRATION_BOARD_META_ORDER, $order );
		}
		$previous = $order;
	}
}
