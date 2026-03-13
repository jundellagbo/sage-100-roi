<?php

/**
 * Show Item to User - Product visibility by user (whitelist/blacklist)
 * UI similar to Linked Products > Upsells
 */

if ( ! function_exists( 'sage_roi_option_key' ) ) {
    return;
}

$show_item_tab_key = sage_roi_option_key( 'show_item_to_user' );

// Add tab above Sage ROI (priority 5 runs before default 10)
add_filter( 'woocommerce_product_data_tabs', 'sage_roi_show_item_to_user_tab', 5 );
function sage_roi_show_item_to_user_tab( $tabs ) {
    $key = sage_roi_option_key( 'show_item_to_user' );
    $new_tabs = array(
        $key => array(
            'label'  => __( 'Show Item to User', 'sage-100-roi' ),
            'target' => $key,
            'class'  => array( 'show_if_simple', 'show_if_variable' ),
        ),
    );
    return array_merge( $new_tabs, $tabs );
}

// Tab content
add_action( 'woocommerce_product_data_panels', 'sage_roi_show_item_to_user_tab_content', 5 );
function sage_roi_show_item_to_user_tab_content() {
    global $post;
    $key = sage_roi_option_key( 'show_item_to_user' );

    $show_only_to = get_post_meta( $post->ID, sage_roi_option_key( 'show_only_to_users' ), true );
    $hide_from    = get_post_meta( $post->ID, sage_roi_option_key( 'hide_from_users' ), true );

    if ( ! is_array( $show_only_to ) ) {
        $show_only_to = array();
    }
    if ( ! is_array( $hide_from ) ) {
        $hide_from = array();
    }
    ?>
    <div id="<?php echo esc_attr( $key ); ?>" class="panel woocommerce_options_panel">
        <div class="options_group">
            <p class="form-field">
                <label><?php esc_html_e( 'Show this product only to selected users', 'sage-100-roi' ); ?></label>
                <select
                    class="sage-customer-search sage-user-search"
                    multiple="multiple"
                    style="width: 50%;"
                    name="<?php echo esc_attr( sage_roi_option_key( 'show_only_to_users' ) ); ?>[]"
                    data-placeholder="<?php esc_attr_e( 'Search for a user&hellip;', 'sage-100-roi' ); ?>"
                    data-action="sage_roi_customer_search"
                    data-minimum-input-length="3"
                >
                    <?php
                    foreach ( $show_only_to as $uid ) {
                        $user = get_user_by( 'id', $uid );
                        if ( $user ) {
                            $label = trim( $user->first_name . ' ' . $user->last_name );
                            if ( empty( $label ) ) {
                                $label = $user->display_name;
                            }
                            echo '<option value="' . esc_attr( $uid ) . '" selected="selected">' . esc_html( $label ) . '</option>';
                        }
                    }
                    ?>
                </select>
                <span class="description"><?php esc_html_e( 'Leave empty to show to all users.', 'sage-100-roi' ); ?></span>
            </p>
            <p class="form-field">
                <label><?php esc_html_e( 'Hide this product from selected users', 'sage-100-roi' ); ?></label>
                <select
                    class="sage-customer-search sage-user-search"
                    multiple="multiple"
                    style="width: 50%;"
                    name="<?php echo esc_attr( sage_roi_option_key( 'hide_from_users' ) ); ?>[]"
                    data-placeholder="<?php esc_attr_e( 'Search for a user&hellip;', 'sage-100-roi' ); ?>"
                    data-action="sage_roi_customer_search"
                    data-minimum-input-length="3"
                >
                    <?php
                    foreach ( $hide_from as $uid ) {
                        $user = get_user_by( 'id', $uid );
                        if ( $user ) {
                            $label = trim( $user->first_name . ' ' . $user->last_name );
                            if ( empty( $label ) ) {
                                $label = $user->display_name;
                            }
                            echo '<option value="' . esc_attr( $uid ) . '" selected="selected">' . esc_html( $label ) . '</option>';
                        }
                    }
                    ?>
                </select>
                <span class="description"><?php esc_html_e( 'Product will be hidden from these users.', 'sage-100-roi' ); ?></span>
            </p>
        </div>
    </div>
    <?php
}

// Save
add_action( 'woocommerce_process_product_meta', 'sage_roi_save_show_item_to_user_fields', 15 );
function sage_roi_save_show_item_to_user_fields( $post_id ) {
    $show_only_to = isset( $_POST[ sage_roi_option_key( 'show_only_to_users' ) ] )
        ? array_map( 'absint', (array) $_POST[ sage_roi_option_key( 'show_only_to_users' ) ] )
        : array();
    $hide_from = isset( $_POST[ sage_roi_option_key( 'hide_from_users' ) ] )
        ? array_map( 'absint', (array) $_POST[ sage_roi_option_key( 'hide_from_users' ) ] )
        : array();

    sage_roi_meta_upsert( 'post', $post_id, 'show_only_to_users', array_filter( $show_only_to ) );
    sage_roi_meta_upsert( 'post', $post_id, 'hide_from_users', array_filter( $hide_from ) );
}
