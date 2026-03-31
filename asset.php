<?php

# admin enqueue
add_action( 'admin_enqueue_scripts', 'sage_roi_admin_enqueue_scripts' );

/**
 * Bundled Select2 uses its own handle so we never depend on WooCommerce's selectWoo
 * registration (which can differ between environments and break on live).
 */
function sage_roi_admin_enqueue_scripts( $hook ) {
    $screen = get_current_screen();
    $needs_select2 = $screen && (
        $screen->post_type === 'sage_roi_order_date'
    );

    if ( $needs_select2 ) {
        $base_url = plugin_dir_url( __FILE__ ) . 'assets/';
        $base_dir = plugin_dir_path( __FILE__ ) . 'assets/';
        $js_ver  = file_exists( $base_dir . 'select2.min.js' ) ? (string) filemtime( $base_dir . 'select2.min.js' ) : '1';
        $css_ver = file_exists( $base_dir . 'select2.min.css' ) ? (string) filemtime( $base_dir . 'select2.min.css' ) : '1';

        wp_register_script(
            'sage_roi_select2',
            $base_url . 'select2.min.js',
            array( 'jquery' ),
            $js_ver,
            true
        );
        wp_register_style(
            'sage_roi_select2',
            $base_url . 'select2.min.css',
            array(),
            $css_ver
        );
        wp_enqueue_script( 'sage_roi_select2' );
        wp_enqueue_style( 'sage_roi_select2' );
    }

    $deps = array( 'jquery' );
    if ( $needs_select2 ) {
        $deps[] = 'sage_roi_select2';
    }

    $sage_js = plugin_dir_path( __FILE__ ) . 'assets/sage.js';
    $sage_ver = file_exists( $sage_js ) ? (string) filemtime( $sage_js ) : null;

    wp_enqueue_script(
        'sage_roi_script',
        plugin_dir_url( __FILE__ ) . 'assets/sage.js',
        $deps,
        $sage_ver,
        true
    );

    wp_localize_script( 'sage_roi_script', 'sage_roi_var', array(
        'url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'sage_roi_nonce' )
    ));
}


# frontend enqueue
add_action( 'get_footer', 'sage_roi_enqueue_scripts' );

function sage_roi_enqueue_scripts() {

    wp_enqueue_style('sage_roi_style', plugin_dir_url( __FILE__  ) .'assets/sage.css', array(), null, null);

}


