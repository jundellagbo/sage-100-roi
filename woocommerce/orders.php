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
    $requestURL = sage_roi_base_endpoint("/v2/sales_order_history_headers/search?PageNumber=$page&PageSize=2");
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

     // pagination handler
     if($results->HasNext === true) {
        $page++;
        sage_roi_set_option( 'orders_page_number', $page );
    }

    if($results->HasNext === false) {
        sage_roi_set_option( 'orders_page_number', 1 );
        sage_roi_set_option( 'orders_sync_complete', 1 );
    }

    return $results;
}



function sage_roi_refetch_order( $orderId ) {
    $code = sage_roi_token_validate();
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint("/v2/sales_order_history_headers/search?PageNumber=1&PageSize=1");
    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '"x => x.SalesOrderNo == \"' . $orderId . '\""'
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
    }

     $results = json_decode($response['body']);

     foreach( $results->Results as $orderObject ) {
        sage_roi_set_customer_order( $orderObject );
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
        //'email'      => $orderObject->EmailAddress,
        'address_1'  => $orderObject->BillToAddress1,
        'address_2'  => $orderObject->BillToAddress2,
        'city'       => $orderObject->BillToCity,
        'state'      => $orderObject->BillToState,
        'postcode'   => $orderObject->BillToZipCode,
        'country'    => $orderObject->BillToCountryCode
    );

    if(!empty($orderObject->EmailAddress)) {
        $billing_address['email'] = strtolower(str_replace(',', '.', $orderObject->EmailAddress));
    }

    $order->set_address( $shipping_address, 'shipping' );
    $order->set_address( $billing_address, 'billing' );

    $order->set_payment_method( strtolower($orderObject->PaymentType) );
    $order->set_payment_method_title( $orderObject->PaymentType );

    $order->set_status( 'wc-completed', 'Order is created programmatically from SAGE 100' );

    $order->set_discount_total( $orderObject->DiscountAmt );
    $order->set_total( $orderObject->NonTaxableAmt );

    $order->set_date_created( sage_roi_api_date( $orderObject->OrderDate ) );
    $order->set_date_paid( sage_roi_api_date( $orderObject->OrderDate ) );

    $order->update_meta_data( sage_roi_option_key( 'SalesOrderNo' ), $orderObject->SalesOrderNo );
    $order->update_meta_data( sage_roi_option_key( 'order_json' ), json_encode($orderObject, true) );

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


