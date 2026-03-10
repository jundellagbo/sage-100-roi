<?php

add_action( 'init', 'sage_roi_products_menu_order_support', 20 );
function sage_roi_products_menu_order_support() {
    add_post_type_support( 'product', 'page-attributes' );
}

add_action( 'pre_get_posts', 'sage_roi_admin_products_sort_by_menu_order', 5 );
function sage_roi_admin_products_sort_by_menu_order( $query ) {
    global $pagenow;
    if ( ! is_admin() || 'edit.php' !== $pagenow ) {
        return;
    }
    if ( ! isset( $_GET['post_type'] ) || 'product' !== $_GET['post_type'] ) {
        return;
    }
    if ( isset( $_GET['orderby'] ) ) {
        return;
    }
    $query->set( 'orderby', 'menu_order' );
    $query->set( 'order', 'ASC' );
}

add_filter( 'woocommerce_default_catalog_orderby', 'sage_roi_default_catalog_orderby' );
function sage_roi_default_catalog_orderby() {
    return 'menu_order';
}

function sage_roi_items_inprocess_sync_api() {
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
    $requestURL = sage_roi_base_endpoint("/v2/items/search?PageNumber=$page&PageSize=5");
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

     $acknowledgeItemCodes = array();
     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );

        if( !$productObject->IsProcessed ) {
            $acknowledgeItemCodes[] = array(
                'ItemCode' => $productObject->ItemCode,
                'CompanyCode' => $productObject->CompanyCode ?? ''
            );
        }
    }

    if( count( $acknowledgeItemCodes ) ) {
        sage_roi_items_acknowledgement( $acknowledgeItemCodes );
    }
     
     // pagination handler
     if($results->HasNext === true) {
        $page++;
        sage_roi_set_option( 'products_inprocess_page_number', $page );
    }

    if($results->HasNext === false) {
        sage_roi_set_option( 'products_inprocess_page_number', 1 );
        sage_roi_set_option( 'items_inprocess_sync_complete', 1 );
    }

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::log("Items in process sync result:");
        WP_CLI::log(print_r($results, true));
    }
    
    return $results;
}

function sage_roi_items_all_sync_api() {
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
    $requestURL = sage_roi_base_endpoint("/v2/items/search?PageNumber=$page&PageSize=5");
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

     $acknowledgeItemCodes = array();
     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
        
        if( !$productObject->IsProcessed ) {
            $acknowledgeItemCodes[] = array(
                'ItemCode' => $productObject->ItemCode,
                'CompanyCode' => $productObject->CompanyCode ?? ''
            );
        }
    }

    if( count( $acknowledgeItemCodes ) ) {
        sage_roi_items_acknowledgement( $acknowledgeItemCodes );
    }
     
     // pagination handler
     if($results->HasNext === true) {
        $page++;
        sage_roi_set_option( 'products_page_number', $page );
    }

    if($results->HasNext === false) {
        sage_roi_set_option( 'products_page_number', 1 );
        sage_roi_set_option( 'items_sync_complete', 1 );
    }

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::log("Items sync result:");
        WP_CLI::log(print_r($results, true));
    }
    
    return $results;
}


function sage_roi_items_acknowledgement( $items = array() ) {
    if ( sage_roi_token_validate() !== 200 ) {
        return;
    }
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint("/v2/items/batch/acknowledge");

    $date = new DateTime('now', new DateTimeZone('UTC'));
    $formatted = $date->format("Y-m-d\TH:i:s.v\Z");

    $acknowledgeItems = array();
    foreach ($items as $item) {
        $acknowledgeItems[] = array(
            'DateTimeProcessed' => $formatted,
            'ItemCode' => $item['ItemCode'] ?? '',
            'CompanyCode' => $item['CompanyCode'] ?? ''
        );
    }

    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => json_encode($acknowledgeItems)
    ));
}


