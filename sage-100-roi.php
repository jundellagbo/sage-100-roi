<?php
/**
 * Plugin Name: Sage API Connection to ROI Consulting
 * Plugin URI: mailto:jj@xooker.com
 * Description: Connect and sync ecommerce platform from SAGE 100 to Wordpress Woocommerce
 * Version: 1.0.0
 * Author: JJXooker
 * Author URI: mailto:jj@xooker.com
 * License: GPL-2.0+
 * Text Domain: sage-100-roi
 * Domain Path: /languages
 *
 */

  require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

 // reference https://fullstackdigital.io/blog/how-to-safely-store-api-keys-and-access-protected-external-apis-in-wordpress/

 require_once __DIR__ . '/FSD_Data_Encryption.php';

 require_once __DIR__ . '/asset.php';

 require_once __DIR__ . '/ajax.php';

 require_once __DIR__ . '/shortcode.php';

 require_once __DIR__ . '/force-login.php';

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

require_once __DIR__ . '/woocommerce/rest.php';

require_once __DIR__ . '/woocommerce/woocommerce.php';

require_once __DIR__ . '/woocommerce/customers.php';

require_once __DIR__ . '/woocommerce/images.php';

require_once __DIR__ . '/woocommerce/items.php';

require_once __DIR__ . '/woocommerce/orders.php';

require_once __DIR__ . '/woocommerce/cart.php';


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

    // if(!empty( $get_meta( $id, $metaKey, true ) )) {
    //     $update_meta( 
    //         $id, 
    //         $metaKey, 
    //         $data
    //     );
    // } else {
    //     $add_meta( 
    //         $id, 
    //         $metaKey, 
    //         $data, 
    //         true 
    //     );
    // }
    $update_meta( 
        $id, 
        $metaKey, 
        $data
    );
}


add_action( 'admin_post_sage_roi_itemcodes_sync', 'sage_roi_itemcodes_sync' );
function sage_roi_itemcodes_sync() {
    check_admin_referer( 'sage_roi_multiple_itemcodes_sync');

    $itemCodes = isset($_POST[sage_roi_option_key('item_codes_sync')]) ? $_POST[sage_roi_option_key('item_codes_sync')] : "";
    $itemCodes = array_map('trim', explode("\n", $itemCodes));

    if( count( $itemCodes )) {
       sage_roi_set_product_ids( $itemCodes );
       sage_roi_message_transient(array(
            "status" => "success",
            "message" => "Item Codes has been synced"
        ));

        return true;

    } else {
        sage_roi_message_transient(array(
            "status" => "error",
            "message" => "Please enter item codes."
        ));

        return true;
    }

    sage_roi_message_transient(array(
        "status" => "success",
        "message" => "Failed to sync item codes"
    ));

    return true;
}

add_action( 'admin_post_sage_roi_sync_settings', 'sage_roi_sync_settings' );
function sage_roi_sync_settings() {
    check_admin_referer( 'sage_roi_api_options_verify');

    sage_roi_set_option( 'stop_sync_items', isset($_POST[sage_roi_option_key('stop_sync_items')]) ? 1 : null );
    sage_roi_set_option( 'stop_sync_items_images', isset($_POST[sage_roi_option_key('stop_sync_items_images')]) ? 1 : null );
    sage_roi_set_option( 'stop_sync_customers', isset($_POST[sage_roi_option_key('stop_sync_customers')]) ? 1 : null );
    sage_roi_set_option( 'stop_sync_orders', isset($_POST[sage_roi_option_key('stop_sync_orders')]) ? 1 : null );
    sage_roi_set_option( 'stop_new_customer_email_notification', isset($_POST[sage_roi_option_key('stop_new_customer_email_notification')]) ? 1 : null );

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
    if(isset($_POST[sage_roi_option_key('reset_orders_sync')])) {
        sage_roi_set_option( 'orders_page_number', 1 );
    }

    sage_roi_message_transient(array(
        "status" => "success",
        "message" => "Settings has been saved, checked resets has been executed."
    ));
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
        sage_roi_message_transient(array(
            "status" => "error",
            "message" => "API Credentials verfication failed! No changes has been made."
        ));
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
    sage_roi_message_transient(array(
        "status" => "success",
        "message" => "API Credentials has been saved!"
    ));
}

function sage_roi_api_date( $date ) {
    if(!$date) {
        return null;
    }

    $date = new DateTime($date);
    return $date->format("Y-m-d H:i:s");
}


function sage_roi_message_transient( $args = array( "message" => "Error", "status" => "error", "redirect" => null ) ) {
    set_transient('sage_roi_message_transient' . get_current_user_id(), $args, 30);
    wp_redirect($_SERVER['HTTP_REFERER']);
}

function sage_roi_show_message_transient() {
    $transient = get_transient('sage_roi_message_transient' . get_current_user_id());
    if (!empty( $transient )):
    ?>
    <div class="notice notice-<?php echo $transient['status']; ?> is-dismissible" style="margin:0;margin-bottom:20px;">
      <p><?php echo $transient['message']; ?></p>
    </div>
    <?php
    delete_transient('sage_roi_message_transient' . get_current_user_id());
    endif;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
  'https://github.com/jundellagbo/sage-100-roi',
  __FILE__,
  'sage-100-roi'
);


if( defined( 'WP_CLI') && WP_CLI ) {
  require_once __DIR__ . '/cli.php';
}