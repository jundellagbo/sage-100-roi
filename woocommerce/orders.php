<?php

/** ORDERS REST API CODE */

add_action('rest_api_init', function () {
    register_rest_route( 'sage-roi', '/orders-sync', array(
        'methods' => 'POST',
        'callback' => 'sage_roi_orders_sync',
        'permission_callback' => 'sage_roi_request_permission_callback'
    ));
});


function sage_roi_orders_sync( WP_REST_Request $request ) {

    if(!empty(sage_roi_get_option('stop_sync_orders'))) {
        return false;
    }

    // nothing to do if all items and customers has not been completely fetched.
    if(!sage_roi_get_option('items_sync_complete') || !sage_roi_get_option('customer_sync_complete')) {
        return false;
    }

    $code = sage_roi_token_validate();
    if($code !== 200) {
        return false;
    }
    $page = sage_roi_get_option( 'orders_page_number' );
    $page = empty($page) ? 1 : $page;
    $fds = new FSD_Data_Encryption();
    $requestURL = "https://roiconsultingapidev.azurewebsites.net/api/v2/sales_order_history_headers/search?PageNumber=$page&PageSize=2";
    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '"x => x.IsProcessed == false"'
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
    }

     $results = json_decode($response['body']);

     foreach( $results->Results as $orderObject ) {
        sage_roi_set_customer_order( $orderObject );
    }

    if($results->HasNext) {
        $page++;
        sage_roi_set_option( 'orders_page_number', $page );
     } else {
        sage_roi_set_option( 'orders_page_number', 1 );
     }

    return $results;
}


function sage_roi_set_customer_order( $orderObject ) {

    $orderPostIds = wc_get_orders( array(
        'meta_key' => sage_roi_option_key("SalesOrderNo"),
        'meta_value' => $orderObject->SalesOrderNo,
        'meta_compare'  => '=',
        'return'        => 'ids'
    ));

    $orderId = count($orderPostIds) ? $orderPostIds[0] : null;
    $order = new WC_Order();
    if(!empty( $orderId )) {
        $order = new WC_Order( $orderId );
    }

    $user = get_user_by( 'email', strtolower( $orderObject->Customer->EmailAddress ) );
    if(!$user) { return false; }
    $order->set_customer_id( $user->ID );
    $order->set_created_via( 'admin' );


    $existingProductIds = [];
    foreach( $order->get_items() as $item_id => $item ) {
        $existingProductIds[] = $item->get_product_id();
    }

    foreach( $orderObject->SalesOrderHistoryDetails as $orderItems ) {
        $product_id = wc_get_product_id_by_sku( $orderItems->Item->ItemCode );
        $quantity = $orderItems->QuantityOrderedOriginal;
        if($orderItems->QuantityOrderedOriginal !== $orderItems->QuantityOrderedRevised) {
            $quantity = $orderItems->QuantityOrderedRevised;
        }
        if($product_id && !in_array($product_id, $existingProductIds)) {
            $order->add_product( wc_get_product( $product_id ), $quantity );
        }
    }

    $shipping_address = array(
        'first_name' => $orderObject->ShipToName,
        'address_1'  => $orderObject->ShipToAddress1,
        'address_2'  => $orderObject->ShipToAddress2,
        'city'       => $orderObject->ShipToCity,
        'state'      => $orderObject->ShipToState,
        'postcode'   => $orderObject->ShipToZipCode,
        'country'    => $orderObject->ShipToCountryCode
    );

    $billing_address = array(
        'first_name' => $orderObject->BillToName,
        'email'      => $orderObject->EmailAddress,
        'address_1'  => $orderObject->BillToAddress1,
        'address_2'  => $orderObject->BillToAddress2,
        'city'       => $orderObject->BillToCity,
        'state'      => $orderObject->BillToState,
        'postcode'   => $orderObject->BillToZipCode,
        'country'    => $orderObject->BillToCountryCode
    );

    $order->set_address( $shipping_address, 'shipping' );
    $order->set_address( $billing_address, 'billing' );

    $order->set_payment_method( strtolower($orderObject->PaymentType) );
    $order->set_payment_method_title( $orderObject->PaymentType );

    $order->set_status( 'wc-completed', 'Order is created programmatically from SAGE 100' );

    $order->set_discount_total( $orderObject->DiscountAmt );
    $order->set_total( $orderObject->NonTaxableAmt );

    $order->set_date_created( sage_roi_api_date( $orderObject->OrderDate ) );
    $order->set_date_paid( sage_roi_api_date( $orderObject->OrderDate ) );

    $order->add_meta_data( sage_roi_option_key( 'SalesOrderNo' ), $orderObject->SalesOrderNo );
    $order->add_meta_data( sage_roi_option_key( 'order_json' ), json_encode($orderObject, true) );

    $order->save();

    // disable sending email
    add_filter('woocommerce_new_order_email_allows_resend', '__return_false' );

    // update order item metas
    foreach( $order->get_items() as $item_id => $item ) {
        $productIdForMeta = $item->get_product_id();
        $productForMeta = wc_get_product($productIdForMeta);
        foreach( $orderObject->SalesOrderHistoryDetails as $oitems ) {
            $pSku = $productForMeta->get_sku();
            if($oitems->Item->ItemCode === $pSku) {
                wc_update_order_item_meta($item_id, sage_roi_option_key('order_item_json'), json_encode($oitems));
            }
        }
    }

}