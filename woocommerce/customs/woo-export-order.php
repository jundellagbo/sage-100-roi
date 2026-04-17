<?php
/**
 * Let customers export their own orders from My Account using
 * Advanced Order Export For WooCommerce (woo-order-export-lite).
 *
 * The lite plugin loads only in admin by default; we allow it to load for
 * admin-ajax.php when action is sage_my_account_export_orders, then run the
 * same bulk-export path as the admin orders list (Export Now profile).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the export plugin is installed (path check; does not require the plugin to be loaded).
 */
function sage_roi_woo_order_export_lite_installed() {
	return file_exists( WP_PLUGIN_DIR . '/woo-order-export-lite/woo-order-export-lite.php' );
}

/**
 * When the same order meta key exists more than once (common with HPOS / sync),
 * WOE concatenates all values with ", " which looks like a duplicated field
 * (e.g. "6739, 6739"). Prefer the first stored value per key.
 *
 * @link WC_Order_Export_Order_Fields::__construct() woe_use_first_order_meta
 */
add_filter( 'woe_use_first_order_meta', '__return_true', 1 );

/**
 * Allow WOE to bootstrap for our AJAX export only (keeps frontend light).
 */
add_filter( 'woe_check_running_options', 'sage_roi_woe_check_running_options', 1, 1 );
function sage_roi_woe_check_running_options( $is_backend ) {
	if ( $is_backend ) {
		return true;
	}
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && 'sage_my_account_export_orders' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}
	return false;
}

add_action( 'wp_ajax_sage_my_account_export_orders', 'sage_roi_ajax_my_account_export_orders' );
function sage_roi_ajax_my_account_export_orders() {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You must be logged in.', 'sage-100-roi' ), '', 403 );
	}

	check_ajax_referer( 'sage_my_account_export_orders', 'nonce' );

	if ( ! class_exists( 'WC_Order_Export_Ajax' ) ) {
		wp_die( esc_html__( 'Order export is not available.', 'sage-100-roi' ), '', 503 );
	}

	$order_ids_raw = isset( $_REQUEST['order_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$ids           = array_filter( array_map( 'absint', explode( ',', $order_ids_raw ) ) );

	if ( empty( $ids ) ) {
		wp_die( esc_html__( 'No orders selected.', 'sage-100-roi' ), '', 400 );
	}

	$user_id = get_current_user_id();
	foreach ( $ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_user_id() !== (int) $user_id ) {
			wp_die( esc_html__( 'You cannot export this order.', 'sage-100-roi' ), '', 403 );
		}
	}

	// Same request shape as admin bulk export → WooCommerce → Export as …
	$_REQUEST['ids']                 = implode( ',', $ids );
	$_REQUEST['export_bulk_profile'] = 'now';

	$ajax = new WC_Order_Export_Ajax();
	$ajax->ajax_export_download_bulk_file();
	exit;
}

/**
 * Export link for orders table (My Account → Orders).
 */
add_filter( 'woocommerce_my_account_my_orders_actions', 'sage_roi_my_account_orders_export_action', 10, 2 );
function sage_roi_my_account_orders_export_action( $actions, $order ) {
	if ( ! sage_roi_woo_order_export_lite_installed() || ! $order instanceof WC_Order ) {
		return $actions;
	}

	$actions['sage_export'] = array(
		'url'        => sage_roi_get_my_account_order_export_url( $order->get_id() ),
		'name'       => __( 'Export', 'sage-100-roi' ),
		'aria-label' => sprintf(
			/* translators: %s: order number */
			__( 'Export order %s', 'sage-100-roi' ),
			$order->get_order_number()
		),
	);

	return $actions;
}

/**
 * Export button on single order view (My Account → View order).
 */
add_action( 'woocommerce_view_order', 'sage_roi_view_order_export_button', 15, 1 );
function sage_roi_view_order_export_button( $order_id ) {
	if ( ! sage_roi_woo_order_export_lite_installed() ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
		return;
	}

	$url = sage_roi_get_my_account_order_export_url( $order->get_id() );
	echo '<p class="sage-roi-order-export"><a href="' . esc_url( $url ) . '" class="woocommerce-button button wp-element-button">' . esc_html__( 'Download export (Excel/CSV)', 'sage-100-roi' ) . '</a></p>';
}

/**
 * Builds admin-ajax URL for a one or more order IDs (comma-separated).
 *
 * @param string|int $order_ids Order ID(s).
 */
function sage_roi_get_my_account_order_export_url( $order_ids ) {
	return add_query_arg(
		array(
			'action'    => 'sage_my_account_export_orders',
			'nonce'     => wp_create_nonce( 'sage_my_account_export_orders' ),
			'order_ids' => $order_ids,
		),
		admin_url( 'admin-ajax.php' )
	);
}
