<?php

/** IMAGE REST API CODE */

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