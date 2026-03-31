<?php

/**
 * Order Dates - Custom post type for configurable delivery/order dates
 *
 * - Multiple dates per config, assignable to users
 * - Global option to repeat specific days per month (Settings page only)
 * - Global holidays (excluded from selection) and open weekdays (delivery + Sage OrderDate business-day math)
 * - Dropdown delivery options exclude dates whose derived Sage order date is before today + N open weekdays (N = Business Days Before Delivery)
 * - Cart + classic checkout: delivery date dropdown + settings notice (session + POST; UI inside #payment so AJAX refresh includes it)
 * - Elementor Pro Cart widget: inject before .wc-proceed-to-checkout when the WC hook does not run
 * - WooCommerce Cart block: same UI via assets/cart-delivery.js + AJAX (above Proceed to checkout)
 * - Block checkout: additional checkout field + assets/checkout-block-notice.js (notice under select in order fields)
 */

if ( ! function_exists( 'sage_roi_option_key' ) ) {
    return;
}

define( 'SAGE_ROI_ORDER_DATE_CPT', 'sage_roi_order_date' );
define( 'SAGE_ROI_ORDER_DATE_HOLIDAYS_OPTION', 'sage_roi_order_date_holidays' );
define( 'SAGE_ROI_ORDER_DATE_REPEAT_OPTION', 'sage_roi_order_date_repeat_days' );
define( 'SAGE_ROI_ORDER_DATE_CUTOFF_HOUR_OPTION', 'sage_roi_order_date_cutoff_hour' );
define( 'SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION', 'sage_roi_order_date_business_days_before' );
define( 'SAGE_ROI_ORDER_DATE_BUSINESS_WEEKDAYS_OPTION', 'sage_roi_order_date_business_weekdays' );
define( 'SAGE_ROI_ORDER_DATE_CART_NOTICE_OPTION', 'sage_roi_order_date_cart_notice_template' );
define( 'SAGE_ROI_ORDER_DATE_FIELD_ID', 'sage-100-roi/order-date' );

// --- Shared helpers (no secrets in defaults; customize under Order Dates > Settings) ---

function sage_roi_order_date_default_cart_notice_template() {
    return __( 'This order will be scheduled for delivery on {delivery_date}. You can choose another available date using the menu above.', 'sage-100-roi' );
}

function sage_roi_order_date_get_cart_notice_template_string() {
    $template = (string) get_option( SAGE_ROI_ORDER_DATE_CART_NOTICE_OPTION, '' );
    if ( trim( $template ) === '' ) {
        $template = sage_roi_order_date_default_cart_notice_template();
    }
    return $template;
}

function sage_roi_order_date_get_holidays_md() {
    $h = get_option( SAGE_ROI_ORDER_DATE_HOLIDAYS_OPTION, array() );
    if ( ! is_array( $h ) ) {
        return array();
    }
    return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $h ) ) ) );
}

function sage_roi_order_date_get_cutoff_hour() {
    $h = (int) get_option( SAGE_ROI_ORDER_DATE_CUTOFF_HOUR_OPTION, 10 );
    return ( $h < 0 || $h > 23 ) ? 10 : $h;
}

/**
 * Weekdays (1 = Mon … 7 = Sun, ISO-8601) allowed for delivery options and counted as “business” for Sage OrderDate.
 * Default all seven if unset or empty (backward compatible).
 *
 * @return int[]
 */
function sage_roi_order_date_get_business_weekdays() {
    $raw = get_option( SAGE_ROI_ORDER_DATE_BUSINESS_WEEKDAYS_OPTION, null );
    if ( ! is_array( $raw ) || empty( $raw ) ) {
        return range( 1, 7 );
    }
    $out = array();
    foreach ( $raw as $n ) {
        $n = (int) $n;
        if ( $n >= 1 && $n <= 7 ) {
            $out[ $n ] = $n;
        }
    }
    $out = array_values( $out );
    return ! empty( $out ) ? $out : range( 1, 7 );
}

function sage_roi_order_date_is_weekday_open( DateTime $dt ) {
    $n = (int) $dt->format( 'N' );
    return in_array( $n, sage_roi_order_date_get_business_weekdays(), true );
}

function sage_roi_order_date_format_notice_short_date( $ymd ) {
    $dt = DateTime::createFromFormat( 'Y-m-d', (string) $ymd );
    return $dt ? $dt->format( 'n/j/y' ) : (string) $ymd;
}

function sage_roi_order_date_sort_dates_asc( array $dates ) {
    $dates = array_values( array_unique( array_filter( array_map( 'strval', $dates ) ) ) );
    sort( $dates, SORT_STRING );
    return $dates;
}

function sage_roi_order_date_earliest_in_list( array $dates ) {
    $dates = sage_roi_order_date_sort_dates_asc( $dates );
    return ! empty( $dates ) ? $dates[0] : '';
}

/**
 * @param string[] $dates Y-m-d.
 * @return array<int,array{value:string,label:string}>
 */
function sage_roi_order_date_select_options_for_dates( array $dates ) {
    $fmt = get_option( 'date_format' );
    $out = array();
    foreach ( sage_roi_order_date_sort_dates_asc( $dates ) as $d ) {
        $dt = DateTime::createFromFormat( 'Y-m-d', $d );
        $out[] = array(
            'value' => $d,
            'label' => $dt ? $dt->format( $fmt ) : $d,
        );
    }
    return $out;
}

function sage_roi_order_date_session_or_earliest( array $dates ) {
    if ( empty( $dates ) ) {
        return '';
    }
    $session = WC()->session ? WC()->session->get( 'sage_roi_selected_delivery_date' ) : '';
    $session = is_string( $session ) ? sanitize_text_field( $session ) : '';
    if ( $session !== '' && in_array( $session, $dates, true ) ) {
        return $session;
    }
    return sage_roi_order_date_earliest_in_list( $dates );
}

function sage_roi_order_date_set_session_delivery( $date_ymd ) {
    if ( ! WC()->session ) {
        return;
    }
    $available = sage_roi_order_date_get_available_dates();
    if ( $date_ymd === '' ) {
        WC()->session->__unset( 'sage_roi_selected_delivery_date' );
        return;
    }
    if ( in_array( $date_ymd, $available, true ) ) {
        WC()->session->set( 'sage_roi_selected_delivery_date', $date_ymd );
    }
}

/**
 * @param WC_Order $order Order object.
 * @param string   $date_ymd Y-m-d.
 */
function sage_roi_order_date_save_order_delivery_meta( $order, $date_ymd ) {
    if ( ! $order || ! is_string( $date_ymd ) || $date_ymd === '' ) {
        return false;
    }
    $available = sage_roi_order_date_get_available_dates();
    if ( ! in_array( $date_ymd, $available, true ) ) {
        return false;
    }
    $order->update_meta_data( sage_roi_option_key( 'order_date' ), $date_ymd );
    $order->update_meta_data( sage_roi_option_key( 'sage_final_order_date' ), sage_roi_order_date_calculate_final_order_date( $date_ymd, null, 'Y-m-d' ) );
    $order->save();
    return true;
}

// --- Custom Post Type ---
add_action( 'init', 'sage_roi_order_date_register_cpt' );
function sage_roi_order_date_register_cpt() {
    register_post_type( SAGE_ROI_ORDER_DATE_CPT, array(
        'labels'       => array(
            'name'          => __( 'Order Dates', 'sage-100-roi' ),
            'singular_name' => __( 'Order Date', 'sage-100-roi' ),
            'add_new'      => __( 'Add New', 'sage-100-roi' ),
            'add_new_item'  => __( 'Add New Order Date', 'sage-100-roi' ),
            'edit_item'     => __( 'Edit Order Date', 'sage-100-roi' ),
        ),
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-calendar-alt',
        'menu_position'=> 56,
        'capability_type' => 'post',
        'supports'     => array( 'title' ),
    ) );
}

/**
 * Admin list table: add helpful columns for configured dates and assigned users.
 *
 * @param array<string,string> $columns Existing columns.
 * @return array<string,string>
 */
