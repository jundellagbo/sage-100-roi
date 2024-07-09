<?php

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
            $variation->id, 
            'number_of_units_package', 
            sanitize_text_field($_POST[$field_key][$i])
        );
    }
}

# Customers Reports
add_filter( 'woocommerce_report_customers_export_columns', 'sage_roi_woocommerce_report_customers_export_columns_filter' );
function sage_roi_woocommerce_report_customers_export_columns_filter( $export_columns ) {
	return array_merge(
        array(
            "sage_customer_number" => "Customer #"
        ),
        $export_columns
    );
}


add_filter( 'woocommerce_report_customers_prepare_export_item', 'sage_roi_woocommerce_report_customers_prepare_export_item', 10 , 2 );
function sage_roi_woocommerce_report_customers_prepare_export_item( $export_item, $item ) {
    try {
        $user = get_user_by( 'email', strtolower( $item['email'] ) );
        $usermeta = get_user_meta( $user->ID, sage_roi_option_key('customer_json'), true );
        $usermetajson = json_decode( $usermeta );
        if($usermetajson->CustomerNo) {
            $export_item['sage_customer_number'] = $usermetajson->ARDivisionNo."-".$usermetajson->CustomerNo;
        } else {
            $export_item['sage_customer_number'] = "";
        }
        return $export_item;
    } catch(Exception $e) {
        return $export_item;
    }
    return $export_item;
}


# Customers List Reports
add_filter( 'woocommerce_admin_reports', 'woocommerce_admin_reports' );
function woocommerce_admin_reports( $reports ) {
    $reports['customers']['reports']['customer_list']['callback'] = 'sage_roi_customer_list_get_report';
    return $reports;
}


function sage_roi_customer_list_get_report( $name ) {
    $class = 'Sage_ROI_WC_Report_Customer_List';
    do_action('sage_roi_class_wc_report_customer_list');
    if ( ! class_exists( $class ) )
        return;

    $report = new $class();
    $report->output_report();
}


add_action( 'sage_roi_class_wc_report_customer_list', 'sage_roi_class_wc_report_customer_list' );
function sage_roi_class_wc_report_customer_list() {

    if ( ! class_exists( 'WC_Report_Customer_List' ) ) {
        include_once( WC_ABSPATH . 'includes/admin/reports/class-wc-report-customer-list.php' );
    }
    class Sage_ROI_WC_Report_Customer_List extends WC_Report_Customer_List {

        /**
         * Get column value.
         *
         * @param WP_User $user
         * @param string $column_name
         * @return string
         */
        public function column_default( $user, $column_name ) {
            global $wpdb;

            switch ( $column_name ) {

                case 'customer_number' :
                    $usermeta = get_user_meta( $user->ID, sage_roi_option_key('customer_json'), true );
                    try {
                        $usermetajson = json_decode( $usermeta );
                        return $usermetajson->ARDivisionNo."-".$usermetajson->CustomerNo;
                    } catch(Exception $e) {
                        return "";
                    }
                break;

                case 'customer_name' :
                    $userdata = get_userdata( $user->ID );
                    return $userdata->first_name . " " . $userdata->last_name;
                break;

            }
            return parent::column_default( $user, $column_name );
        }

        /**
         * Get columns.
         *
         * @return array
         */
        public function get_columns() {

            /* default columns.
            $columns = array(
                'customer_name'   => __( 'Name (Last, First)', 'woocommerce' ),
                'username'        => __( 'Username', 'woocommerce' ),
                'email'           => __( 'Email', 'woocommerce' ),
                'location'        => __( 'Location', 'woocommerce' ),
                'orders'          => __( 'Orders', 'woocommerce' ),
                'spent'           => __( 'Money spent', 'woocommerce' ),
                'last_order'      => __( 'Last order', 'woocommerce' ),
                'user_actions'    => __( 'Actions', 'woocommerce' ),
            ); */

            // adding custom column in customers reports woocommerce
            $columns = array(
                'customer_number' => __( 'Customer #', 'woocommerce' ),
                'customer_name'   => __( 'Name (First, Last)', 'woocommerce' ),
                'username'        => __( 'Username', 'woocommerce' ),
                'email'           => __( 'Email', 'woocommerce' ),
                'location'        => __( 'Location', 'woocommerce' )
            );
            return array_merge( $columns, parent::get_columns() );
        }
    }
}



 # Order List Custom Columns

 // ADDING 2 NEW COLUMNS WITH THEIR TITLES (before "Total" and "Actions" columns)
