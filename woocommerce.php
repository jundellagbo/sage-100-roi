<?php

// app and secret handler
function sage_roi_app_secret_handler( $request ) {
    $appId = $request->get_header( 'Sage-ROI-X-App-ID' );
    $appSecret = $request->get_header( 'Sage-ROI-X-App-Secret' );
    $appCredentials = base64_encode( $appId.":".$appSecret );
    
    $fds = new FSD_Data_Encryption();
    $wpAppId = $fds->decrypt(sage_roi_get_option('app_password_id'));
    $wpAppSecret = $fds->decrypt(sage_roi_get_option('app_password_secret'));
    $wpAppCredentials = base64_encode( $wpAppId.":".$wpAppSecret );

    if(($appCredentials === $wpAppCredentials)) { return true; }
    return false;
}

// Handling credentials for app id, secret and microsoft online oauth2 token
function sage_roi_request_permission_callback( WP_REST_Request $request ) {
    $tokenCode = sage_roi_token_validate();
    if((sage_roi_app_secret_handler( $request )) && $tokenCode===200) { return true; }
    return false;
}

// Handling credentials for non insynch related request
function sage_roi_request_permission_callback_no_insynch( WP_REST_Request $request ) {
    return sage_roi_app_secret_handler( $request );
}

// if param is provided then use the param values, otherwise use the get_option values, they are optionals.
function sage_roi_token_auth( $clientIdParam=null, $clientSecretParam=null ) {
    $fds = new FSD_Data_Encryption();
    $clientId = $clientIdParam ? $clientIdParam : $fds->decrypt(sage_roi_get_option('client_id'));
    $clientSecret = $clientSecretParam ? $clientSecretParam : $fds->decrypt(sage_roi_get_option('client_secret'));
    $requestURL = "https://login.microsoftonline.com/974b2ee7-8fca-4ab4-948a-02becfbf058f/oauth2/v2.0/token";

    $response = wp_remote_post($requestURL, array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded'
        ),
        'body' => array(
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'api://e18be6e0-2a6b-4f6d-b38b-dd9c3c34b141/.default'
        )
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
     }

     return json_decode($response['body']);
}

// handling token expiration and refresh the token.
function sage_roi_token_validate() {
    $fds = new FSD_Data_Encryption();
    $requestURL = "https://roiconsultingapidev.azurewebsites.net/api/items?take=1";
    $response = wp_remote_get($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
    ));

    $statusCode = wp_remote_retrieve_response_code($response);
    if($statusCode === 401) {
        $tokenAuth = sage_roi_token_auth();
        if(isset($tokenAuth->access_token)) {
            $appToken = $fds->encrypt($tokenAuth->access_token);
            sage_roi_set_option( 'access_token', $appToken );
            $statusCode = 200;
        }
    }

    return $statusCode;
}

add_action('rest_api_init', function () {
    register_rest_route( 'sage-roi', '/items-sync', array(
        'methods' => 'POST',
        'callback' => 'sage_roi_items_sync_api',
        'permission_callback' => 'sage_roi_request_permission_callback'
    ));
});

function sage_roi_items_sync_api( WP_REST_Request $request ) {
    if(!empty(sage_roi_get_option('stop_sync_items'))) {
        return false;
    }

    $code = sage_roi_token_validate();
    if($code !== 200) {
        return false;
    }
    $page = sage_roi_get_option( 'products_page_number' );
    $page = empty($page) ? 1 : $page;
    $fds = new FSD_Data_Encryption();
    $requestURL = "https://roiconsultingapidev.azurewebsites.net/api/v2/items/search?PageNumber=$page&PageSize=5";
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

     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
    }
     
     // pagination handler
     if($results->HasNext) {
        $page++;
        sage_roi_set_option( 'products_page_number', $page );
     } else {
        sage_roi_set_option( 'products_page_number', 1 );
     }
    
    return $results;
}


function sage_roi_items_set_product( $productObject ) {
    $product = new WC_Product();
    $product_id = wc_get_product_id_by_sku( $productObject->ItemCode );
    if($product_id) {
        $product = new WC_Product( $product_id );
    }
    $product->set_name( $productObject->ItemCodeDesc );
    $product->set_sku( $productObject->ItemCode );
    $product->set_slug( sanitize_title( $productObject->ItemCodeDesc, $productObject->ItemCode ) );
    $product->set_regular_price( $productObject->StandardUnitPrice );
    $product->set_sale_price( $productObject->SalesPromotionPrice > 0 ? $productObject->SalesPromotionPrice : null );

    // product category
    if(isset( $productObject->IM_ProductLine->ProductLineDesc )) {
        $categorySlug = strtolower($productObject->IM_ProductLine->ProductLineDesc);
        $category = get_term_by( 'slug', $categorySlug, 'product_cat' );
        $categoryId = $category->term_id;
        if(!isset($categoryId)) {
            $newcat = wp_insert_term(
                ucwords($categorySlug),
                'product_cat',
                array(
                    'slug' => $categorySlug
                )
            );
            $categoryId = $newcat['term_id'];
            sage_roi_meta_upsert( 'term', $categoryId, 'category_json', json_encode($productObject->IM_ProductLine, true));
        }
        $product->set_category_ids( array( $categoryId ) ); 
    }
    // end of product category

    // product warehouse
    // end of product warehouse

    // product variations
    // end of variations


    // product custom meta
    // end of custom meta


    $product->save();
    // save post meta of product object from sage 100
    sage_roi_meta_upsert( 'post', $product->id, 'product_json', json_encode($productObject, true));
}



add_action('rest_api_init', function () {
    register_rest_route( 'sage-roi', '/items-images-sync', array(
        'methods' => 'POST',
        'callback' => 'sage_roi_items_images_sync',
        'permission_callback' => 'sage_roi_request_permission_callback_no_insynch'
    ));
});