add_filter( 'manage_edit-' . SAGE_ROI_ORDER_DATE_CPT . '_columns', 'sage_roi_order_date_admin_columns' );
function sage_roi_order_date_admin_columns( $columns ) {
    $out = array();
    foreach ( (array) $columns as $key => $label ) {
        $out[ $key ] = $label;
        if ( 'title' === $key ) {
            $out['sage_roi_dates'] = __( 'Configured Dates', 'sage-100-roi' );
            $out['sage_roi_users'] = __( 'Users', 'sage-100-roi' );
        }
    }
    return $out;
}

/**
 * @param int $post_id Post ID.
 * @return string[] Normalized Y-m-d values.
 */
function sage_roi_order_date_get_post_dates_for_admin( $post_id ) {
    $items = get_post_meta( $post_id, 'sage_roi_order_date_dates', true );
    if ( ! is_array( $items ) ) {
        return array();
    }
    $dates = array();
    foreach ( $items as $item ) {
        $d = '';
        if ( is_array( $item ) && isset( $item['date'] ) ) {
            $d = (string) $item['date'];
        } elseif ( is_string( $item ) ) {
            $d = $item;
        }
        $d = trim( $d );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
            $dates[] = $d;
        }
    }
    return sage_roi_order_date_sort_dates_asc( $dates );
}

add_action( 'manage_' . SAGE_ROI_ORDER_DATE_CPT . '_posts_custom_column', 'sage_roi_order_date_admin_column_content', 10, 2 );
function sage_roi_order_date_admin_column_content( $column, $post_id ) {
    if ( 'sage_roi_dates' === $column ) {
        $dates = sage_roi_order_date_get_post_dates_for_admin( $post_id );
        if ( empty( $dates ) ) {
            echo '<span aria-hidden="true">&mdash;</span>';
            return;
        }
        $labels = array();
        foreach ( $dates as $d ) {
            $dt       = DateTime::createFromFormat( 'Y-m-d', $d );
            $labels[] = $dt ? $dt->format( 'm/d/Y' ) : $d;
        }
        echo esc_html( implode( ', ', $labels ) );
        return;
    }

    if ( 'sage_roi_users' === $column ) {
        $users = get_post_meta( $post_id, 'sage_roi_order_date_users', true );
        if ( ! is_array( $users ) || empty( $users ) ) {
            esc_html_e( 'All users', 'sage-100-roi' );
            return;
        }
        $names = array();
        foreach ( array_filter( array_map( 'absint', $users ) ) as $uid ) {
            $user = get_user_by( 'id', $uid );
            if ( ! $user ) {
                continue;
            }
            $first = is_string( $user->first_name ) ? trim( $user->first_name ) : '';
            $last  = is_string( $user->last_name ) ? trim( $user->last_name ) : '';
            if ( '-' === $last ) {
                $last = '';
            }
            $label = trim( $first . ' ' . $last );
            if ( '' === $label ) {
                $label = $user->display_name;
            }
            if ( '' !== $label ) {
                $names[] = $label;
            }
        }
        if ( empty( $names ) ) {
            echo '<span aria-hidden="true">&mdash;</span>';
            return;
        }
        echo esc_html( implode( ', ', $names ) );
    }
}

// --- Meta box: Dates (append/remove) ---
add_action( 'add_meta_boxes', 'sage_roi_order_date_meta_boxes' );
function sage_roi_order_date_meta_boxes() {
    add_meta_box(
        'sage_roi_order_date_dates',
        __( 'Order Dates', 'sage-100-roi' ),
        'sage_roi_order_date_dates_meta_box',
        SAGE_ROI_ORDER_DATE_CPT,
        'normal'
    );
    add_meta_box(
        'sage_roi_order_date_users',
        __( 'Applicable Users / Customers', 'sage-100-roi' ),
        'sage_roi_order_date_users_meta_box',
        SAGE_ROI_ORDER_DATE_CPT,
        'normal'
    );
}

function sage_roi_order_date_dates_meta_box( $post ) {
    $items = get_post_meta( $post->ID, 'sage_roi_order_date_dates', true );
    if ( ! is_array( $items ) ) {
        $items = array();
    }
    $entries = array();
    foreach ( $items as $item ) {
        if ( is_array( $item ) && isset( $item['date'] ) ) {
            $entries[] = array( 'date' => $item['date'] );
        } elseif ( is_string( $item ) ) {
            $entries[] = array( 'date' => $item );
        }
    }
    $holidays_md = sage_roi_order_date_get_holidays_md();
    wp_nonce_field( 'sage_roi_order_date_save', 'sage_roi_order_date_nonce' );
    $open_wd = sage_roi_order_date_get_business_weekdays();
    ?>
    <p class="description"><?php esc_html_e( 'Add specific dates for this configuration. Monthly repeat is managed in Order Dates > Settings.', 'sage-100-roi' ); ?></p>
    <p class="description"><?php esc_html_e( 'Dates matching global holidays are disabled.', 'sage-100-roi' ); ?></p>
    <input type="hidden" id="sage-roi-holidays-md" value="<?php echo esc_attr( wp_json_encode( $holidays_md ) ); ?>" />
    <input type="hidden" id="sage-roi-open-weekdays" value="<?php echo esc_attr( wp_json_encode( $open_wd ) ); ?>" />
    <div id="sage-roi-order-dates-list">
        <?php foreach ( $entries as $e ) : ?>
            <p class="sage-roi-date-row">
                <input type="date" name="sage_roi_order_date_dates[][date]" value="<?php echo esc_attr( $e['date'] ); ?>" />
                <button type="button" class="button sage-roi-remove-date"><?php esc_html_e( 'Remove', 'sage-100-roi' ); ?></button>
            </p>
        <?php endforeach; ?>
    </div>
    <p><button type="button" class="button sage-roi-add-date"><?php esc_html_e( '+ Add Date', 'sage-100-roi' ); ?></button></p>
    <template id="sage-roi-date-row-tpl">
        <p class="sage-roi-date-row">
            <input type="date" name="sage_roi_order_date_dates[][date]" value="" />
            <button type="button" class="button sage-roi-remove-date"><?php esc_html_e( 'Remove', 'sage-100-roi' ); ?></button>
        </p>
    </template>
    <?php
}

function sage_roi_order_date_users_meta_box( $post ) {
    $users = get_post_meta( $post->ID, 'sage_roi_order_date_users', true );
    if ( ! is_array( $users ) ) {
        $users = array();
    }
    ?>
    <select
        class="sage-customer-search sage-user-search"
        multiple="multiple"
        style="width: 100%; max-width: 400px;"
        name="sage_roi_order_date_users[]"
        data-placeholder="<?php esc_attr_e( 'Search for a user&hellip;', 'sage-100-roi' ); ?>"
        data-action="sage_roi_customer_search"
        data-minimum-input-length="3"
    >
        <?php foreach ( $users as $uid ) :
            $user = get_user_by( 'id', $uid );
            if ( $user ) :
                $first = is_string( $user->first_name ) ? trim( $user->first_name ) : '';
                $last  = is_string( $user->last_name ) ? trim( $user->last_name ) : '';
                if ( '-' === $last ) {
                    $last = '';
                }
                $label = trim( $first . ' ' . $last );
                if ( empty( $label ) ) { $label = $user->display_name; }
        ?>
            <option value="<?php echo esc_attr( $uid ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
        <?php endif; endforeach; ?>
    </select>
    <p class="description"><?php esc_html_e( 'Leave empty to apply to all users.', 'sage-100-roi' ); ?></p>
    <?php
}

// --- Order Dates > Settings submenu ---
add_action( 'admin_menu', 'sage_roi_order_date_settings_menu', 20 );
function sage_roi_order_date_settings_menu() {
    add_submenu_page(
        'edit.php?post_type=' . SAGE_ROI_ORDER_DATE_CPT,
        __( 'Settings', 'sage-100-roi' ),
        __( 'Settings', 'sage-100-roi' ),
        'manage_options',
        'sage-roi-order-date-settings',
        'sage_roi_order_date_settings_page'
    );
}

