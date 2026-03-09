<?php

function sage_roi_customers_acknowledgement( $customers = array() ) {
    if ( sage_roi_token_validate() !== 200 ) {
        return;
    }
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint("/v2/customers/batch/acknowledge");

    $date = new DateTime('now', new DateTimeZone('UTC'));
    $formatted = $date->format("Y-m-d\TH:i:s.v\Z");

    $acknowledgeCustomers = array();
    foreach ($customers as $customer) {
        $acknowledgeCustomers[] = array(
            'DateTimeProcessed' => $formatted,
            'CustomerNo' => $customer['CustomerNo'],
            'CompanyCode' => $customer['CompanyCode'] ?? '',
            'ARDivisionNo' => $customer['ARDivisionNo'] ?? '',
            'ExternalProviderId' => sage_roi_acknowledgement_external_provider_id()
        );
    }

    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => json_encode($acknowledgeCustomers)
    ));
}

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

function sage_roi_conditionally_email_notification( $enabled, $email ) {
    $enableEmail = sage_roi_get_option('stop_new_customer_email_notification');
    if(!empty($enableEmail)) {
        return false;
    }
    return $enabled;
}

add_filter( 'woocommerce_email_enabled_customer_new_account', 'sage_roi_conditionally_email_notification', 10, 2);
add_filter( 'woocommerce_email_enabled_customer_reset_password', 'sage_roi_conditionally_email_notification', 10, 2);
add_filter( 'wp_new_user_notification_email', 'sage_roi_conditionally_email_notification' );
add_filter( 'send_password_change_email', 'sage_roi_conditionally_email_notification' );
add_filter( 'send_email_change_email', 'sage_roi_conditionally_email_notification' );
add_filter( 'send_password_change_admin_email', 'sage_roi_conditionally_email_notification' );

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
        $customer->set_password( wp_generate_password() );
    }
    $customer->set_username( $userName );
    $customer->set_email( strtolower( $customerObject->EmailAddress ) );
    $customer->set_first_name( $customerObject->CustomerName );
    $customer->set_last_name( "-" );
    
    // billing
    $customer->set_billing_address( $customerObject->AddressLine1 );
    $customer->set_billing_address_2( $customerObject->AddressLine2 );
    $customer->set_billing_city( $customerObject->City );
    $customer->set_billing_state( $customerObject->State );
    $customer->set_billing_country( substr( $customerObject->CountryCode, 0, 2 ) );
    $customer->set_billing_email( strtolower( $customerObject->EmailAddress ) );
    $customer->set_billing_first_name( $customerObject->CustomerName );
    $customer->set_billing_last_name( "-" );
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

function sage_roi_customers_inprocess_sync_api() {
    if ( ! empty( sage_roi_get_option( 'stop_sync_customers' ) ) ) {
        return false;
    }

    $code = sage_roi_token_validate();
    if ( $code !== 200 ) {
        return false;
    }

    $page = sage_roi_get_option( 'customers_inprocess_page_number' );
    $page = empty( $page ) ? 1 : $page;
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint( "/v2/customers/search?PageNumber=$page&PageSize=5" );
    $response = wp_remote_post( $requestURL, array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt( sage_roi_get_option( 'access_token' ) )
        ),
        'body' => '"x => x.IsProcessed == false"'
    ) );

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
    }

    $results = json_decode( $response['body'] );

    $acknowledgeCustomers = array();
    foreach ( $results->Results as $customerObject ) {
        sage_roi_set_customer( $customerObject );
        if ( ! $customerObject->IsProcessed ) {
            $acknowledgeCustomers[] = array(
                'CustomerNo'    => $customerObject->CustomerNo,
                'CompanyCode'    => $customerObject->CompanyCode ?? '',
                'ARDivisionNo'  => $customerObject->ARDivisionNo ?? '',
            );
        }
    }

    if ( count( $acknowledgeCustomers ) ) {
        sage_roi_customers_acknowledgement( $acknowledgeCustomers );
    }

    if ( $results->HasNext === true ) {
        $page++;
        sage_roi_set_option( 'customers_inprocess_page_number', $page );
    }

    if ( $results->HasNext === false ) {
        sage_roi_set_option( 'customers_inprocess_page_number', 1 );
        sage_roi_set_option( 'customers_inprocess_sync_complete', 1 );
    }

    return $results;
}