function sage_roi_items_sku_image_sync_auto_assign( $productSKU = null ) {
    global $wpdb;

    if(!$productSKU) { return false; }
    $product_id = wc_get_product_id_by_sku( $productSKU );
    if( !$product_id ) {
        return false;
    }
    $imageIds = array();

    // getting galleries
    $galleries = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_title LIKE '$productSKU%' AND post_type = 'attachment'  AND post_mime_type LIKE 'image%'", OBJECT );
    foreach($galleries as $gallery) {
        $imageIds[] = $gallery->ID;
    }

    // thumbnail setter
    $postThumbnail = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_title='$productSKU' AND post_type = 'attachment'  AND post_mime_type LIKE 'image%'", OBJECT );
    $theThumbnail = null;
    if(count( $postThumbnail )) {
        $theThumbnail = $postThumbnail[0]->ID;
    } else {
        if( count( $imageIds) ) {
            $theThumbnail = $imageIds[0];
        }
    }

    if($theThumbnail) {
        set_post_thumbnail($product_id, $theThumbnail);
        $imageIds = array_values( array_diff( $imageIds, array( $theThumbnail )) );
    }

    if(count($imageIds)) {
        sage_roi_meta_upsert('post', $product_id, '_product_image_gallery', implode(',', $imageIds), false);
    }

    return true;
}

function sage_roi_items_images_sync( WP_REST_Request $request ) {
    if(!empty(sage_roi_get_option('stop_sync_items_images'))) {
        return false;
    }
    
    $page = sage_roi_get_option( 'products_images_page_number' );
    $page = empty($page) ? 1 : $page;
    $args = array (
        'limit' => 10,
        'page' => $page,
    );
    $productArray = array();
    $products = wc_get_products( $args );
    foreach( $products as $product ) {
        $productArray[] = $product->get_data();
        sage_roi_items_sku_image_sync_auto_assign( $product->get_sku() );
    }
    $page++;
    sage_roi_set_option( 'products_images_page_number', $page );

    if(!count( $productArray )) {
        // set back to page 1 if there is no product in next page.
        sage_roi_set_option( 'products_images_page_number', 1 );
    }

    return array(
        'products' => $productArray,
        'NextPage' => $page
    );
}




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
    sage_roi_meta_upsert( 'user', $customer->id, 'customer_json', json_encode($customerObject, true));
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
     }

    return $results;
}


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



add_action('rest_api_init', function () {
    register_rest_route( 'sage-roi', '/test', array(
        'methods' => 'POST',
        'callback' => 'sage_roi_request_test',
    ));
});


function sage_roi_request_test( WP_REST_Request $request ) {
    global $wpdb;
    $product = new WC_Product_Variable();
    $product_id = wc_get_product_id_by_sku( 'test-123' );
    if($product_id) {
        $product = new WC_Product_Variable( $product_id );
    }
    $product->set_name( 'Test Product' );
    $product->set_sku( 'test-123' );
    $product->set_slug( sanitize_title( 'Test Product', 'test-123' ) );
    $product->set_regular_price( 10 );
    $product->set_sale_price( null );

    // attributes
    $sageAttributeKey = "SOLD BY";
    $product_attributes = array(
        $sageAttributeKey => array('BAG', 'CASE')
    );
    $attributes = [];
    foreach ($product_attributes as $name => $value) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name($name);
        $attribute->set_options($value);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }
    $product->set_attributes($attributes);
    $product->set_manage_stock(true);
    // yes, no, notify
    // $product->set_backorders('no');
    $product->save();

    // units per package
    sage_roi_meta_upsert( 
        'post', 
        $product->id, 
        'product_unit_per_package', 
        'BAG'
    );

    // variations
    $variations = array(
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => 'BAG' ),
            'identifier' => 'woo-variation-identifier-BAG-1-' . $product->id,
            'number_of_unit' => 1,
            'stock' => 'instock',
            'price' => 10,
            'sale' => 10,
            'sale_from' => null,
            'sale_to' => null,
            'description' => null
        ),
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => 'CASE' ),
            'identifier' => 'woo-variation-identifier-CASE-4-' . $product->id,
            'number_of_unit' => 4,
            'stock' => 'instock',
            'price' => 50,
            'sale' => 20,
            'sale_from' => null,
            'sale_to' => null,
            'description' => null
        ),
    );

    $variationIndex = 0;
    foreach($variations as $variant) {
        $variation = new WC_Product_Variation();
        $post_id_query = $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta where meta_value = %s LIMIT 1", $variant['identifier']);
        $variationIds = $wpdb->get_col( $post_id_query );
        if(count($variationIds)) {
            $variation = wc_get_product( $variationIds[0] );
        }
        $variation->set_parent_id( $product->id );
        $variation->set_attributes( $variant['attributes'] );
        $variation->set_stock_status( $variant['stock'] );
        $variation->set_regular_price( $variant['price'] );
        $variation->set_sale_price( $variant['sale'] );
        $variation->set_date_on_sale_from( $variant['sale_from'] );
        $variation->set_date_on_sale_to( $variant['sale_to'] );
        $variation->set_description( $variant['description'] );
        $variation->set_manage_stock(true);
        $variation->save();

        if($variationIndex === 0) {
            // set default form values, or default variation
            sage_roi_meta_upsert( 'post', $variation->get_id(), '_default_attributes', $variant['attributes'], false);
        }

        sage_roi_meta_upsert( 
            'post', 
            $variation->get_id(), 
            'number_of_units_package', 
            $variant['number_of_unit']
        );

        sage_roi_meta_upsert( 
            'post', 
            $variation->get_id(), 
            'identifier',
            $variant['identifier']
        );

        $variationIndex++;
    }


    // store locations setup
}