function sage_roi_order_date_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $repeat = get_option( SAGE_ROI_ORDER_DATE_REPEAT_OPTION, array() );
    if ( ! is_array( $repeat ) ) $repeat = array();
    $cutoff_hour = sage_roi_order_date_get_cutoff_hour();
    $business_days = (int) get_option( SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION, 1 );
    if ( $business_days < 0 ) {
        $business_days = 1;
    }
    $cart_notice_template = (string) get_option( SAGE_ROI_ORDER_DATE_CART_NOTICE_OPTION, '' );
    if ( $cart_notice_template === '' ) {
        $cart_notice_template = sage_roi_order_date_default_cart_notice_template();
    }

    $holidays = get_option( SAGE_ROI_ORDER_DATE_HOLIDAYS_OPTION, array() );
    if ( ! is_array( $holidays ) ) $holidays = array();
    $holidays = array_values( array_unique( array_filter( $holidays ) ) );
    $holidays_display = array();
    foreach ( $holidays as $md ) {
        $holidays_display[] = strlen( $md ) === 5 ? date( 'Y' ) . '-' . $md : $md;
    }

    if ( isset( $_POST['sage_roi_order_date_settings_nonce'] ) && wp_verify_nonce( $_POST['sage_roi_order_date_settings_nonce'], 'sage_roi_order_date_settings_save' ) ) {
        $repeat_post = isset( $_POST['sage_roi_order_date_repeat_days'] ) ? array_map( 'absint', (array) $_POST['sage_roi_order_date_repeat_days'] ) : array();
        $repeat_post = array_values( array_filter( $repeat_post, function( $d ) { return $d >= 1 && $d <= 31; } ) );
        update_option( SAGE_ROI_ORDER_DATE_REPEAT_OPTION, $repeat_post );
        $cutoff_post = isset( $_POST['sage_roi_order_date_cutoff_hour'] ) ? (int) $_POST['sage_roi_order_date_cutoff_hour'] : 10;
        if ( $cutoff_post < 0 || $cutoff_post > 23 ) {
            $cutoff_post = 10;
        }
        update_option( SAGE_ROI_ORDER_DATE_CUTOFF_HOUR_OPTION, $cutoff_post );
        $weekdays_post = isset( $_POST['sage_roi_order_date_business_weekdays'] ) ? array_map( 'absint', (array) $_POST['sage_roi_order_date_business_weekdays'] ) : array();
        $weekdays_post = array_values( array_unique( array_filter( $weekdays_post, function ( $x ) {
            return $x >= 1 && $x <= 7;
        } ) ) );
        if ( empty( $weekdays_post ) ) {
            $weekdays_post = range( 1, 7 );
        }
        update_option( SAGE_ROI_ORDER_DATE_BUSINESS_WEEKDAYS_OPTION, $weekdays_post );
        $business_days_post = isset( $_POST['sage_roi_order_date_business_days_before'] ) ? (int) $_POST['sage_roi_order_date_business_days_before'] : 1;
        if ( $business_days_post < 0 ) {
            $business_days_post = 1;
        }
        update_option( SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION, $business_days_post );
        $cart_notice_post = isset( $_POST['sage_roi_order_date_cart_notice_template'] ) ? wp_kses_post( wp_unslash( $_POST['sage_roi_order_date_cart_notice_template'] ) ) : '';
        update_option( SAGE_ROI_ORDER_DATE_CART_NOTICE_OPTION, $cart_notice_post );

        $holidays_post = isset( $_POST['sage_roi_order_date_holidays'] ) ? (array) $_POST['sage_roi_order_date_holidays'] : array();
        $holidays_save = array();
        foreach ( array_filter( array_map( 'sanitize_text_field', $holidays_post ) ) as $ymd ) {
            $d = DateTime::createFromFormat( 'Y-m-d', $ymd );
            if ( $d ) {
                $holidays_save[] = $d->format( 'm-d' );
            }
        }
        update_option( SAGE_ROI_ORDER_DATE_HOLIDAYS_OPTION, array_unique( $holidays_save ) );
        $repeat = $repeat_post;
        $cutoff_hour = $cutoff_post;
        $business_days = $business_days_post;
        $cart_notice_template = $cart_notice_post;
        $holidays = $holidays_save;
        $holidays_display = array();
        foreach ( $holidays as $md ) {
            $holidays_display[] = date( 'Y' ) . '-' . $md;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'sage-100-roi' ) . '</p></div>';
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Order Dates Settings', 'sage-100-roi' ); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'sage_roi_order_date_settings_save', 'sage_roi_order_date_settings_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Repeat Days Per Month', 'sage-100-roi' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'Check days to repeat every month (e.g., 1st, 15th). These dates are available to all users with matching Order Date configs.', 'sage-100-roi' ); ?></p>
                        <div style="display: grid; grid-template-columns: repeat(10, 1fr); gap: 6px 12px; max-width: 500px;">
                            <?php for ( $d = 1; $d <= 31; $d++ ) : ?>
                                <label><input type="checkbox" name="sage_roi_order_date_repeat_days[]" value="<?php echo (int) $d; ?>" <?php checked( in_array( (int) $d, $repeat, true ) ); ?> /> <?php echo (int) $d; ?></label>
                            <?php endfor; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Next-Day Cutoff Hour', 'sage-100-roi' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'After this hour (0-23, site timezone), tomorrow delivery is hidden.', 'sage-100-roi' ); ?></p>
                        <input type="number" min="0" max="23" step="1" name="sage_roi_order_date_cutoff_hour" value="<?php echo (int) $cutoff_hour; ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Open weekdays', 'sage-100-roi' ); ?></th>
                    <td>
                        <div style="display:flex;flex-wrap:wrap;gap:12px 16px;max-width:640px;">
                            <?php
                            $wd_labels = array(
                                1 => __( 'Monday', 'woocommerce' ),
                                2 => __( 'Tuesday', 'woocommerce' ),
                                3 => __( 'Wednesday', 'woocommerce' ),
                                4 => __( 'Thursday', 'woocommerce' ),
                                5 => __( 'Friday', 'woocommerce' ),
                                6 => __( 'Saturday', 'woocommerce' ),
                                7 => __( 'Sunday', 'woocommerce' ),
                            );
                            $wd_checked = sage_roi_order_date_get_business_weekdays();
                            foreach ( $wd_labels as $num => $lab ) :
                                ?>
                                <label><input type="checkbox" name="sage_roi_order_date_business_weekdays[]" value="<?php echo (int) $num; ?>" <?php checked( in_array( (int) $num, $wd_checked, true ) ); ?> /> <?php echo esc_html( $lab ); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Business Days Before Delivery', 'sage-100-roi' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'How many open weekdays (see above) to count backward from the customer delivery date when calculating the Sage Order Date.', 'sage-100-roi' ); ?></p>
                        <input type="number" min="0" step="1" name="sage_roi_order_date_business_days_before" value="<?php echo (int) $business_days; ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cart Notice Template', 'sage-100-roi' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'Shown above Proceed to Checkout. Use {delivery_date} placeholder. HTML is allowed (sanitized on save).', 'sage-100-roi' ); ?></p>
                        <textarea name="sage_roi_order_date_cart_notice_template" rows="6" style="min-width: 650px; max-width: 100%;"><?php echo esc_textarea( $cart_notice_template ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Holidays (Global)', 'sage-100-roi' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'Dates disabled from selection. Repeats every year (e.g., Dec 25 applies annually).', 'sage-100-roi' ); ?></p>
                        <div id="sage-roi-holidays-list">
                            <?php foreach ( $holidays_display as $d ) : ?>
                                <p class="sage-roi-holiday-row"><input type="date" name="sage_roi_order_date_holidays[]" value="<?php echo esc_attr( $d ); ?>" /> <button type="button" class="button sage-roi-remove-holiday"><?php esc_html_e( 'Remove', 'sage-100-roi' ); ?></button></p>
                            <?php endforeach; ?>
                        </div>
                        <p><button type="button" class="button sage-roi-add-holiday"><?php esc_html_e( '+ Add Holiday', 'sage-100-roi' ); ?></button></p>
                        <template id="sage-roi-holiday-row-tpl">
                            <p class="sage-roi-holiday-row"><input type="date" name="sage_roi_order_date_holidays[]" value="" /> <button type="button" class="button sage-roi-remove-holiday"><?php esc_html_e( 'Remove', 'sage-100-roi' ); ?></button></p>
                        </template>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'sage-100-roi' ); ?>"></p>
        </form>
    </div>
    <?php
}

