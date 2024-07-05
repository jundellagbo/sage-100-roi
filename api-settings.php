<?php $fds = new FSD_Data_Encryption(); ?>

<style type="text/css">
.apisettinginput {
    display: block; 
    width: 100%; 
    max-width: 500px; 
    margin-bottom: 15px;
}
#wpfooter {
    position: relative!important;
}
.divider {
    border: 0;
    border-top: 1px solid #dcdcde;
    border-bottom: 1px solid #f6f7f7;
    margin: 20px 0;
}
#wpbody-content {
    float: none!important;
}
.label-checkbox {
    display: block;
    margin-bottom: 15px;
}
</style>
<div class="wrap"></div>
    <h2>Sage 100 ROI</h2>
    <p>The values of these credentials has been securely stored and encrypted by SSL. The APP ID and Secret will automatically reset everytime you execute the Save Credentials button.</p>
    <?php
        // Check if status is 1 which means a successful options save just happened
        if(isset($_GET['status']) && $_GET['status'] == 1): ?>
            <div class="notice notice-success inline" style="margin:0;margin-bottom:20px;">
                <p>API Credentials has been saved!</p>
            </div>
        <?php endif;
    ?>

    <?php
        // Check if status is 0 which means api credentials is invalid
        if(isset($_GET['settings']) && $_GET['settings'] == 1): ?>
            <div class="notice notice-success inline" style="margin:0;margin-bottom:20px;">
                <p>Settings has been saved, checked resets has been executed.</p>
            </div>
        <?php endif;
    ?>


    <?php
        // Check if status is 0 which means api credentials is invalid
        if(isset($_GET['status']) && $_GET['status'] == 0): ?>
            <div class="notice notice-error inline" style="margin:0;margin-bottom:20px;">
                <p>API Credentials verfication failed! No changes has been made.</p>
            </div>
        <?php endif;
    ?>

    <?php
        // Check if status is 0 which means api credentials is invalid
        $tokenStatusCode = sage_roi_token_validate();
        if($tokenStatusCode === 200): ?>
            <div class="notice notice-success inline" style="margin:0;margin-bottom:20px;">
                <p>API Token is valid with the response of 200.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-error inline" style="margin:0;margin-bottom:20px;">
                <p>API Token is invalid with the response of <?php echo $tokenStatusCode; ?>.</p>
            </div>
        <?php endif;
    ?>
    

    <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
        <!-- The nonce field is a security feature to avoid submissions from outside WP admin -->
        <?php wp_nonce_field( 'sage_roi_api_options_verify'); ?>
        <input type="password" name="<?php echo sage_roi_option_key('client_id'); ?>" value="<?php echo $fds->decrypt( sage_roi_get_option('client_id') ); ?>" placeholder="Enter Client ID" class="apisettinginput">
        <input type="password" name="<?php echo sage_roi_option_key('client_secret'); ?>" value="<?php echo $fds->decrypt( sage_roi_get_option('client_secret') ); ?>" placeholder="Enter Client Secret" class="apisettinginput">
        <input type="hidden" name="action" value="sage_roi_external_api">			 
        <input type="submit" name="submit" id="submit" class="update-button button button-primary" value="Save Credentials"  />
    </form> 
</div>

<div class="divider"></div>

<h2>Application Details</h2>
<p>APP ID: 
    <code>
        <?php if(isset($_GET['showSecret']) && $_GET['showSecret'] == 1):  ?>
            <?php echo $fds->decrypt(sage_roi_get_option('app_password_id')); ?>
        <?php else: ?>
            <?php echo str_repeat("*", 20); ?>
        <?php endif; ?>
    </code>
</p>
<p>APP Secret: 
    <code>
        <?php if(isset($_GET['showSecret']) && $_GET['showSecret'] == 1):  ?>
            <?php echo $fds->decrypt(sage_roi_get_option('app_password_secret')); ?>
        <?php else: ?>
            <?php echo str_repeat("*", 50); ?>
        <?php endif; ?>
    </code>
</p>
<p><a href="<?php echo $_SERVER['REQUEST_URI']; ?>&showSecret=1">Show App Details</a></p>


<div class="divider"></div>

<h2>Sync Settings</h2>

<form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
    <?php wp_nonce_field( 'sage_roi_api_options_verify'); ?>
    <input type="hidden" name="action" value="sage_roi_sync_settings">
    
    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_items'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_items'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_items'); ?>"
        <?php echo sage_roi_get_option('stop_sync_items') ? "checked" : ""; ?>
        >
        Stop Item Sync
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_items_images'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_items_images'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_items_images'); ?>"
        <?php echo sage_roi_get_option('stop_sync_items_images') ? "checked" : ""; ?>
        >
        Stop Item Images Sync
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_customers'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_customers'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_customers'); ?>"
        <?php echo sage_roi_get_option('stop_sync_customers') ? "checked" : ""; ?>
        >
        Stop Customers Sync
    </label>

    <h3>Resets</h3>
    <p>It will sync starts to first page</p>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_item_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_item_sync'); ?>" name="<?php echo sage_roi_option_key('reset_item_sync'); ?>">
        Reset Item Sync (current page to sync: <?php echo sage_roi_get_option('products_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>" name="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>">
        Reset Item Images Sync (current page to sync: <?php echo sage_roi_get_option('products_images_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_customers_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_customers_sync'); ?>" name="<?php echo sage_roi_option_key('reset_customers_sync'); ?>">
        Reset Customers Sync (current page to sync: <?php echo sage_roi_get_option('customers_page_number'); ?>)
    </label>

    <input type="submit" name="submit" id="submit" class="update-button button button-primary" style="margin-bottom: 5px;" value="Save and execute resets"  />  
</form> 


<div class="divider"></div>

<div>
    <label>Access Token</label>
    <input type="text" id="accessTokenValue" readonly value="<?php echo $fds->decrypt( sage_roi_get_option('access_token') ); ?>">
    <button onclick="copyAccessToken()" class="button button-primary">Copy access token</button>
</div>

<script type="text/javascript">
    // remove the success status after save.
    window.history.replaceState(null, '', window.location.href.replace(/(&status=0|&status=1|&showSecret=1|&settings=1)/g, ''));

    function copyAccessToken() {
        let copyGfGText = document.getElementById("accessTokenValue");
            copyGfGText.select();
            document.execCommand("copy");
            alert("Access token has been copied.");
    }
</script>