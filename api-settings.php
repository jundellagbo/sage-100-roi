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
        // Check if status is 0 which means api credentials is invalid
        $tokenStatusCode = sage_roi_token_validate();
        if($tokenStatusCode === 200): ?>
            <div class="notice notice-success inline" style="margin:0;margin-bottom:20px;">
                <p>API Token is valid with the response of 200.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-error inline" style="margin:0;margin-bottom:20px;">
                <p>API Token is invalid with the response of <?php echo esc_html( $tokenStatusCode ); ?>.</p>
            </div>
        <?php endif;
    ?>

    <?php sage_roi_show_message_transient(); ?>
    
    <h2>
        Currently Endpoint: <?php echo sage_roi_base_endpoint( "/v2" ); ?>
    </h2>

    <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
        <!-- The nonce field is a security feature to avoid submissions from outside WP admin -->
        <?php wp_nonce_field( 'sage_roi_api_options_verify'); ?>
        <h1>Microsoft Oauth Token URL</h1>
        <input type="text" name="<?php echo sage_roi_option_key('oauth_token_url'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('oauth_token_url') ) ); ?>" placeholder="Enter Microsoft Oauth Token URL" class="apisettinginput">
        <h2>Development Credentials</h2>
        <input type="password" name="<?php echo sage_roi_option_key('client_id'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_id') ) ); ?>" placeholder="Enter Client ID" class="apisettinginput">
        <input type="password" name="<?php echo sage_roi_option_key('client_secret'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_secret') ) ); ?>" placeholder="Enter Client Secret" class="apisettinginput">
        <input type="password" name="<?php echo sage_roi_option_key('client_scope'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_scope') ) ); ?>" placeholder="Enter Client Scope" class="apisettinginput">
        <h2>Production Credentials</h2>
        <input type="password" name="<?php echo sage_roi_option_key('client_id_production'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_id_production') ) ); ?>" placeholder="Enter Client ID" class="apisettinginput">
        <input type="password" name="<?php echo sage_roi_option_key('client_secret_production'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_secret_production') ) ); ?>" placeholder="Enter Client Secret" class="apisettinginput">
        <input type="password" name="<?php echo sage_roi_option_key('client_scope_production'); ?>" value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('client_scope_production') ) ); ?>" placeholder="Enter Client Scope" class="apisettinginput">
        <input type="checkbox" name="<?php echo sage_roi_option_key('use_production'); ?>" value="1" <?php echo sage_roi_get_option('use_production') ? "checked" : ""; ?>> Use Production Credentials
        <div style="margin-top: 10px;">
            <input type="hidden" name="action" value="sage_roi_external_api">			 
            <input type="submit" name="submit" id="submit" class="update-button button button-primary" value="Save Credentials"  />
        </div>
    </form> 