add_action( 'admin_footer', 'sage_roi_order_date_settings_footer_scripts' );
function sage_roi_order_date_settings_footer_scripts() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sage-roi-order-date-settings' ) return;
    ?>
    <script>
    (function($){
        $(function(){
            $('#sage-roi-holidays-list').on('click', '.sage-roi-remove-holiday', function(){
                $(this).closest('.sage-roi-holiday-row').remove();
            });
            $('.sage-roi-add-holiday').on('click', function(){
                var tpl = document.getElementById('sage-roi-holiday-row-tpl');
                if (tpl && tpl.content) {
                    $('#sage-roi-holidays-list').append(tpl.content.cloneNode(true));
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}

// --- Save ---
add_action( 'save_post_' . SAGE_ROI_ORDER_DATE_CPT, 'sage_roi_order_date_save', 10, 2 );
function sage_roi_order_date_save( $post_id, $post ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['sage_roi_order_date_nonce'] ) || ! wp_verify_nonce( $_POST['sage_roi_order_date_nonce'], 'sage_roi_order_date_save' ) ) {
        return;
    }

    $holidays_md = sage_roi_order_date_get_holidays_md();

    $dates_raw = isset( $_POST['sage_roi_order_date_dates'] ) ? (array) $_POST['sage_roi_order_date_dates'] : array();
    $dates_save = array();
    foreach ( $dates_raw as $row ) {
        $date = isset( $row['date'] ) ? sanitize_text_field( $row['date'] ) : '';
        if ( empty( $date ) ) continue;
        $dt = DateTime::createFromFormat( 'Y-m-d', $date );
        if ( ! $dt ) continue;
        if ( in_array( $dt->format( 'm-d' ), $holidays_md, true ) ) {
            continue;
        }
        if ( ! sage_roi_order_date_is_weekday_open( $dt ) ) {
            continue;
        }
        $dates_save[] = array( 'date' => $date );
    }
    update_post_meta( $post_id, 'sage_roi_order_date_dates', $dates_save );

    $users = isset( $_POST['sage_roi_order_date_users'] ) ? array_map( 'absint', (array) $_POST['sage_roi_order_date_users'] ) : array();
    $users = array_filter( $users );
    update_post_meta( $post_id, 'sage_roi_order_date_users', $users );
}

// --- Admin scripts for add/remove dates ---
add_action( 'admin_footer-post.php', 'sage_roi_order_date_admin_scripts' );
add_action( 'admin_footer-post-new.php', 'sage_roi_order_date_admin_scripts' );
function sage_roi_order_date_admin_scripts() {
    global $post;
    if ( ! $post || $post->post_type !== SAGE_ROI_ORDER_DATE_CPT ) return;
    ?>
    <script>
    (function($){
        $(function(){
            function getHolidaysMd() {
                var raw = $('#sage-roi-holidays-md').val();
                if (!raw) return [];
                try {
                    var parsed = JSON.parse(raw);
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }

            function mmdd(dateStr) {
                if (!dateStr || dateStr.length !== 10) return '';
                return dateStr.substring(5);
            }

            function isHoliday(dateStr, holidaysMd) {
                var key = mmdd(dateStr);
                return !!key && holidaysMd.indexOf(key) !== -1;
            }

            function getOpenWeekdays() {
                var raw = $('#sage-roi-open-weekdays').val();
                if (!raw) return [1,2,3,4,5,6,7];
                try {
                    var p = JSON.parse(raw);
                    return Array.isArray(p) && p.length ? p : [1,2,3,4,5,6,7];
                } catch (e) {
                    return [1,2,3,4,5,6,7];
                }
            }

            function isoWeekdayFromYmd(ymd) {
                if (!ymd || ymd.length !== 10) return 0;
                var t = ymd.split('-');
                var d = new Date(parseInt(t[0], 10), parseInt(t[1], 10) - 1, parseInt(t[2], 10));
                var j = d.getDay();
                return j === 0 ? 7 : j;
            }

            function isWeekdayOpen(ymd, openList) {
                var n = isoWeekdayFromYmd(ymd);
                return n && openList.indexOf(n) !== -1;
            }

            function applyHolidayValidation($input, holidaysMd) {
                $input.attr('title', '<?php echo esc_js( __( 'This date is blocked by global holidays.', 'sage-100-roi' ) ); ?>');
                $input.off('change.sageRoiHoliday').on('change.sageRoiHoliday', function(){
                    var val = $(this).val();
                    if (isHoliday(val, holidaysMd)) {
                        alert('<?php echo esc_js( __( 'This date is in Global Holidays. Please choose another date.', 'sage-100-roi' ) ); ?>');
                        $(this).val('');
                    }
                });
            }

            function applyOpenWeekdayValidation($input, openList) {
                $input.off('change.sageRoiOpenWd').on('change.sageRoiOpenWd', function(){
                    var val = $(this).val();
                    if (val && !isWeekdayOpen(val, openList)) {
                        alert('<?php echo esc_js( __( 'This weekday is not enabled under Order Dates > Settings (open weekdays).', 'sage-100-roi' ) ); ?>');
                        $(this).val('');
                    }
                });
            }

            var holidaysMd = getHolidaysMd();
            var openWd = getOpenWeekdays();
            $('#sage-roi-order-dates-list input[type="date"]').each(function(){
                applyHolidayValidation($(this), holidaysMd);
                applyOpenWeekdayValidation($(this), openWd);
            });

            $('#sage-roi-order-dates-list').on('click', '.sage-roi-remove-date', function(){
                $(this).closest('.sage-roi-date-row').remove();
            });
            $('.sage-roi-add-date').on('click', function(){
                var tpl = document.getElementById('sage-roi-date-row-tpl');
                if (tpl && tpl.content) {
                    var frag = $(tpl.content.cloneNode(true));
                    $('#sage-roi-order-dates-list').append(frag);
                    frag.find('input[type="date"]').each(function(){
                        applyHolidayValidation($(this), holidaysMd);
                        applyOpenWeekdayValidation($(this), openWd);
                    });
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}

// --- Get eligible dates for current user ---
function sage_roi_order_date_get_available_dates() {
    $userId = get_current_user_id();
    if ( ! $userId ) return array();

    $holidays_md = sage_roi_order_date_get_holidays_md();

    $posts = get_posts( array(
        'post_type'   => SAGE_ROI_ORDER_DATE_CPT,
        'post_status' => 'publish',
        'numberposts' => -1,
    ) );

    $all_dates = array();
    $now = new DateTime( 'now', wp_timezone() );
    $today = clone $now;
    $today->setTime( 0, 0, 0 );
    $cutoff_hour = sage_roi_order_date_get_cutoff_hour();

    foreach ( $posts as $p ) {
        $users = get_post_meta( $p->ID, 'sage_roi_order_date_users', true );
        if ( ! is_array( $users ) ) $users = array();
        if ( ! empty( $users ) && ! in_array( $userId, array_map( 'intval', $users ), true ) ) {
            continue;
        }

        $items = get_post_meta( $p->ID, 'sage_roi_order_date_dates', true );
        if ( ! is_array( $items ) ) $items = array();

        foreach ( $items as $item ) {
            $d = is_array( $item ) && isset( $item['date'] ) ? $item['date'] : ( is_string( $item ) ? $item : '' );
            if ( empty( $d ) ) continue;

            $dt = DateTime::createFromFormat( 'Y-m-d', $d );
            if ( ! $dt ) continue;
            $dt->setTime( 0, 0, 0 );

            $md = $dt->format( 'm-d' );
            if ( ! in_array( $md, $holidays_md, true ) && sage_roi_order_date_is_date_selectable( $dt, $now, $cutoff_hour ) && sage_roi_order_date_is_weekday_open( $dt ) ) {
                $all_dates[ $d ] = $d;
            }
        }
    }

    $repeat = get_option( SAGE_ROI_ORDER_DATE_REPEAT_OPTION, array() );
    if ( is_array( $repeat ) && ! empty( $repeat ) ) {
        foreach ( $repeat as $day ) {
            $day = (int) $day;
            if ( $day < 1 || $day > 31 ) continue;
            for ( $m = 0; $m < 12; $m++ ) {
                $check = clone $today;
                $check->modify( "+{$m} months" );
                $max = (int) $check->format( 't' );
                if ( $day <= $max ) {
                    $check->setDate( (int) $check->format( 'Y' ), (int) $check->format( 'm' ), $day );
                    $str = $check->format( 'Y-m-d' );
                    $md = $check->format( 'm-d' );
                    if ( ! in_array( $md, $holidays_md, true ) && sage_roi_order_date_is_date_selectable( $check, $now, $cutoff_hour ) && sage_roi_order_date_is_weekday_open( $check ) ) {
                        $all_dates[ $str ] = $str;
                    }
                }
            }
        }
    }

    $sorted = sage_roi_order_date_sort_dates_asc( array_values( $all_dates ) );
    return sage_roi_order_date_filter_delivery_dates_for_dropdown( $sorted, $now );
}

function sage_roi_order_date_is_date_selectable( DateTime $candidate, DateTime $now, $cutoff_hour = 10 ) {
    $candidate_day = clone $candidate;
    $candidate_day->setTime( 0, 0, 0 );
    $today = clone $now;
    $today->setTime( 0, 0, 0 );
    if ( $candidate_day < $today ) {
        return false;
    }

    $tomorrow = clone $today;
    $tomorrow->modify( '+1 day' );
    if ( $candidate_day->format( 'Y-m-d' ) === $tomorrow->format( 'Y-m-d' ) ) {
        $cutoff = clone $today;
        $cutoff->setTime( (int) $cutoff_hour, 0, 0 );
        if ( $now >= $cutoff ) {
            return false;
        }
    }

    return true;
}

function sage_roi_order_date_calculate_final_order_date( $delivery_date, $business_days_before = null, $format = 'Y-m-d' ) {
    $delivery_dt = DateTime::createFromFormat( 'Y-m-d', (string) $delivery_date, wp_timezone() );
    if ( ! $delivery_dt ) {
        return '';
    }
    $delivery_dt->setTime( 0, 0, 0 );

    if ( null === $business_days_before ) {
        $business_days_before = (int) get_option( SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION, 1 );
    }
    $business_days_before = max( 0, (int) $business_days_before );

    $open = sage_roi_order_date_get_business_weekdays();

    while ( $business_days_before > 0 ) {
        $delivery_dt->modify( '-1 day' );
        $weekday = (int) $delivery_dt->format( 'N' );
        if ( in_array( $weekday, $open, true ) ) {
            $business_days_before--;
        }
    }

    $guard = 0;
    while ( ! in_array( (int) $delivery_dt->format( 'N' ), $open, true ) && $guard < 400 ) {
        $delivery_dt->modify( '-1 day' );
        $guard++;
    }

    return $delivery_dt->format( $format );
}

/**
 * Advance from a calendar day by N open weekdays (forward; same open-weekday list as {@see sage_roi_order_date_calculate_final_order_date()}).
 *
 * @param DateTime $from Midnight-normalized start day.
 * @param int      $n    Business days to add (0 = same day).
 */
function sage_roi_order_date_step_open_weekdays_forward( DateTime $from, $n ) {
    $open = sage_roi_order_date_get_business_weekdays();
    $n    = max( 0, (int) $n );
    $dt   = clone $from;
    $dt->setTime( 0, 0, 0 );
    $added = 0;
    $guard = 0;
    while ( $added < $n && $guard < 400 ) {
        $dt->modify( '+1 day' );
        if ( in_array( (int) $dt->format( 'N' ), $open, true ) ) {
            $added++;
        }
        $guard++;
    }
    return $dt;
}

/**
 * Keep delivery Y-m-d values whose Sage order date (from business-days-before rules) is on/after
 * “today + N” open weekdays (N = {@see SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION}). Used once at the end of {@see sage_roi_order_date_get_available_dates()}.
 *
 * @param string[] $dates Candidate Y-m-d strings.
 * @param DateTime $now   Same clock as used when building $dates.
 * @return string[]
 */
function sage_roi_order_date_filter_delivery_dates_for_dropdown( array $dates, DateTime $now ) {
    $n = max( 0, (int) get_option( SAGE_ROI_ORDER_DATE_BUSINESS_DAYS_OPTION, 1 ) );
    $today = clone $now;
    $today->setTime( 0, 0, 0 );
    $min_final = sage_roi_order_date_step_open_weekdays_forward( $today, $n )->format( 'Y-m-d' );

    $out = array();
    foreach ( $dates as $d ) {
        if ( ! is_string( $d ) || $d === '' ) {
            continue;
        }
        $final = sage_roi_order_date_calculate_final_order_date( $d, null, 'Y-m-d' );
        if ( $final !== '' && $final >= $min_final ) {
            $out[] = $d;
        }
    }
    return sage_roi_order_date_sort_dates_asc( $out );
}

/**
 * Resolve the customer-selected delivery date (Y-m-d) from order meta, block checkout fields, and session.
 *
 * @param \WC_Order $order Order object.
 * @return string Y-m-d or ''.
 */
function sage_roi_order_date_resolve_delivery_ymd_for_order( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return '';
    }
    $key = sage_roi_option_key( 'order_date' );
    $d   = $order->get_meta( $key );
    if ( is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $d ) ) ) {
        return trim( $d );
    }
    $cf = sage_roi_order_date_blocks_checkout_fields();
    if ( $cf ) {
        $v = $cf->get_field_from_object( SAGE_ROI_ORDER_DATE_FIELD_ID, $order, 'other' );
        if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $v ) ) ) {
            return trim( $v );
        }
    }
    // WooCommerce persists additional order fields as _wc_other/{field_id} (field id includes '/').
    $wc_other_full = '_wc_other/' . SAGE_ROI_ORDER_DATE_FIELD_ID;
    $v             = $order->get_meta( $wc_other_full );
    if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $v ) ) ) {
        return trim( $v );
    }
    $wc_other_legacy = '_wc_other/' . str_replace( '/', '_', SAGE_ROI_ORDER_DATE_FIELD_ID );
    $v               = $order->get_meta( $wc_other_legacy );
    if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $v ) ) ) {
        return trim( $v );
    }
    $v = $order->get_meta( SAGE_ROI_ORDER_DATE_FIELD_ID );
    if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $v ) ) ) {
        return trim( $v );
    }
    if ( WC()->session ) {
        $s = WC()->session->get( 'sage_roi_selected_delivery_date' );
        if ( is_string( $s ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $s ) ) ) {
            return trim( $s );
        }
    }
    return '';
}

