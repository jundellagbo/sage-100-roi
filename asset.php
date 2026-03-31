<?php

# admin enqueue
add_action( 'admin_enqueue_scripts', 'sage_roi_admin_enqueue_scripts' );

function sage_roi_admin_enqueue_scripts( $hook ) {
    $screen = get_current_screen();
    $needs_select2 = $screen && (
        $screen->post_type === 'product' ||
        $screen->post_type === 'sage_roi_order_date'
    );

    if ( $needs_select2 ) {
        if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
            $base = plugin_dir_url( __FILE__ ) . 'assets/';
            wp_register_script(
                'selectWoo',
                $base . 'select2.min.js',
                array( 'jquery' ),
                null,
                true
            );
            wp_register_style(
                'selectWoo',
                $base . 'select2.min.css',
                array(),
                null
            );
        }
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_style( 'selectWoo' );
    }

    $deps = array( 'jquery' );
    if ( $needs_select2 ) {
        $deps[] = 'selectWoo';
    }

    wp_enqueue_script(
        'sage_roi_script',
        plugin_dir_url( __FILE__ ) . 'assets/sage.js',
        $deps,
        null,
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


