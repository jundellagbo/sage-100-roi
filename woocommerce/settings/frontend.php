<?php

/**
 * Add short description to WooCommerce product blocks
 */
add_action( 'woocommerce_after_shop_loop_item', 'sage_roi_woo_show_excerpt_shop_page', 5 );
function sage_roi_woo_show_excerpt_shop_page() {
    global $product;
    $attributes = $product->get_attributes();
    foreach($attributes as $key => $attrib) {
        if(is_array($attributes[$key]['options'])) {
            echo "<p><small>". $attributes[$key]['name'] .": " . join(", ", array_unique($attributes[$key]['options'])) . "</small></p>";
        } else {
            echo "<p><small>". $attributes[$key]['name'] .": " . $attributes[$key]['options'] . "</small></p>";
        }
    }

    $productId = $product->get_id();
    $unitsPerPackage = get_post_meta( $productId, sage_roi_option_key('product_unit_per_package'), true );
    if(!empty($unitsPerPackage)) {
        echo '<p class="'.sage_roi_option_key('product_unit_per_package').'"><small>UNITS OF MEASURE: '.$unitsPerPackage.'</small></p>';
    }
}


// insert custom product data to product summary
function sage_roi_add_content_product_summary() {
    global $product;
    $productId = $product->get_id();

    $unitsPerPackage = get_post_meta( $productId, sage_roi_option_key('product_unit_per_package'), true );
    if(!empty($unitsPerPackage)) {
        echo '<p class="'.sage_roi_option_key('product_unit_per_package').'"><small>UNITS OF MEASURE: '.$unitsPerPackage.'</small></p>';
    }

    if($product->is_type('variable')) {
        foreach ($product->get_available_variations() as $key => $variation) 
        {
            $noOfPackage = get_post_meta($variation['variation_id'], sage_roi_option_key('number_of_units_package'), true);
            echo '<div data-roi-sage-variation="' . $variation['variation_id'] . '">';
                if(!empty($noOfPackage)):
                    echo "<p><small>No. of " . $unitsPerPackage . " (" . $noOfPackage . ")</small></p>";
                endif;
            echo '</div>';
        }
    }
}
add_action( 'woocommerce_single_product_summary', 'sage_roi_add_content_product_summary', 15 );


// handle display for selected variation style
add_action( 'wp_head', 'sage_roi_single_product_variation_style' );
function sage_roi_single_product_variation_style() {
    global $product;
    if ( is_object( $product ) && is_product() && $product->is_type( 'variable' ) ) {
        ?>
        <style type="text/css">
            [data-roi-sage-variation] {
                display: none;
            }
        </style>
        <?php
    }
}

// handle display for selected variation script
add_action( 'wp_footer', 'sage_roi_single_product_variation_change_script' );
function sage_roi_single_product_variation_change_script() {
    global $product;
    if ( is_object($product) && is_product() && $product->is_type( 'variable' ) ) {
        ?>
        <script type="text/javascript">
            function sage_roi_variation_handler() {
                var sageRoiVariationId = jQuery('input.variation_id').val();
                jQuery('[data-roi-sage-variation]').hide();
                jQuery('[data-roi-sage-variation="' + sageRoiVariationId + '"]').show();
            }
            jQuery(document).ready(sage_roi_variation_handler);
            jQuery( 'input.variation_id' ).change(sage_roi_variation_handler);
        </script>
        <?php
    }
}



# hide product from current user conditionally
// add_filter( 'woocommerce_product_is_visible', 'sage_roi_conditionally_product_hidden', 10, 2 );
// function sage_roi_conditionally_product_hidden( $is_visible, $id ) {
//     $product   = wc_get_product( $id );
//     if(is_object( $product )) {
//         $is_visible = false;
//     }
//     // $available = $product->get_attribute( 'availability' );
//     // if ( ! $product->is_in_stock() && ( 'Only with restock' !== $available ) ) {
//     //     $is_visible = false;
//     // }
//     // return $is_visible;

//     return $is_visible;
// }

// add_action('pre_get_posts', 'exclude_category_from_catalog');
// function exclude_category_from_catalog($query) {
//     // Check if we're in the WooCommerce main query and not in the admin area
//     if (!is_admin() && $query->is_main_query() && is_shop()) {
//         // Exclude products in a specific category from the catalog page
//         $tax_query = (array) $query->get('tax_query');
//         $tax_query[] = array(
//             'taxonomy' => 'product_cat',
//             'field'    => 'slug',
//             'terms'    => array('music'), // Replace with your category slug
//             'operator' => 'NOT IN'
//         );
        