/**
 * Single source for Sage sales order submit: `OrderDate` (Ymd), optional `sage_final_order_date` meta (Y-m-d), and resolved delivery (Y-m-d).
 *
 * Refreshes order meta, prefers stored `sage_final_order_date`, then computes from resolved delivery, else order-placed date.
 *
 * @param \WC_Order $order Order object.
 * @return array{ order_date_ymd: string, final_ymd: string, delivery_ymd: string } `order_date_ymd` is always eight digits; `final_ymd` is Y-m-d or ''.
 */
function sage_roi_order_date_get_sage_submit_order_date( $order ) {
    $out = array(
        'order_date_ymd' => '',
        'final_ymd'      => '',
        'delivery_ymd'   => '',
    );
    if ( ! $order instanceof WC_Order ) {
        $ts                   = time();
        $out['order_date_ymd'] = date( 'Ymd', $ts );
        $out['final_ymd']      = date( 'Y-m-d', $ts );
        return $out;
    }
    if ( is_callable( array( $order, 'read_meta_data' ) ) ) {
        $order->read_meta_data( true );
    }
    $out['delivery_ymd'] = sage_roi_order_date_resolve_delivery_ymd_for_order( $order );

    $final_meta = $order->get_meta( sage_roi_option_key( 'sage_final_order_date' ) );
    if ( is_string( $final_meta ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $final_meta ) ) ) {
        $final_hyphen          = trim( $final_meta );
        $out['final_ymd']      = $final_hyphen;
        $out['order_date_ymd'] = str_replace( '-', '', $final_hyphen );
        return $out;
    }

    if ( $out['delivery_ymd'] !== '' && function_exists( 'sage_roi_order_date_calculate_final_order_date' ) ) {
        $ymd = sage_roi_order_date_calculate_final_order_date( $out['delivery_ymd'], null, 'Ymd' );
        if ( is_string( $ymd ) && $ymd !== '' ) {
            $out['order_date_ymd'] = $ymd;
            $out['final_ymd']      = sage_roi_order_date_calculate_final_order_date( $out['delivery_ymd'], null, 'Y-m-d' );
            return $out;
        }
    }

    $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
    $out['order_date_ymd'] = date( 'Ymd', $ts );
    $out['final_ymd']      = date( 'Y-m-d', $ts );
    return $out;
}

