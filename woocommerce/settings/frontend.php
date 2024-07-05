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
        echo '<p class="'.sage_roi_option_key('product_unit_per_package').'"><small>Units per package: '.$unitsPerPackage.'</small></p>';
    }
}


// insert custom product data to product summary
function sage_roi_add_content_product_summary() {
    global $product;
    $productId = $product->get_id();

    $unitsPerPackage = get_post_meta( $productId, sage_roi_option_key('product_unit_per_package'), true );
    if(!empty($unitsPerPackage)) {
        echo '<p class="'.sage_roi_option_key('product_unit_per_package').'"><small>Units Per Package: '.$unitsPerPackage.'</small></p>';
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
    if ( is_product() && $product->is_type( 'variable' ) ) {
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
    if ( is_product() && $product->is_type( 'variable' ) ) {
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