</div>


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

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_items_inprocess'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_items_inprocess'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_items_inprocess'); ?>"
        <?php echo sage_roi_get_option('stop_sync_items_inprocess') ? "checked" : ""; ?>
        >
        Stop Item In Process Sync
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

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_customers_inprocess'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_customers_inprocess'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_customers_inprocess'); ?>"
        <?php echo sage_roi_get_option('stop_sync_customers_inprocess') ? "checked" : ""; ?>
        >
        Stop Customers In Process Sync
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_new_customer_email_notification'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_new_customer_email_notification'); ?>" 
        name="<?php echo sage_roi_option_key('stop_new_customer_email_notification'); ?>"
        <?php echo sage_roi_get_option('stop_new_customer_email_notification') ? "checked" : ""; ?>
        >
        Stop Customers Email Notification
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('stop_sync_orders'); ?>">
        <input type="checkbox" 
        id="<?php echo sage_roi_option_key('stop_sync_orders'); ?>" 
        name="<?php echo sage_roi_option_key('stop_sync_orders'); ?>"
        <?php echo sage_roi_get_option('stop_sync_orders') ? "checked" : ""; ?>
        >
        Stop Orders Sync
    </label>

    <h3>Sage order JSON retention</h3>
    <p>Orders older than this drop their stored Sage payload the next time the database cleanup below runs. 0 keeps it forever.</p>

    <label for="<?php echo sage_roi_option_key('order_json_retention_days'); ?>">
        Days to keep
        <input type="number" min="0" step="1"
        id="<?php echo sage_roi_option_key('order_json_retention_days'); ?>"
        name="<?php echo sage_roi_option_key('order_json_retention_days'); ?>"
        value="<?php echo esc_attr( sage_roi_order_json_retention_days() ); ?>">
    </label>

    <h3>Resets</h3>
    <p>It will sync starts to first page</p>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_item_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_item_sync'); ?>" name="<?php echo sage_roi_option_key('reset_item_sync'); ?>">
        Reset Item Sync (current page to sync: <?php echo sage_roi_get_option('products_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_item_inprocess_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_item_inprocess_sync'); ?>" name="<?php echo sage_roi_option_key('reset_item_inprocess_sync'); ?>">
        Reset Item In Process Sync (current page to sync: <?php echo sage_roi_get_option('products_inprocess_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>" name="<?php echo sage_roi_option_key('reset_item_images_sync'); ?>">
        Reset Item Images Sync (current page to sync: <?php echo sage_roi_get_option('products_images_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_customers_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_customers_sync'); ?>" name="<?php echo sage_roi_option_key('reset_customers_sync'); ?>">
        Reset Customers Sync (current page to sync: <?php echo sage_roi_get_option('customers_page_number'); ?>)
    </label>

    <label class="label-checkbox" for="<?php echo sage_roi_option_key('reset_orders_sync'); ?>">
        <input type="checkbox" id="<?php echo sage_roi_option_key('reset_orders_sync'); ?>" name="<?php echo sage_roi_option_key('reset_orders_sync'); ?>">
        Reset Orders Sync (current page to sync: <?php echo sage_roi_get_option('orders_page_number'); ?>)
    </label>

    <input type="submit" name="submit" id="submit" class="update-button button button-primary" style="margin-bottom: 5px;" value="Save and execute resets"  />
</form>

<div class="divider"></div>

<?php
$prune_state   = sage_roi_prune_get_state();
$prune_running = sage_roi_prune_is_running();
$prune_sizes   = sage_roi_prune_table_sizes();
?>

<h2>Database cleanup</h2>
<p>
    Orders store the Sage API response. Only a couple of dozen fields are ever read back, so this rewrites
    the stored payloads down to those fields and removes duplicate rows left by older syncs. Nothing is lost
    that a Reset Orders Sync cannot restore — but take a database backup first.
</p>

<table class="widefat striped" style="max-width: 620px; margin-bottom: 15px;">
    <thead><tr><th>Table</th><th style="text-align:right;">Size on disk</th></tr></thead>
    <tbody>
    <?php foreach ( $prune_sizes as $prune_table => $prune_bytes ) : ?>
        <tr>
            <td><code><?php echo esc_html( $prune_table ); ?></code></td>
            <td style="text-align:right;"><?php echo esc_html( size_format( $prune_bytes ) ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ( $prune_state ) : ?>
    <?php
    $prune_total     = array_sum( (array) $prune_state['totals'] );
    $prune_done      = min( array_sum( (array) $prune_state['processed'] ), $prune_total );
    $prune_percent   = $prune_total > 0 ? (int) floor( $prune_done / $prune_total * 100 ) : 100;
    $prune_saved     = $prune_state['stats']['bytes_before'] - $prune_state['stats']['bytes_after'];
    $prune_phases    = array(
        'dedupe'    => 'Removing duplicate rows',
        'orders'    => 'Rewriting order payloads',
        'items'     => 'Rewriting line payloads',
        'retention' => 'Applying retention window',
        'done'      => 'Finished',
    );
    ?>
    <div class="notice notice-<?php echo $prune_running ? 'info' : 'success'; ?> inline" style="margin:0 0 15px;">
        <p style="margin:8px 0;">
            <strong>
                <?php
                if ( ! empty( $prune_state['cancelled'] ) ) {
                    echo 'Stopped';
                } elseif ( $prune_running ) {
                    echo esc_html( $prune_phases[ $prune_state['phase'] ] ?? $prune_state['phase'] ) . ' — ' . (int) $prune_percent . '%';
                } else {
                    echo $prune_state['dry_run'] ? 'Preview complete' : 'Cleanup complete';
                }
                ?>
            </strong>
            <?php if ( $prune_state['dry_run'] ) : ?>
                <em>(preview only — nothing was written)</em>
            <?php endif; ?>
        </p>
        <p style="margin:8px 0;">
            <?php
            printf(
                '%s duplicate row(s) · %s order payload(s) · %s line payload(s) · %s purged · <strong>%s</strong> reclaimed',
                esc_html( number_format_i18n( $prune_state['stats']['duplicates_removed'] ) ),
                esc_html( number_format_i18n( $prune_state['stats']['orders_slimmed'] ) ),
                esc_html( number_format_i18n( $prune_state['stats']['items_slimmed'] ) ),
                esc_html( number_format_i18n( $prune_state['stats']['purged_orders'] ) ),
                esc_html( size_format( max( 0, $prune_saved ) ) )
            );
            ?>
        </p>
        <?php if ( ! $prune_running && ! $prune_state['dry_run'] && $prune_saved > 0 ) : ?>
            <p style="margin:8px 0;">
                Freed space stays inside the table files until they are rebuilt. Ask your host to run
                <code>OPTIMIZE TABLE</code> on the tables above to return it to the disk.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST">
    <?php wp_nonce_field( 'sage_roi_prune_order_json' ); ?>
    <input type="hidden" name="action" value="sage_roi_prune_order_json">
    <?php if ( $prune_running ) : ?>
        <p>The cleanup is running in the background. Reload this page to update the progress above.</p>
        <button type="submit" name="sage_roi_prune_action" value="cancel" class="button">Stop cleanup</button>
    <?php else : ?>
        <button type="submit" name="sage_roi_prune_action" value="preview" class="button">Preview (no changes)</button>
        <button type="submit" name="sage_roi_prune_action" value="run" class="button button-primary"
            onclick="return confirm('Run the database cleanup now? Take a database backup first.');">Run cleanup</button>
    <?php endif; ?>
</form>

<div class="divider"></div>

<h3>Item codes sync</h3>
<p>It will immediately pull items.</p>

<form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
    <?php wp_nonce_field( 'sage_roi_multiple_itemcodes_sync'); ?>
    <input type="hidden" name="action" value="sage_roi_itemcodes_sync">
    <label class="label-checkbox" for="<?php echo sage_roi_option_key('item_codes_sync'); ?>">
        <textarea 
        placeholder="Enter multiple itemcodes to sync, separated by new line."
        id="<?php echo sage_roi_option_key('item_codes_sync'); ?>" 
        name="<?php echo sage_roi_option_key('item_codes_sync'); ?>"
        rows="10"
        style="width: 100%; max-width: 300px;"
        ></textarea>
    </label>
    <input type="submit" name="submit" id="submit" class="update-button button button-primary" style="margin-bottom: 5px;" value="Sync item codes"  />
</form>


<div class="divider"></div>

<div>
    <label>Access Token</label>
    <input type="text" id="accessTokenValue" readonly value="<?php echo esc_attr( $fds->decrypt( sage_roi_get_option('access_token') ) ); ?>">
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