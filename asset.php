<?php

add_action( 'admin_enqueue_scripts', 'sage_roi_admin_enqueue_scripts' );

function sage_roi_admin_enqueue_scripts() {
    wp_enqueue_script( 'sage_roi_script', plugin_dir_url( __FILE__ ) . 'assets/sage.js', array(), null, true );

    wp_localize_script( 'sage_roi_script', 'sage_roi_var', array(
        'url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'sage_roi_nonce' )
    ));
}