function sage_roi_customers_sync() {
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
    $requestURL = sage_roi_base_endpoint("/v2/customers/search?PageNumber=$page&PageSize=5");
    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '""'
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
     }

     $results = json_decode($response['body']);

     $acknowledgeCustomers = array();
     foreach( $results->Results as $customerObject ) {
        sage_roi_set_customer( $customerObject );
        if( !$customerObject->IsProcessed ) {
            $acknowledgeCustomers[] = array(
                'CustomerNo' => $customerObject->CustomerNo,
                'CompanyCode' => $customerObject->CompanyCode ?? '',
                'ARDivisionNo' => $customerObject->ARDivisionNo ?? '',
            );
        }
    }

    if( count( $acknowledgeCustomers ) ) {
        sage_roi_customers_acknowledgement( $acknowledgeCustomers );
    }

    // pagination handler
    if($results->HasNext === true) {
        $page++;
        sage_roi_set_option( 'customers_page_number', $page );
    }

    if($results->HasNext === false) {
        sage_roi_set_option( 'customers_page_number', 1 );
        sage_roi_set_option( 'customer_sync_complete', 1 );
    }

    return $results;
}

function sage_roi_get_customer_in_sage( $email = null ) {
    if ( ! $email ) {
        $current_user = wp_get_current_user();
        if ( $current_user && ! empty( $current_user->user_email ) ) {
            $email = $current_user->user_email;
        }
    }

    if ( ! $email ) {
        return false;
    }

    // Use a separate cache for each email per day
    $cache_key = 'sage_roi_sage_customer_' . md5( strtolower( $email ) ) . '_' . date('Ymd');
    $cached_customer = get_transient( $cache_key );

    if ( false !== $cached_customer ) {
        if ( $cached_customer === '_not_found_' ) {
            return false;
        }
        if ( is_string($cached_customer) && strpos($cached_customer, 'Error:') === 0 ) {
            return $cached_customer;
        }
        return $cached_customer;
    }

    $code = sage_roi_token_validate();
    if ( $code !== 200 ) {
        return false;
    }

    $fds = new FSD_Data_Encryption();
    $customerRequestURL = sage_roi_base_endpoint("/v2/customers/search");
    $customerResponse = wp_remote_post($customerRequestURL, array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '"x => x.EmailAddress == \"' . $email . '\""'
    ));

    if ( is_wp_error( $customerResponse ) ) {
        set_transient( $cache_key, 'Error:' . $customerResponse->get_error_message(), DAY_IN_SECONDS );
        return $customerResponse->get_error_message();
    }

    $customerResponseResults = json_decode($customerResponse['body']);

    if ( ! isset($customerResponseResults->Results) || ! count($customerResponseResults->Results) ) {
        set_transient( $cache_key, '_not_found_', DAY_IN_SECONDS );
        return false;
    }

    $fetchedCustomer = $customerResponseResults->Results[0];
    sage_roi_set_customer( $fetchedCustomer );
    $acknowledgeCustomers = array();
    if ( ! $fetchedCustomer->IsProcessed ) {
        $acknowledgeCustomers[] = array(
            'CustomerNo'   => $fetchedCustomer->CustomerNo,
            'CompanyCode'  => $fetchedCustomer->CompanyCode ?? '',
            'ARDivisionNo'  => $fetchedCustomer->ARDivisionNo ?? '',
        );
    }
    if ( count( $acknowledgeCustomers ) ) {
        sage_roi_customers_acknowledgement( $acknowledgeCustomers );
    }
    set_transient( $cache_key, $fetchedCustomer, DAY_IN_SECONDS );

    return $fetchedCustomer;
}


// Show an alert at the very top of the header if current user is not in Sage
add_action('wp_body_open', function() {
    // Only show for logged-in users on the front-end (never in wp-admin)
    if ( is_admin() ) {
        return;
    }
    if ( !is_user_logged_in() ) {
        return;
    }
    $user = wp_get_current_user();
    if ( ! $user || empty( $user->user_email ) ) {
        return;
    }

    // Check if user exists in Sage
    $customer = sage_roi_get_customer_in_sage( $user->user_email );

    if ( ! $customer ) {
        // Basic style for top notification bar
        ?>
        <div style="background:var( --e-global-color-primary );color:#fff;padding:12px 0;text-align:center;font-weight:bold;position:sticky;top:0;left:0;width:100%;z-index:99999;">
            <span>We're sorry, but your account is not in SAGE 100 records. We cannot process your orders in SAGE 100. Please contact support.</span>
        </div>
        <?php
    }
});