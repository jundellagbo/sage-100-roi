<?php

/** ORDERS REST API CODE */

function sage_roi_set_customer_order( $orderObject ) {

    $orderId = get_post_meta( $orderObject->SalesOrderHistoryHeaderId, sage_roi_option_key( 'sales_order_history_header_id' ) );
    $order = new WC_Order();
    if(!empty( $orderId )) {
        $order = new WC_Order( $orderId );
    }

    $user = get_user_by( 'email', strtolower( $orderObject->Customer->EmailAddress ) );
    if(!$user) { return false; }
    $order->set_customer_id( $user->ID );

    foreach( $orderObject->SalesOrderHistoryDetails as $orderItems ) {
        
    }
}