function sage_roi_simple_product( $productObject ) {
    global $wpdb;
    $product = new WC_Product();
    $product_id = wc_get_product_id_by_sku( $productObject->ItemCode );
    if($product_id) {
        $product = new WC_Product( $product_id );
    } else {
        $product->set_menu_order( 999999 );
    }
    $product->set_name( $productObject->ItemCodeDesc );
    $product->set_sku( $productObject->ItemCode );
    $product->set_slug( sanitize_title( $productObject->ItemCodeDesc, $productObject->ItemCode ) );
    $product->set_regular_price( $productObject->StandardUnitPrice );
    $product->set_sale_price( $productObject->SalesPromotionPrice > 0 ? $productObject->SalesPromotionPrice : null );
    if(count($productObject->ItemWarehouses)) {
        $product->set_stock_quantity( $productObject->ItemWarehouses[0]->QuantityOnHand );
    }
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');
    $product->set_attributes( array() );
    $product->set_short_description( '' );

    // product category
    if(isset( $productObject->IM_ProductLine->ProductLineDesc )) {
        $categorySlug = strtolower($productObject->IM_ProductLine->ProductLineDesc);

        $customCategory = get_post_meta( $product->get_id(), sage_roi_option_key('product_custom_category'), true );
        if(!empty($customCategory)) {
            $categorySlug = strtolower($customCategory);
        }

        if($category = get_term_by( 'slug', $categorySlug, 'product_cat' )) {
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
    }
    // end of product category

    if( $productObject->InactiveItem == "Y" ) {
        $product->set_status('draft');
    }

    $product->save();

    $productId = $product->get_id();

    // save post meta of product object from sage 100
    sage_roi_meta_upsert( 'post', $productId, 'product_json', json_encode($productObject, true));

    sage_roi_meta_upsert( 
        'post', 
        $productId, 
        'product_unit_per_package', 
        $productObject->StandardUnitOfMeasure
    );
}

function sage_roi_variant_product( $productObject ) {

    global $wpdb;
    $product = new WC_Product_Variable();
    $product_id = wc_get_product_id_by_sku( $productObject->ItemCode );
    if($product_id) {
        $product = new WC_Product_Variable( $product_id );
    } else {
        $product->set_menu_order( 999999 );
    }
    $product->set_name( $productObject->ItemCodeDesc );
    $product->set_sku( $productObject->ItemCode );
    $product->set_slug( sanitize_title( $productObject->ItemCodeDesc, $productObject->ItemCode ) );
    $product->set_regular_price( $productObject->StandardUnitPrice );
    $product->set_sale_price( $productObject->SalesPromotionPrice > 0 ? $productObject->SalesPromotionPrice : null );
    if( $productObject->InactiveItem == "Y" ) {
        $product->set_status('draft');
    }

    // attributes
    $sageAttributeKey = "SOLD BY";
    $product_attributes = array(
        // $sageAttributeKey => array($productObject->PurchaseUnitOfMeasure, $productObject->SalesUnitOfMeasure)
        $sageAttributeKey => array($productObject->StandardUnitOfMeasure)
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
    $product->set_manage_stock(false);
    if(count($productObject->ItemWarehouses)) {
        $product->set_stock_quantity( $productObject->ItemWarehouses[0]->QuantityOnHand );
    }
    // yes, no, notify
    // $product->set_backorders('no');
    // product category
    if(isset( $productObject->IM_ProductLine->ProductLineDesc )) {
        $categorySlug = strtolower($productObject->IM_ProductLine->ProductLineDesc);

        $customCategory = get_post_meta( $product->get_id(), sage_roi_option_key('product_custom_category'), true );
        if(!empty($customCategory)) {
            $categorySlug = strtolower($customCategory);
        }
        
        if($category = get_term_by( 'slug', $categorySlug, 'product_cat' )) {
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
    }
    // end of product category

    $product->save();

    $productId = $product->get_id();

    // save post meta of product object from sage 100
    sage_roi_meta_upsert( 'post', $productId, 'product_json', json_encode($productObject, true));

    // units per package
    sage_roi_meta_upsert( 
        'post', 
        $productId, 
        'product_unit_per_package', 
        $productObject->SalesUnitOfMeasure
    );

    // // variations
    $variations = array(
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => $productObject->PurchaseUnitOfMeasure ),
            'identifier' => 'woo-variation-identifier-' . $productObject->PurchaseUnitOfMeasure . '-' . $productId,
            'number_of_unit' => isset($productObject->PurchaseUMConvFctr) ? $productObject->PurchaseUMConvFctr : 1,
            'stock' => 'instock',
            'stock_qty' => count($productObject->ItemWarehouses) ? $productObject->ItemWarehouses[0]->QuantityOnPurchaseOrder : 0,
            'price' => $productObject->StandardUnitPrice,
        ),
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => $productObject->SalesUnitOfMeasure ),
            'identifier' => 'woo-variation-identifier-' . $productObject->SalesUnitOfMeasure . '-' . $productId,
            'number_of_unit' => isset($productObject->SalesUMConvFct) ? $productObject->SalesUMConvFct : 1,
            'stock' => 'instock',
            'stock_qty' => count($productObject->ItemWarehouses) ? $productObject->ItemWarehouses[0]->QuantityOnSalesOrder : 0,
            'price' =>  $productObject->StandardUnitCost,
        ),
    );

    $variationIndex = 0;
    foreach($variations as $variant) {
        $variation = new WC_Product_Variation();
        $post_id_query = $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}postmeta where meta_value = %s LIMIT 1", $variant['identifier']);
        $variationIds = $wpdb->get_col( $post_id_query );
        if(count($variationIds)) {
            try {
                $variation = new WC_Product_Variation( $variationIds[0] );
            } catch(Exception $e) {
                $variation = new WC_Product_Variation();
            }
        }

        if(!$variation->get_id()) {
            $variation = new WC_Product_Variation();
        }

        $variation->set_parent_id( $productId );
        $variation->set_attributes( $variant['attributes'] );
        $variation->set_stock_status( $variant['stock'] );
        $variation->set_regular_price( $variant['price'] );
        $variation->set_manage_stock(false);
        $variation->set_stock_quantity( $variant['stock_qty'] );
        $variation->save();

        $variationId = $variation->get_id();

        sage_roi_meta_upsert( 
            'post', 
            $variationId, 
            'number_of_units_package', 
            $variant['number_of_unit']
        );

        sage_roi_meta_upsert( 
            'post', 
            $variationId, 
            'identifier',
            $variant['identifier']
        );

        if($variationIndex === 0) {
            // set default form values, or default variation
            sage_roi_meta_upsert( 'post', $variationId, '_default_attributes', $variant['attributes'], false);
        }

        $variationIndex++;
    }
}