function sage_roi_submit_order_to_api( $orderId ) {

    $order = wc_get_order( $orderId );

    $customerId = $order->get_customer_id();

    $customer = new WC_Customer( $customerId );

    $customerJsonData = get_user_meta( $customer->ID, sage_roi_option_key('customer_json'), true );

    $customerJson = json_decode( $customerJsonData );

    if( !is_object( $customerJson )) {
        return false;
    }

    $code = sage_roi_token_validate();
    if($code !== 200) {
        return [];
    }

    // refetching and auto update customer record from SAGE API
    $fds = new FSD_Data_Encryption();
    $customerRequestURL = sage_roi_base_endpoint("/v2/customers/search");
    $customerResponse = wp_remote_post($customerRequestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '"x => x.CustomerNo == \"' . $customerJson->CustomerNo . '\""'
    ));

    if ( is_wp_error( $customerResponse ) ) {
        return $customerResponse->get_error_message();
     }

     $customerResponseResults = json_decode($customerResponse['body']);
    
    // end here if there is no record, disregard the process
    if(!count($customerResponseResults->Results)) {
      return false;
    }


    $fetchedCustomer = $customerResponseResults->Results[0];

    // update customer record from SAGE 100
    sage_roi_set_customer( $fetchedCustomer );


    // refetch fresh customer update
    $customer = new WC_Customer( $customerId );


    // order params preparation
    $args = array(
        "SalesOrderNo" => $orderId,
        "OrderDate" => date('Ymd', strtotime($order->order_date)),
        "OrderType" => "C",
        "DateTimeProcessed" => $order->order_date,
        "DateCreatedUtc" => $order->order_date,
        "CompanyCode" => $fetchedCustomer->CompanyCode,
        "CustomerNo" => $customerJson->CustomerNo,
        "BillToName" => $fetchedCustomer->CustomerName,
        "Customer" => $fetchedCustomer,
        "Salesperson" => $fetchedCustomer->Salesperson,
        "ARDivisionNo" => $fetchedCustomer->ARDivisionNo,
        "BillToDivisionNo" => $fetchedCustomer->ARDivisionNo,
        "BillToCustomerNo" => $fetchedCustomer->CustomerNo,
        "BillToName" => $fetchedCustomer->CustomerName,
        "BillToAddress1" => $customer->get_billing_address(),
        "BillToAddress2" => $customer->get_billing_address_2(),
        "BillToCity" => $customer->get_billing_city(),
        "BillToState" => $customer->get_billing_state(),
        "BillToZipCode" => $customer->get_billing_postcode(),
        "BillToCountryCode" => $customer->get_billing_country(),
        "EmailAddress" => $customer->get_billing_email(),
        "SalesOrderDetails" => array()
    );

    $itemCodes = array();
    foreach( $order->get_items() as $item_id => $item ) {
        $product = $item->get_product();
        $pSku = $product->get_sku();
        $itemCodes[] = $pSku;
    }

    $productApis = sage_roi_set_product_ids( $itemCodes );
    $lineKey = 0;
    foreach( $order->get_items() as $item_id => $item ) {
        $lineKey++;
        $product = $item->get_product();
        $pSku = $product->get_sku();
        
        $salesOrderDetails = array(
            "SalesOrderNo" => $orderId,
            "ItemCode" => $pSku,
            "QuantityOrdered" => $item->get_quantity(),
            "UnitPrice" => $product->get_price(),
            "LineKey" => str_pad($lineKey, 6, "0", STR_PAD_LEFT)
        );

        if( $product->is_type('simple') ) {
            $salesOrderDetails['UnitOfMeasure'] = get_post_meta( $product->get_id(), sage_roi_option_key('product_unit_per_package'), true );
        }

        
        // Only for product variation
        if( $product->is_type('variation') ) {
                // Get the variation attributes
            $variation_attributes = $product->get_variation_attributes();
            // Loop through each selected attributes
            foreach($variation_attributes as $attribute_taxonomy => $term_slug ) {
                // Get product attribute name or taxonomy
                $taxonomy = str_replace('attribute_', '', $attribute_taxonomy );
                // The label name from the product attribute
                $attribute_name = wc_attribute_label( $taxonomy, $product );
                // The term name (or value) from this attribute
                if( taxonomy_exists($taxonomy) ) {
                    $attribute_value = get_term_by( 'slug', $term_slug, $taxonomy )->name;
                } else {
                    $attribute_value = $term_slug; // For custom product attributes
                }

                $salesOrderDetails['UnitOfMeasure'] = $attribute_value;
            }
        }

        
        
        foreach( $productApis as $papi ) {
            if($papi->ItemCode == $pSku) {
                $papi->StandardUnitCost = number_format( $papi->StandardUnitCost, 2);
                $papi->StandardUnitPrice = number_format( $papi->StandardUnitPrice, 2);
                $salesOrderDetails['Item'] = $papi;
            }

            error_log( "API TEST " . json_encode($papi));
        }


        $args['SalesOrderDetails'][] = $salesOrderDetails;
    }

    

    # submit order to api.
    sage_roi_token_validate();
    $submitOrderURL = sage_roi_base_endpoint("/v2/sales_order_headers");
    $submitOrderResponse = wp_remote_post($submitOrderURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token')),
        ),
        'body' => wp_json_encode( $args )
    ));

    if ( is_wp_error( $submitOrderResponse ) ) {
        return $submitOrderResponse->get_error_message();
     }

     $submitOrderResponseResults = json_decode($submitOrderResponse['body']);

     $order->update_meta_data( sage_roi_option_key( 'SalesOrderNo' ), $orderId );

     $order->update_meta_data( sage_roi_option_key( 'submitted_order_json' ), json_encode( $submitOrderResponseResults ) );

     $order->update_meta_data( sage_roi_option_key( 'submitted_order_payload_json' ), json_encode( $args ) );

     $order->update_meta_data( sage_roi_option_key( 'thankyou_action_done' ), true );

    $order->save();


    # resync items after order for stock management
    sage_roi_set_product_ids( $itemCodes );

    # refetch updated order from SAGE to System
    sage_roi_refetch_order( $orderId );

    return $submitOrderResponseResults;
}


add_action('woocommerce_thankyou', 'sage_roi_process_order');

function sage_roi_process_order( $orderId ) {

    if( ! get_post_meta( $orderId, sage_roi_option_key( 'thankyou_action_done' ), true ) ) {

        return sage_roi_submit_order_to_api( $orderId );
    }
}



# preview api json order
// Add custom order meta data to make it accessible in Order preview template
add_filter( 'woocommerce_admin_order_preview_get_order_details', 'sage_roi_admin_order_preview_add_custom_meta_data', 10, 2 );
function sage_roi_admin_order_preview_add_custom_meta_data( $data, $order ) {
    $data['submitted_order_payload_json'] = get_post_meta( $order->get_id(), sage_roi_option_key( 'submitted_order_payload_json' ), true );
    $data['submit_order_json'] = get_post_meta( $order->get_id(), sage_roi_option_key( 'submitted_order_json' ), true );
    $data['order_json'] = get_post_meta( $order->get_id(), sage_roi_option_key( 'order_json' ), true );
    return $data;
}

// Display custom values in Order preview
add_action( 'woocommerce_admin_order_preview_end', 'sage_roi_display_sage_json_response' );
function sage_roi_display_sage_json_response() {
    // Call the stored value and display it
    ob_start();
    ?>
    <div class="wc-order-preview-address w-full" style="padding: 15px;">

        <h3>Sage 100 Order API Sync</h3>
        <div style="overflow-x: auto; max-width: 100%;">
            <pre>{{ data.order_json }}</pre>
        </div>

        <h3>Sage 100 Order Submission Payload</h3>
        <div style="overflow-x: auto; max-width: 100%;">
            <pre>{{ data.submitted_order_payload_json }}</pre>
        </div>

        <h3>Sage 100 Order Submission API Response</h3>
        <div style="overflow-x: auto; max-width: 100%;">
            <pre>{{ data.submit_order_json }}</pre>
        </div>
    </div>

    <?php
    echo ob_get_clean();
}