function sage_roi_order_date_get_selected_delivery_date_for_cart_notice() {
    $available = sage_roi_order_date_get_available_dates();
    if ( empty( $available ) ) {
        return '';
    }
    return sage_roi_order_date_session_or_earliest( $available );
}

/**
 * Persist delivery date chosen on cart (POST).
 */
add_action( 'wp_loaded', 'sage_roi_order_date_handle_cart_delivery_post', 20 );
function sage_roi_order_date_handle_cart_delivery_post() {
    if ( ! isset( $_POST['sage_roi_cart_delivery_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sage_roi_cart_delivery_nonce'] ) ), 'sage_roi_cart_delivery' ) ) {
        return;
    }
    if ( ! is_cart() || ! is_user_logged_in() || ! WC()->session ) {
        return;
    }

    $date = isset( $_POST['sage_roi_order_date'] ) ? sanitize_text_field( wp_unslash( $_POST['sage_roi_order_date'] ) ) : '';
    sage_roi_order_date_set_session_delivery( $date );

    wp_safe_redirect( wc_get_cart_url() );
    exit;
}

/**
 * Plugin root (this file: woocommerce/customs/woo-order-date.php).
 */
function sage_roi_order_date_plugin_file() {
    return dirname( dirname( dirname( __FILE__ ) ) ) . '/sage-100-roi.php';
}

/**
 * Valid $selected if it appears in $dates; otherwise default to earliest available (no empty &lt;option&gt;).
 */
function sage_roi_order_date_normalize_selected_for_list( $selected, $dates ) {
    if ( empty( $dates ) ) {
        return '';
    }
    $dates = sage_roi_order_date_sort_dates_asc( $dates );
    if ( is_string( $selected ) && $selected !== '' && in_array( $selected, $dates, true ) ) {
        return $selected;
    }
    return sage_roi_order_date_earliest_in_list( $dates );
}

/**
 * Shared: delivery date &lt;select&gt; options (Y-m-d values, site date_format labels). No placeholder row. Earliest first.
 */
function sage_roi_order_date_echo_delivery_select( $selected, $dates ) {
    $selected = sage_roi_order_date_normalize_selected_for_list( $selected, $dates );
    foreach ( sage_roi_order_date_select_options_for_dates( $dates ) as $opt ) {
        echo '<option value="' . esc_attr( $opt['value'] ) . '"' . selected( $selected, $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
    }
}

/**
 * Cart delivery markup: POST form (classic / Elementor shortcode cart) or AJAX select (block cart mount).
 *
 * @param bool $ajax_mount No form; select uses AJAX to save session (for JS injection).
 */
function sage_roi_order_date_get_cart_delivery_section_html( $ajax_mount = false ) {
    if ( ! is_user_logged_in() ) {
        return '';
    }
    $dates = sage_roi_order_date_get_available_dates();
    if ( empty( $dates ) ) {
        return '';
    }

    $selected = WC()->session ? WC()->session->get( 'sage_roi_selected_delivery_date' ) : '';
    if ( $selected && ! in_array( $selected, $dates, true ) ) {
        $selected = '';
        if ( WC()->session ) {
            WC()->session->__unset( 'sage_roi_selected_delivery_date' );
        }
    }

    $select_id  = $ajax_mount ? 'sage_roi_order_date_cart_ajax' : 'sage_roi_order_date_cart';
    $select_cls = 'sage-roi-order-date-cart-select' . ( $ajax_mount ? ' sage-roi-order-date-cart-ajax' : '' );

    ob_start();
    echo '<div class="sage-roi-cart-delivery-wrap" style="margin:0 0 16px 0;"' . ( $ajax_mount ? ' data-sage-roi-ajax="1"' : '' ) . '>';
    if ( ! $ajax_mount ) {
        echo '<form method="post" class="sage-roi-cart-delivery-form" action="' . esc_url( wc_get_cart_url() ) . '">';
        wp_nonce_field( 'sage_roi_cart_delivery', 'sage_roi_cart_delivery_nonce' );
    }
    echo '<p class="form-row form-row-wide">';
    echo '<label for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Delivery date', 'sage-100-roi' ) . '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span></label>';
    echo '<select name="sage_roi_order_date" id="' . esc_attr( $select_id ) . '" class="' . esc_attr( $select_cls ) . '" style="max-width:100%;"' . ( $ajax_mount ? '' : ' onchange="this.form.submit();"' ) . '>';
    sage_roi_order_date_echo_delivery_select( $selected, $dates );
    echo '</select>';
    echo '</p>';
    if ( ! $ajax_mount ) {
        echo '</form>';
    }
    $selected_ui = sage_roi_order_date_normalize_selected_for_list( $selected, $dates );
    sage_roi_order_date_render_cart_notice_inner( $selected_ui );
    echo '</div>';
    return ob_get_clean();
}

/**
 * Cart: dropdown → notice → then pay buttons / Proceed to Checkout (classic template hook).
 */
add_action( 'woocommerce_proceed_to_checkout', 'sage_roi_order_date_cart_delivery_section', 5 );
function sage_roi_order_date_cart_delivery_section() {
    if ( ! is_cart() || ! is_user_logged_in() ) {
        return;
    }
    echo sage_roi_order_date_get_cart_delivery_section_html( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Elementor Pro Cart widget outputs [woocommerce_cart] HTML without firing `woocommerce_proceed_to_checkout` in some setups — inject before `.wc-proceed-to-checkout`.
 */
add_filter( 'elementor/widget/render_content', 'sage_roi_order_date_elementor_inject_cart_delivery', 10, 2 );
function sage_roi_order_date_elementor_inject_cart_delivery( $content, $widget ) {
    if ( ! is_cart() || ! is_user_logged_in() || ! is_string( $content ) || $content === '' ) {
        return $content;
    }
    if ( strpos( $content, 'sage-roi-cart-delivery-wrap' ) !== false ) {
        return $content;
    }
    if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'woocommerce-cart' !== $widget->get_name() ) {
        return $content;
    }
    if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
        return $content;
    }
    $inject = sage_roi_order_date_get_cart_delivery_section_html( false );
    if ( $inject === '' ) {
        return $content;
    }
    $pattern = '/<div(?=[^>]*\bwc-proceed-to-checkout\b)[^>]*>/';
    if ( preg_match( $pattern, $content ) ) {
        return preg_replace( $pattern, $inject . '$0', $content, 1 );
    }
    return $content;
}

/**
 * WooCommerce Cart block renders in React — inject delivery UI via JS + AJAX.
 */
add_action( 'wp_enqueue_scripts', 'sage_roi_order_date_enqueue_cart_block_delivery', 30 );
function sage_roi_order_date_enqueue_cart_block_delivery() {
    if ( ! is_cart() || ! is_user_logged_in() ) {
        return;
    }
    $markup = sage_roi_order_date_get_cart_delivery_section_html( true );
    if ( $markup === '' ) {
        return;
    }
    $handle = 'sage-roi-cart-delivery';
    wp_enqueue_script(
        $handle,
        plugins_url( 'assets/cart-delivery.js', sage_roi_order_date_plugin_file() ),
        array(),
        '1.0.0',
        true
    );
    wp_localize_script(
        $handle,
        'sageRoiCartDelivery',
        array(
            'markup'   => $markup,
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'sage_roi_cart_delivery_ajax' ),
        )
    );
}

