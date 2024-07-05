<?php

/** ITEMS REST API CODE */

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


    $product->save();
    // save post meta of product object from sage 100
    sage_roi_meta_upsert( 'post', $product->id, 'product_json', json_encode($productObject, true));

    sage_roi_meta_upsert( 
        'post', 
        $product->id, 
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

    // attributes
    $sageAttributeKey = "SOLD BY";
    $product_attributes = array(
        $sageAttributeKey => array($productObject->PurchaseUnitOfMeasure, $productObject->SalesUnitOfMeasure)
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

    $product->save();

    // save post meta of product object from sage 100
    sage_roi_meta_upsert( 'post', $product->id, 'product_json', json_encode($productObject, true));

    // units per package
    sage_roi_meta_upsert( 
        'post', 
        $product->id, 
        'product_unit_per_package', 
        $productObject->StandardUnitOfMeasure
    );

    // // variations
    $variations = array(
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => $productObject->PurchaseUnitOfMeasure ),
            'identifier' => 'woo-variation-identifier-' . $productObject->PurchaseUnitOfMeasure . '-' . $product->id,
            'number_of_unit' => $productObject->PurchaseUMConvFctr,
            'stock' => 'instock',
            'stock_qty' => count($productObject->ItemWarehouses) ? $productObject->ItemWarehouses[0]->QuantityOnPurchaseOrder : 0,
            'price' => $productObject->StandardUnitPrice,
        ),
        array(
            'attributes' => array( sanitize_title($sageAttributeKey) => $productObject->SalesUnitOfMeasure ),
            'identifier' => 'woo-variation-identifier-' . $productObject->SalesUnitOfMeasure . '-' . $product->id,
            'number_of_unit' => $productObject->SalesUMConvFct,
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
            $variation = new WC_Product_Variation( $variationIds[0] );
        }

        if(!$variation->id) {
            $variation = new WC_Product_Variation();
        }

        $variation->set_parent_id( $product->id );
        $variation->set_attributes( $variant['attributes'] );
        $variation->set_stock_status( $variant['stock'] );
        $variation->set_regular_price( $variant['price'] );
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity( $variant['stock_qty'] );
        $variation->save();

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

        if($variationIndex === 0) {
            // set default form values, or default variation
            sage_roi_meta_upsert( 'post', $variation->get_id(), '_default_attributes', $variant['attributes'], false);
        }

        $variationIndex++;
    }
}


function sage_roi_items_set_product( $productObject ) {

    $productAttributesLists = array(
        $productObject->SalesUnitOfMeasure,
        $productObject->PurchaseUnitOfMeasure,
        $productObject->StandardUnitOfMeasure,
    );

    if(count(array_unique( array_filter($productAttributesLists) )) > 1) {
        sage_roi_variant_product( $productObject );
    } else {
        sage_roi_simple_product( $productObject );
    }
}