//         $query->set('tax_query', $tax_query);
//     }
// }



// add_action( 'woocommerce_product_query', 'sage_roi_custom_pre_get_posts_query' );
// function sage_roi_custom_pre_get_posts_query( $q ) {
    
//     if (is_product_category()){
//         $term_id = get_queried_object()->term_id;
//         // $show_hide_products = get_term_meta($term_id, 'show_hide_products', true);
//         // if ($show_hide_products == 0){
//         //     $tax_query = (array) $q->get( 'tax_query' );
//         //     $tax_query[] = array(
//         //         'taxonomy' => 'product_cat',
//         //         'field' => 'term_id',
//         //         'terms' => array(17),
//         //         'operator' => 'NOT IN'
//         //     );
//         //     $q->set( 'tax_query', $tax_query );
//         // }

//         $tax_query = (array) $q->get( 'tax_query' );
//         $tax_query[] = array(
//             'taxonomy' => 'product_cat',
//             'field' => 'term_id',
//             'terms' => array(17),
//             'operator' => 'NOT IN'
//         );
//         $q->set( 'tax_query', $tax_query );
//     }
    
//     return $q;
// }



function sage_roi_custom_pre_get_posts_query( $q ) {
    $userId = get_current_user_id();
    if ( ! $userId ) {
        return;
    }

    $meta_query = array( 'relation' => 'AND' );

    // hide_from_customers (Sage ROI tab)
    $meta_query[] = array(
        'relation' => 'OR',
        array(
            'key'     => sage_roi_option_key( 'hide_from_customers' ),
            'compare' => 'NOT EXISTS',
        ),
        array(
            'key'     => sage_roi_option_key( 'hide_from_customers' ),
            'value'   => ';i:' . (int) $userId . '[;}]',
            'compare' => 'NOT REGEXP',
        ),
    );

    // hide_from_users (Show Item to User tab)
    $meta_query[] = array(
        'relation' => 'OR',
        array(
            'key'     => sage_roi_option_key( 'hide_from_users' ),
            'compare' => 'NOT EXISTS',
        ),
        array(
            'key'     => sage_roi_option_key( 'hide_from_users' ),
            'value'   => ';i:' . (int) $userId . '[;}]',
            'compare' => 'NOT REGEXP',
        ),
    );

    // show_only_to_users (Show Item to User tab) - if set, product visible only to listed users
    $meta_query[] = array(
        'relation' => 'OR',
        array(
            'key'     => sage_roi_option_key( 'show_only_to_users' ),
            'compare' => 'NOT EXISTS',
        ),
        array(
            'key'     => sage_roi_option_key( 'show_only_to_users' ),
            'value'   => ';i:' . (int) $userId . '[;}]',
            'compare' => 'REGEXP',
        ),
    );

    $q->set( 'meta_query', $meta_query );
}
add_action( 'woocommerce_product_query', 'sage_roi_custom_pre_get_posts_query' );

// Single product page: hide product if user lacks access
add_filter( 'woocommerce_product_is_visible', 'sage_roi_product_visibility_by_user', 10, 2 );
function sage_roi_product_visibility_by_user( $visible, $product_id ) {
    $userId = get_current_user_id();
    if ( ! $userId ) {
        return $visible;
    }

    $hide_from = get_post_meta( $product_id, sage_roi_option_key( 'hide_from_users' ), true );
    $hide_customers = get_post_meta( $product_id, sage_roi_option_key( 'hide_from_customers' ), true );
    $show_only = get_post_meta( $product_id, sage_roi_option_key( 'show_only_to_users' ), true );

    if ( is_array( $hide_from ) && in_array( $userId, array_map( 'intval', $hide_from ), true ) ) {
        return false;
    }
    if ( is_array( $hide_customers ) && in_array( $userId, array_map( 'intval', $hide_customers ), true ) ) {
        return false;
    }
    if ( is_array( $show_only ) && ! empty( $show_only ) && ! in_array( $userId, array_map( 'intval', $show_only ), true ) ) {
        return false;
    }

    return $visible;
}