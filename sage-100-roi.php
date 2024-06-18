<?php
/**
 * Plugin Name: Sage API Connection to ROI Consulting
 * Plugin URI: mailto:jj@xooker.com
 * Description: Connect and sync ecommerce platform from SAGE 100 to Wordpress Woocommerce
 * Version: 1.0.0
 * Author: JJXooker
 * Author URI: mailto:jj@xooker.com
 * License: GPL-2.0+
 * Text Domain: sage100roi
 * Domain Path: /languages
 *
 */

 // reference https://fullstackdigital.io/blog/how-to-safely-store-api-keys-and-access-protected-external-apis-in-wordpress/

 require_once __DIR__ . '/FSD_Data_Encryption.php';

 add_action('admin_menu', 'sage_roi_register_my_api_keys_page');

function sage_roi_register_my_api_keys_page() {
  add_submenu_page(
    'tools.php', // Add our page under the "Tools" menu
    'Sage 100 ROI', // Title in menu
    'Sage 100 ROI', // Page title
    'manage_options', // permissions
    'api-keys', // slug for our page
    'sage_roi_form_page_callback' // Callback to render the page
  );
}


function sage_roi_form_page_callback() {

    require_once __DIR__ . '/api-settings.php';
}

require_once __DIR__ . '/woocommerce.php';

function sage_roi_option_key( $option ) {
    return $optionKey = 'wp_xookerdev_sage_roi_' . $option;
}

function sage_roi_set_option( $option, $value ) {
    $optionKey = sage_roi_option_key($option);
    if(is_null($value)) {
        delete_option( $optionKey );
        return false;
    }
    if (!empty(get_option( $optionKey ))) {
        update_option( $optionKey, $value);
    } else {
        add_option( $optionKey, $value);
    }
}

function sage_roi_get_option( $option, $default="" ) {
    $optionKey = sage_roi_option_key($option);
    if (!empty(get_option( $optionKey ))) {
        return get_option( $optionKey );
    } else {
        return $default;
    }
}

function sage_roi_meta_upsert( $type, $id, $key, $data, $usePluginKey=true ) {
    $meta = "_".$type."_meta";
    $get_meta = "get$meta";
    $update_meta = "update$meta";
    $add_meta = "add$meta";
    if(
        !function_exists( $get_meta ) ||
        !function_exists( $update_meta ) ||
        !function_exists( $add_meta )
    ) {
        return false;
    }

    $metaKey = sage_roi_option_key($key);
    if(!$usePluginKey) {
        $metaKey = $key;
    }

    if(!empty( $get_meta( $id, $metaKey, true ) )) {
        $update_meta( 
            $id, 
            $metaKey, 
            $data
        );
    } else {
        $add_meta( 
            $id, 
            $metaKey, 
            $data, 
            true 
        );
    }
}

add_action( 'admin_post_sage_roi_sync_settings', 'sage_roi_sync_settings' );
function sage_roi_sync_settings() {
    check_admin_referer( 'sage_roi_api_options_verify');

    sage_roi_set_option( 'stop_sync_items', isset($_POST[sage_roi_option_key('stop_sync_items')]) ? 1 : null );
    sage_roi_set_option( 'stop_sync_items_images', isset($_POST[sage_roi_option_key('stop_sync_items_images')]) ? 1 : null );
    sage_roi_set_option( 'stop_sync_customers', isset($_POST[sage_roi_option_key('stop_sync_customers')]) ? 1 : null );

    // resets
    if(isset($_POST[sage_roi_option_key('reset_item_sync')])) {
        sage_roi_set_option( 'products_page_number', 1 );
    }
    if(isset($_POST[sage_roi_option_key('reset_item_images_sync')])) {
        sage_roi_set_option( 'products_images_page_number', 1 );
    }
    if(isset($_POST[sage_roi_option_key('reset_customers_sync')])) {
        sage_roi_set_option( 'customers_page_number', 1 );
    }
    wp_redirect($_SERVER['HTTP_REFERER'] . '&settings=1');
}

add_action( 'admin_post_sage_roi_external_api', 'sage_roi_submit_api_key' );


