<?php

/** CUSTOMERS REST API CODE */

add_action('rest_api_init', function () {
    register_rest_route( 'sage-roi', '/customers-sync', array(
        'methods' => 'POST',
        'callback' => 'sage_roi_customers_sync',
        'permission_callback' => 'sage_roi_request_permission_callback'
    ));
});


function sage_roi_unique_username( $username ) {
    $theusername = str_replace(" ", "_", $username);
    $theusername = preg_replace('/[^A-Za-z0-9\-]/', '', $theusername);
    $original_login = $theusername;
    do {
        //Check in the database here
        $exists = get_user_by( 'login', $original_login ) !== false;
        if($exists) {
            $i++;
            $original_login = $original_login . "_" . $i;
        }
    }  while($exists);

    return $original_login;
}

function sage_roi_set_customer( $customerObject ) {
    if(!$customerObject->EmailAddress) {
        return false;
    }
    $user = get_user_by( 'email', strtolower( $customerObject->EmailAddress ) );
    $userName = strtolower( $customerObject->CustomerName );
    if($user->ID) {
        $customer = new WC_Customer( $user->ID );
    } else {
        $customer = new WC_Customer();
        $userName = sage_roi_unique_username( $userName );
    }
    $customer->set_username( $userName );
    $customer->set_email( strtolower( $customerObject->EmailAddress ) );
    $customer->set_password( wp_generate_password() );
    $customer->set_first_name( $customerObject->CustomerName );
    
    // billing
    $customer->set_billing_address( $customerObject->AddressLine1 );
    $customer->set_billing_address_2( $customerObject->AddressLine2 );
    $customer->set_billing_city( $customerObject->City );
    $customer->set_billing_state( $customerObject->State );
    $customer->set_billing_country( substr( $customerObject->CountryCode, 0, 2 ) );
    $customer->set_billing_email( strtolower( $customerObject->EmailAddress ) );
    $customer->set_billing_first_name( $customerObject->CustomerName );
    $customer->set_billing_phone( $customerObject->TelephoneNo );
    $customer->set_billing_postcode( $customerObject->ZipCode );

    // shipping
    $customer->set_shipping_address( $customerObject->AddressLine1 );
    $customer->set_shipping_address_2( $customerObject->AddressLine2 );
    $customer->set_shipping_city( $customerObject->City );
    $customer->set_shipping_state( $customerObject->State );
    $customer->set_shipping_country( substr( $customerObject->CountryCode, 0, 2 ) );
    $customer->set_shipping_first_name( $customerObject->CustomerName );
    $customer->set_shipping_phone( $customerObject->TelephoneNo );
    $customer->set_shipping_postcode( $customerObject->ZipCode );

    $customer->save();

    $customerId = $customer->get_id();
    sage_roi_meta_upsert( 'user', $customerId, 'customer_json', json_encode($customerObject, true));
}

function sage_roi_customers_sync( WP_REST_Request $request ) {
    if(!empty(sage_roi_get_option('stop_sync_customers'))) {
        return false;
    }

    $code = sage_roi_token_validate();
    if($code !== 200) {
        return false;
    }
    $page = sage_roi_get_option( 'customers_page_number' );
    $page = empty($page) ? 1 : $page;
    $fds = new FSD_Data_Encryption();
    $requestURL = "https://roiconsultingapidev.azurewebsites.net/api/v2/customers/search?PageNumber=$page&PageSize=5";
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

     foreach( $results->Results as $customerObject ) {
        sage_roi_set_customer( $customerObject );
    }

    if($results->HasNext) {
        $page++;
        sage_roi_set_option( 'customers_page_number', $page );
     } else {
        sage_roi_set_option( 'customers_page_number', 1 );
        sage_roi_set_option( 'customer_sync_complete', 1 );
     }

    return $results;
}