add_filter( 'manage_edit-shop_order_columns', 'sage_roi_add_admin_order_list_custom_column', 20 );
function sage_roi_add_admin_order_list_custom_column($columns)
{
    $reordered_columns = array();
    // Inserting columns to a specific location
    foreach( $columns as $key => $column){
        $reordered_columns[$key] = $column;
        if( $key ==  'order_number' ){
            // Inserting after "Status" column
            $reordered_columns['sage_roi_SalesOrderNo'] = __( 'Sales Order No.', 'woocommerce');
            $reordered_columns['sage_roi_customer_number'] = __( 'Customer #', 'woocommerce');
            $reordered_columns['sage_roi_store_code'] = __( 'Store Code', 'woocommerce');
        }
    }
    return $reordered_columns;
}

// Adding custom fields meta data for each new column (example)
add_action( 'manage_shop_order_posts_custom_column' , 'sage_roi_display_admin_order_list_custom_column_content', 20, 2 );
function sage_roi_display_admin_order_list_custom_column_content( $column, $post_id )
{
    try {

        global $the_order;
        $orderJson = get_post_meta( $the_order->get_id(), sage_roi_option_key('order_json' ), true );
        $orderJson = json_decode($orderJson);

        switch ( $column )
        {
            case 'sage_roi_SalesOrderNo' :
                echo $orderJson->SalesOrderNo;
            break;

            case 'sage_roi_customer_number':
                if($orderJson->Customer->CustomerNo) {
                    echo $orderJson->Customer->ARDivisionNo."-".$orderJson->Customer->CustomerNo;
                }       
            break;

            case 'sage_roi_store_code':
                echo $orderJson->Customer->CustomerNo;
            break;
        }

    } catch(Exception $e) {
        // nothing to do if there is an error.
    }
}


# adding columns in order items.
// Add header
function sage_roi_action_woocommerce_admin_order_item_headers() {
    
    // Display the column name
    echo '<th class="line_packing_weight sortable" data-sort="string-ins">' .  __( 'Item Code', 'woocommerce' ) . '</th>';
    echo '<th class="line_packing_weight sortable" data-sort="string-ins">' .  __( 'Product Line', 'woocommerce' ) . '</th>';
    echo '<th class="line_packing_weight sortable" data-sort="string-ins">' .  __( 'Units Per Package', 'woocommerce' ) . '</th>';
    echo '<th class="line_packing_weight sortable" data-sort="string-ins">' .  __( 'Sold By', 'woocommerce' ) . '</th>';
}
add_action( 'woocommerce_admin_order_item_headers', 'sage_roi_action_woocommerce_admin_order_item_headers', 10, 1 );

//Add content
function sage_roi_action_woocommerce_admin_order_item_values( $product, $item, $item_id=null ) {
    try {
        $itemJson = get_post_meta( $item->get_id(), sage_roi_option_key('product_json' ), true );
        $itemJson = json_decode($itemJson);
        echo '<td>' . $itemJson->ItemCode . '</td>';
        echo '<td>' . $itemJson->ProductLine . '</td>';
        echo '<td>' . $itemJson->StandardUnitOfMeasure . '</td>';
        echo '<td></td>';
    } catch(Exception $e) {
        echo '<td></td>';
        echo '<td></td>';
        echo '<td></td>';
        echo '<td></td>';
    }
}
add_action( 'woocommerce_admin_order_item_values', 'sage_roi_action_woocommerce_admin_order_item_values', 10, 3 );