add_action( 'wp_ajax_sage_roi_order_date_save_cart_delivery', 'sage_roi_order_date_ajax_save_cart_delivery' );
function sage_roi_order_date_ajax_save_cart_delivery() {
    check_ajax_referer( 'sage_roi_cart_delivery_ajax', 'nonce' );
    if ( ! is_user_logged_in() || ! WC()->session ) {
        wp_send_json_error( array( 'message' => 'auth' ), 403 );
    }
    $date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    $available = sage_roi_order_date_get_available_dates();
    if ( $date !== '' && ! in_array( $date, $available, true ) ) {
        wp_send_json_error( array( 'message' => 'invalid' ), 400 );
    }
    sage_roi_order_date_set_session_delivery( $date );
    ob_start();
    sage_roi_order_date_render_cart_notice_inner( $date !== '' ? $date : null );
    $notice_html = ob_get_clean();
    wp_send_json_success( array( 'notice_html' => $notice_html ) );
}

/**
 * Classic checkout: dropdown + cart notice (same template as cart). Placed on
 * `woocommerce_review_order_before_submit` (inside `#payment`) so it runs during
 * `update_order_review` AJAX; `woocommerce_review_order_before_payment` is skipped when `wp_doing_ajax()`.
 */
add_action( 'woocommerce_review_order_before_submit', 'sage_roi_order_date_checkout_delivery_section', 5 );
function sage_roi_order_date_checkout_delivery_section() {
    if ( ! is_checkout() || is_order_received_page() || ! is_user_logged_in() ) {
        return;
    }

    $dates = sage_roi_order_date_get_available_dates();
    if ( empty( $dates ) ) {
        return;
    }

    $checkout = WC()->checkout();
    $posted   = sage_roi_order_date_checkout_posted_delivery_if_valid( $dates );
    $session  = WC()->session ? WC()->session->get( 'sage_roi_selected_delivery_date' ) : '';
    $session  = is_string( $session ) ? $session : '';
    $selected = '';
    if ( $posted !== '' ) {
        $selected = $posted;
    } elseif ( $session !== '' && in_array( $session, $dates, true ) ) {
        $selected = $session;
    } else {
        $gv = $checkout->get_value( 'sage_roi_order_date' );
        if ( is_string( $gv ) && $gv !== '' && in_array( $gv, $dates, true ) ) {
            $selected = $gv;
        }
    }
    $selected_ui = sage_roi_order_date_normalize_selected_for_list( $selected, $dates );

    echo '<div class="sage-roi-checkout-delivery-wrap" style="margin:0 0 1em;">';
    echo '<p class="form-row form-row-wide">';
    echo '<label for="sage_roi_order_date_checkout">' . esc_html__( 'Delivery date', 'sage-100-roi' ) . '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span></label>';
    echo '<select name="sage_roi_order_date" id="sage_roi_order_date_checkout" class="sage-roi-order-date-checkout-select" style="max-width:100%;" onchange="if(typeof jQuery!==\'undefined\'){jQuery(document.body).trigger(\'update_checkout\');}">';
    sage_roi_order_date_echo_delivery_select( $selected_ui, $dates );
    echo '</select>';
    echo '</p>';

    sage_roi_order_date_render_cart_notice_inner( $selected_ui );

    echo '</div>';
}

/**
 * Enhanced delivery &lt;select&gt; on classic checkout (WooCommerce already enqueues selectWoo here).
 */
add_action( 'wp_enqueue_scripts', 'sage_roi_order_date_enqueue_checkout_delivery_selectwoo', 25 );
function sage_roi_order_date_enqueue_checkout_delivery_selectwoo() {
    if ( ! is_checkout() || is_order_received_page() || ! is_user_logged_in() ) {
        return;
    }
    if ( empty( sage_roi_order_date_get_available_dates() ) ) {
        return;
    }
    if ( ! wp_script_is( 'wc-checkout', 'enqueued' ) ) {
        return;
    }
    $js = <<<'JS'
(function ($) {
	function sageRoiCheckoutDeliverySelect() {
		var $s = $('.sage-roi-order-date-checkout-select');
		if (!$s.length || !$.fn.selectWoo) {
			return;
		}
		$s.each(function () {
			var $el = $(this);
			if ($el.data('selectWoo')) {
				try {
					$el.selectWoo('destroy');
				} catch (e) {}
			}
		});
		$s.selectWoo({ width: '100%', minimumResultsForSearch: Infinity });
	}
	$(sageRoiCheckoutDeliverySelect);
	$(document.body).on('updated_checkout', sageRoiCheckoutDeliverySelect);
})(jQuery);
JS;
    wp_add_inline_script( 'wc-checkout', $js, 'after' );
}

/**
 * Notice from settings template. Optional $delivery_date_override (Y-m-d) aligns with checkout dropdown when set.
 */
function sage_roi_order_date_render_cart_notice_inner( $delivery_date_override = null ) {
    if ( $delivery_date_override !== null && $delivery_date_override !== '' ) {
        $delivery_date = sanitize_text_field( $delivery_date_override );
    } else {
        $delivery_date = sage_roi_order_date_get_selected_delivery_date_for_cart_notice();
    }
    if ( empty( $delivery_date ) ) {
        return;
    }

    $template     = sage_roi_order_date_get_cart_notice_template_string();
    $display_date = sage_roi_order_date_format_notice_short_date( $delivery_date );
    $message      = str_replace( '{delivery_date}', esc_html( $display_date ), $template );

    echo '<div class="sage-roi-cart-delivery-notice" style="margin:12px 0 0 0;">' . wp_kses_post( $message ) . '</div>';
}

/**
 * During `update_order_review` AJAX, the customer’s current delivery choice is in serialized `post_data`.
 * Prefer it when rendering the payment fragment so the dropdown matches even if session write order differs.
 *
 * @param string[] $dates Available Y-m-d values.
 * @return string Normalized Y-m-d or ''.
 */
function sage_roi_order_date_checkout_posted_delivery_if_valid( array $dates ) {
    if ( empty( $dates ) || ! isset( $_POST['post_data'] ) || ! is_string( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return '';
    }
    parse_str( wp_unslash( $_POST['post_data'] ), $parsed ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( ! isset( $parsed['sage_roi_order_date'] ) ) {
        return '';
    }
    $ymd = sanitize_text_field( $parsed['sage_roi_order_date'] );
    return in_array( $ymd, $dates, true ) ? $ymd : '';
}

add_action( 'woocommerce_checkout_update_order_review', 'sage_roi_order_date_store_session_selection' );
function sage_roi_order_date_store_session_selection( $post_data ) {
    if ( ! WC()->session ) {
        return;
    }
    parse_str( $post_data, $parsed );
    if ( ! isset( $parsed['sage_roi_order_date'] ) ) {
        return;
    }
    sage_roi_order_date_set_session_delivery( sanitize_text_field( $parsed['sage_roi_order_date'] ) );
}

/**
 * DOM id for Checkout block additional-field select (WC pattern: `order-{key}` with `/` → `-`).
 */
function sage_roi_order_date_block_order_field_select_id() {
    return 'order-' . str_replace( '/', '-', SAGE_ROI_ORDER_DATE_FIELD_ID );
}

/**
 * Cart notice template split for JS (same kses rules as rendered notice).
 *
 * @return array{0:string,1:string} HTML before and after `{delivery_date}`.
 */
function sage_roi_order_date_get_notice_template_parts_for_js() {
    $template = sage_roi_order_date_get_cart_notice_template_string();
    $parts    = explode( '{delivery_date}', $template, 2 );
    if ( count( $parts ) < 2 ) {
        $parts[] = '';
    }
    return array( wp_kses_post( $parts[0] ), wp_kses_post( $parts[1] ) );
}

/**
 * @param string[] $dates Y-m-d list.
 * @return array<string,string> Same short display as cart notice (`n/j/y`).
 */
function sage_roi_order_date_get_date_labels_for_js( array $dates ) {
    $out = array();
    foreach ( $dates as $d ) {
        $out[ $d ] = sage_roi_order_date_format_notice_short_date( $d );
    }
    return $out;
}

/**
 * True when the store’s checkout uses the Checkout block (page or block theme template).
 */
/**
 * @return \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields|null
 */
function sage_roi_order_date_blocks_checkout_fields() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) || ! class_exists( 'Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
        return null;
    }
    return \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
}

function sage_roi_order_date_is_checkout_block_page() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return false;
    }
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) ) {
        return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
    }
    $page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
    if ( $page_id <= 0 || ! function_exists( 'has_block' ) ) {
        return false;
    }
    $post = get_post( $page_id );
    return $post && has_block( 'woocommerce/checkout', $post );
}