function sage_roi_submit_api_key() {
    // Make sure user actually has the capability to edit the options
    if(!current_user_can( 'edit_theme_options' )){
      wp_die("You do not have permission to view this page.");
    }
    // pass in the nonce ID from our form's nonce field - if the nonce fails this will kill script
    check_admin_referer( 'sage_roi_api_options_verify');
    $data_encryption = new FSD_Data_Encryption();

    $apiCheck = sage_roi_token_auth( $_POST[sage_roi_option_key('client_id')], $_POST[sage_roi_option_key('client_secret')] );
    if(!isset($apiCheck->access_token)) {
        wp_redirect($_SERVER['HTTP_REFERER'] . '&status=0');
        return false;
    }

    // client ID
    if (isset($_POST[sage_roi_option_key('client_id')])) {
        $wpSageRoiClientId = sanitize_text_field( $_POST[sage_roi_option_key('client_id')] );
        $encryptedRoiClientId = $data_encryption->encrypt($wpSageRoiClientId);
        sage_roi_set_option( 'client_id', $encryptedRoiClientId );
    }

    // client secret
    if (isset($_POST[sage_roi_option_key('client_secret')])) {
        $wpSageRoiClientSecret = sanitize_text_field( $_POST[sage_roi_option_key('client_secret')] );
        $encryptedRoiClientSecret = $data_encryption->encrypt($wpSageRoiClientSecret);
        sage_roi_set_option( 'client_secret', $encryptedRoiClientSecret );
    }

    // for App password ID
    $appPassword = $data_encryption->encrypt(uniqid('app_id'));
    sage_roi_set_option( 'app_password_id', $appPassword );


    // for App Password Secret
    $appSecret = $data_encryption->encrypt(sha1(time()));
    sage_roi_set_option( 'app_password_secret', $appSecret );

    // access token of API
    $appToken = $data_encryption->encrypt($apiCheck->access_token);
    sage_roi_set_option( 'access_token', $appToken );

    // Redirect to same page with status=1 to show our options updated banner
    wp_redirect($_SERVER['HTTP_REFERER'] . '&status=1');
}


// variation tab
add_action( 'woocommerce_variation_options_pricing', 'sage_roi_add_variation_setting_fields', 10, 3 );
function sage_roi_add_variation_setting_fields( $loop, $variation_data, $variation ) {
    $field_key = sage_roi_option_key('number_of_units_package');
    woocommerce_wp_text_input( array(
        'id'            => $field_key.'['.$loop.']',
        'label'         => __('No. of units package', 'woocommerce'),
        'wrapper_class' => 'form-row',
        'description'   => __('The number of units or quantity for this package.', 'woocommerce'),
        'desc_tip'      => true,
        'value'         => get_post_meta($variation->ID, $field_key, true),
        'type'          => 'number'
    ) );
}

// Save the custom field from product variations
add_action('woocommerce_admin_process_variation_object', 'sage_roi_save_variation_setting_fields', 10, 2 );
function sage_roi_save_variation_setting_fields($variation, $i) {
    $field_key = sage_roi_option_key('number_of_units_package');
    if ( isset($_POST[$field_key][$i]) ) {
        sage_roi_meta_upsert( 
            'post', 
            $variation->get_id(), 
            'number_of_units_package', 
            sanitize_text_field($_POST[$field_key][$i])
        );
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


// sage roi tab, product informations from API
add_filter( 'woocommerce_product_data_tabs', 'sage_roi_product_tab' );
function sage_roi_product_tab($tabs) {
    
    $tabs[sage_roi_option_key('product_data')] = array(
        'label'    => __( 'SAGE ROI', 'text-domain' ),
        'target'   => sage_roi_option_key('product_data'),
        'class'    => array( 'show_if_simple', 'show_if_variable' ),
    );

    $tabs[sage_roi_option_key('product_api_response')] = array(
        'label'    => __( 'SAGE ROI API Response', 'text-domain' ),
        'target'   => sage_roi_option_key('product_api_response'),
        'class'    => array( 'show_if_simple', 'show_if_variable' ),
    );

    return $tabs;
}

// Display the content
add_action( 'woocommerce_product_data_panels', 'sage_roi_product_data_tab_content' );
function sage_roi_product_data_tab_content() {
    global $post;
    // For SAGE ROI Product Setting
    echo '<div id="'.sage_roi_option_key('product_data').'" class="panel woocommerce_options_panel">
    <div class="options_group">';
    ## ---- Content Start ---- ##
    woocommerce_wp_text_input( array(
        'id'          => sage_roi_option_key('product_unit_per_package'),
        'value'       => get_post_meta( $post->ID, sage_roi_option_key('product_unit_per_package'), true ),
        'label'       => __('Units per package', 'woocommerce'),
        'placeholder' => '',
        'name'        => sage_roi_option_key('product_unit_per_package')
    ));
    ## ---- Content End  ---- ##
    echo '</div></div>';

    // FOR SAGE ROI API RESPONSE LOG
    $productJSON = get_post_meta( $post->ID, sage_roi_option_key( 'product_json'), true );
    echo '<div id="'.sage_roi_option_key('product_api_response').'" class="panel woocommerce_options_panel" style="padding: 10px;max-height: 600px; overflow-y:auto;">';
    ## ---- Content Start ---- ##
    echo "<h1>API Response from SAGE 100</h1>";
    echo "<pre>".json_encode(json_decode($productJSON), JSON_PRETTY_PRINT)."</pre>";
    ## ---- Content End  ---- ##
    echo '</div>';
}


add_action('woocommerce_process_product_meta', 'sage_roi_save_product_custom_fields');

function sage_roi_save_product_custom_fields($post_id)
{
    $unitsPerPackage = isset($_POST[sage_roi_option_key('product_unit_per_package')]) ? $_POST[sage_roi_option_key('product_unit_per_package')] : '';
    sage_roi_meta_upsert( 
        'post', 
        $post_id, 
        'product_unit_per_package', 
        $unitsPerPackage
    );
}


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