<?php

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

     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
        
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

     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
        
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


function sage_roi_itemcode_acknowledgement( $itemCode ) {
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint("/v2/items/acknowledge/{$itemCode}");

    $date = new DateTime('now', new DateTimeZone('UTC'));
    $formatted = $date->format("Y-m-d\TH:i:s.v\Z");

    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => wp_json_encode( array(
            "ExternalProviderId" => "ROITEST",
            "DateTimeProcessed" => $formatted
        ))
    ));
}


function sage_roi_simple_product( $productObject ) {
    global $wpdb;
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
    if(count($productObject->ItemWarehouses)) {
        $product->set_stock_quantity( $productObject->ItemWarehouses[0]->QuantityOnHand );
    }
    $product->set_stock_status('instock');

    // product category
    if(isset( $productObject->IM_ProductLine->ProductLineDesc )) {
        $categorySlug = strtolower($productObject->IM_ProductLine->ProductLineDesc);
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
    $product->set_manage_stock(true);
    if(count($productObject->ItemWarehouses)) {
        $product->set_stock_quantity( $productObject->ItemWarehouses[0]->QuantityOnHand );
    }
    // yes, no, notify
    // $product->set_backorders('no');
    // product category
    if(isset( $productObject->IM_ProductLine->ProductLineDesc )) {
        $categorySlug = strtolower($productObject->IM_ProductLine->ProductLineDesc);
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
        $variation->set_manage_stock(true);
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

    if( !$productObject->IsProcessed ) {
        sage_roi_itemcode_acknowledgement( $productObject->ItemCode );
    }
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


function sage_roi_set_product_ids( $itemCodes = array() ) {

    if(!count( $itemCodes )) {
        return [];
    }

    $code = sage_roi_token_validate();
    $requestURL = sage_roi_base_endpoint("/v2/items/search?PageNumber=1&PageSize=" . count( $itemCodes ));
    $fds = new FSD_Data_Encryption();
    $response = wp_remote_post($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
        'body' => '"x => (\"' . implode(",", $itemCodes) . '\").Contains(x.ItemCode)"'
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
     }

     $results = json_decode($response['body']);

     foreach( $results->Results as $productObject ) {
        sage_roi_items_set_product( $productObject );
    }

     return $results->Results;
}


// To prevent stock from being reduced on payment completion, it must be sage stock
add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false' );