/**
 * Block checkout: when customer has no saved value, prefill from cart session (Store API / React).
 */
add_filter( 'woocommerce_get_default_value_for_' . SAGE_ROI_ORDER_DATE_FIELD_ID, 'sage_roi_order_date_block_default_from_session', 10, 3 );
function sage_roi_order_date_block_default_from_session( $value, $group, $_wc_object ) {
    if ( 'other' !== $group || ! WC()->session ) {
        return $value;
    }
    if ( null !== $value && '' !== $value && '0' !== $value ) {
        return $value;
    }
    $available = sage_roi_order_date_get_available_dates();
    if ( empty( $available ) ) {
        return $value;
    }
    $fallback = sage_roi_order_date_session_or_earliest( $available );
    return $fallback !== '' ? $fallback : $value;
}

/**
 * Checkout block: inject cart notice below the delivery `<select>` (order fields are React; PHP hooks do not run).
 */
add_action( 'wp_enqueue_scripts', 'sage_roi_order_date_enqueue_checkout_block_notice', 35 );
function sage_roi_order_date_enqueue_checkout_block_notice() {
    if ( ! sage_roi_order_date_is_checkout_block_page() || is_order_received_page() || ! is_user_logged_in() ) {
        return;
    }
    $dates = sage_roi_order_date_get_available_dates();
    if ( empty( $dates ) ) {
        return;
    }
    $handle = 'sage-roi-checkout-block-notice';
    wp_enqueue_script(
        $handle,
        plugins_url( 'assets/checkout-block-notice.js', sage_roi_order_date_plugin_file() ),
        array(),
        '1.0.1',
        true
    );
    $dates = sage_roi_order_date_sort_dates_asc( $dates );
    wp_localize_script(
        $handle,
        'sageRoiCheckoutBlockNotice',
        array(
            'selectId'      => sage_roi_order_date_block_order_field_select_id(),
            'templateParts' => sage_roi_order_date_get_notice_template_parts_for_js(),
            'labels'        => sage_roi_order_date_get_date_labels_for_js( $dates ),
            'fallbackYmd'   => sage_roi_order_date_earliest_in_list( $dates ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'sage_roi_cart_delivery_ajax' ),
        )
    );
}

// Classic checkout: delivery UI on `woocommerce_review_order_before_submit` (see sage_roi_order_date_checkout_delivery_section).

add_filter( 'woocommerce_checkout_posted_data', 'sage_roi_order_date_merge_posted_delivery', 10, 1 );
function sage_roi_order_date_merge_posted_delivery( $data ) {
    if ( ! is_user_logged_in() ) {
        return $data;
    }
    if ( ! isset( $_POST['sage_roi_order_date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return $data;
    }
    $date = sanitize_text_field( wp_unslash( $_POST['sage_roi_order_date'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $available = sage_roi_order_date_get_available_dates();
    if ( $date === '' || in_array( $date, $available, true ) ) {
        $data['sage_roi_order_date'] = $date;
    }
    return $data;
}

add_filter( 'woocommerce_checkout_get_value', 'sage_roi_order_date_checkout_prefill_from_session', 10, 2 );
function sage_roi_order_date_checkout_prefill_from_session( $value, $input ) {
    if ( 'sage_roi_order_date' !== $input ) {
        return $value;
    }
    if ( ! is_user_logged_in() || ! WC()->session ) {
        return $value;
    }
    if ( '' !== $value && null !== $value && false !== $value ) {
        return $value;
    }
    $available = sage_roi_order_date_get_available_dates();
    $fallback  = sage_roi_order_date_session_or_earliest( $available );
    return $fallback !== '' ? $fallback : $value;
}

// --- Block Checkout field (WooCommerce 8.9+) ---
add_action( 'woocommerce_init', 'sage_roi_order_date_register_block_checkout_field' );
function sage_roi_order_date_register_block_checkout_field() {
    if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
        return;
    }
    if ( ! is_user_logged_in() ) return;

    $dates = sage_roi_order_date_get_available_dates();
    if ( empty( $dates ) ) {
        return;
    }

    woocommerce_register_additional_checkout_field(
        array(
            'id'       => SAGE_ROI_ORDER_DATE_FIELD_ID,
            'label'    => __( 'Order / Delivery Date', 'sage-100-roi' ),
            'location' => 'order',
            'type'     => 'select',
            'required' => false,
            'options'  => sage_roi_order_date_select_options_for_dates( $dates ),
        )
    );
}

// --- Save to order (classic) ---
add_action( 'woocommerce_checkout_update_order_meta', 'sage_roi_order_date_checkout_save_meta' );
// --- Save to order (block shortcode path) ---
add_action( 'woocommerce_checkout_order_created', 'sage_roi_order_date_block_sync_meta', 10, 2 );
// --- Block / Store API: classic hooks above do not run; sync after checkout fields are on the order (not on draft-only update_order_meta). ---
add_action( 'woocommerce_store_api_checkout_order_processed', 'sage_roi_order_date_block_sync_meta', 20, 1 );
function sage_roi_order_date_block_sync_meta( $order, $request = null ) {
    if ( ! $order ) {
        return;
    }
    $val = null;
    $cf  = sage_roi_order_date_blocks_checkout_fields();
    if ( $cf ) {
        $val = $cf->get_field_from_object( SAGE_ROI_ORDER_DATE_FIELD_ID, $order, 'other' );
    }
    if ( ! $val ) {
        $val = $order->get_meta( '_wc_other/' . SAGE_ROI_ORDER_DATE_FIELD_ID );
    }
    if ( ! $val ) {
        $val = $order->get_meta( '_wc_other/' . str_replace( '/', '_', SAGE_ROI_ORDER_DATE_FIELD_ID ) );
    }
    if ( ! $val ) {
        $val = $order->get_meta( SAGE_ROI_ORDER_DATE_FIELD_ID );
    }
    if ( ! $val && WC()->session ) {
        $session_date = WC()->session->get( 'sage_roi_selected_delivery_date' );
        $val          = is_string( $session_date ) ? $session_date : null;
    }
    $available = sage_roi_order_date_get_available_dates();
    if ( ! $val ) {
        $val = sage_roi_order_date_earliest_in_list( $available );
        if ( $val === '' ) {
            $val = null;
        }
    }
    if ( $val ) {
        sage_roi_order_date_save_order_delivery_meta( $order, $val );
    }
}
function sage_roi_order_date_checkout_save_meta( $order_id ) {
    if ( ! is_user_logged_in() ) return;

    $date = '';
    if ( isset( $_POST['sage_roi_order_date'] ) ) {
        $date = sanitize_text_field( wp_unslash( $_POST['sage_roi_order_date'] ) );
    }
    if ( empty( $date ) && WC()->session ) {
        $date = WC()->session->get( 'sage_roi_selected_delivery_date' );
        $date = is_string( $date ) ? $date : '';
    }
    $available = sage_roi_order_date_get_available_dates();
    if ( empty( $date ) ) {
        $date = sage_roi_order_date_earliest_in_list( $available );
    }
    if ( empty( $date ) ) {
        return;
    }

    if ( ! in_array( $date, $available, true ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( $order ) {
        sage_roi_order_date_save_order_delivery_meta( $order, $date );
    }
}

// --- Display in admin order ---
add_action( 'woocommerce_admin_order_data_after_billing_address', 'sage_roi_order_date_display_in_admin' );
function sage_roi_order_date_display_in_admin( $order ) {
    $date = $order->get_meta( sage_roi_option_key( 'order_date' ) );
    if ( ! $date ) {
        $cf = sage_roi_order_date_blocks_checkout_fields();
        if ( $cf ) {
            $date = $cf->get_field_from_object( SAGE_ROI_ORDER_DATE_FIELD_ID, $order, 'other' );
        }
    }
    if ( ! $date ) return;
    $dt = DateTime::createFromFormat( 'Y-m-d', $date );
    $formatted = $dt ? $dt->format( get_option( 'date_format' ) ) : $date;
    echo '<p><strong>' . esc_html__( 'Order Date', 'sage-100-roi' ) . ':</strong> ' . esc_html( $formatted ) . '</p>';
}