function sage_roi_items_set_product( $productObject ) {

    $productAttributesLists = array(
        $productObject->StandardUnitOfMeasure,
        // $productObject->PurchaseUnitOfMeasure,
        // $productObject->StandardUnitOfMeasure,
    );

    sage_roi_simple_product( $productObject );

    // if(count(array_unique( array_filter($productAttributesLists) )) > 1) {
    //     sage_roi_variant_product( $productObject );
    // } else {
    //     sage_roi_simple_product( $productObject );
    // }
}


add_action( 'before_delete_post', 'sage_roi_delete_product_sage' );
function sage_roi_delete_product_sage( $post_id ) {
    $product = wc_get_product( $post_id);
    // execute to sage api.

} 

add_action( 'save_post', 'sage_roi_save_product_sage' );
function sage_roi_save_product_sage($post_id){
    $product = wc_get_product( $post_id);
    // execute to sage api.
    
}

add_action( 'woocommerce_new_product', 'sage_roi_new_product_menu_order_last', 10, 2 );
function sage_roi_new_product_menu_order_last( $product_id, $product ) {
    if ( $product && is_a( $product, 'WC_Product' ) ) {
        $product->set_menu_order( 999999 );
        $product->save();
    }
}

add_action( 'transition_post_status', 'sage_roi_new_product_menu_order_on_publish', 10, 3 );
function sage_roi_new_product_menu_order_on_publish( $new_status, $old_status, $post ) {
    if ( 'product' !== $post->post_type || 'publish' !== $new_status ) {
        return;
    }
    if ( in_array( $old_status, array( 'publish', 'trash', 'private' ), true ) ) {
        return;
    }
    wp_update_post( array(
        'ID'         => $post->ID,
        'menu_order' => 999999,
    ) );
}



function sage_roi_set_product_ids( $itemCodes = array() ) {

    if ( ! count( $itemCodes ) ) {
        return [];
    }

    $code = sage_roi_token_validate();
    $requestURL = sage_roi_base_endpoint( '/v2/items/search?PageNumber=1&PageSize=' . count( $itemCodes ) );
    $fds = new FSD_Data_Encryption();

    // Exact match: ItemCode == "code1" || ItemCode == "code2" (no array.Contains - API has no LINQ)
    $quoted = array_map( function ( $c ) {
        return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $c ) . '"';
    }, $itemCodes );
    $filter = 'x => ' . implode( ' || ', array_map( function ( $q ) {
        return 'x.ItemCode == ' . $q;
    }, $quoted ) );
    $body   = wp_json_encode( $filter );

    $response = wp_remote_post( $requestURL, array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt( sage_roi_get_option( 'access_token' ) )
        ),
        'body' => $body,
    ) );

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
    }

    $results = json_decode( $response['body'] );
    if ( empty( $results->Results ) ) {
        return [];
    }

    $acknowledgeItemCodes = array();

    foreach ( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
        if( !$productObject->IsProcessed ) {
            $acknowledgeItemCodes[] = array(
                'ItemCode' => $productObject->ItemCode,
                'CompanyCode' => $productObject->CompanyCode ?? ''
            );
        }
    }

    if( count( $acknowledgeItemCodes ) ) {
        sage_roi_items_acknowledgement( $acknowledgeItemCodes );
    }

    return $results->Results;
}


// To prevent stock from being reduced on payment completion, it must be sage stock
add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false' );

add_action( 'woocommerce_reduce_order_stock', 'sage_roi_reduce_order_stock', 10, 2 );


add_filter('woocommerce_get_catalog_ordering_args', 'sage_roi_custom_woocommerce_menu_order');
function sage_roi_custom_woocommerce_menu_order($args) {
    $args['orderby'] = 'menu_order title';
    $args['order'] = 'ASC';
    return $args;
}