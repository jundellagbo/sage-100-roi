<?php

add_action('wp_footer', 'sage_roi_add_floating_button');
function sage_roi_add_floating_button() {
    $cart_url = wc_get_cart_url();
    $cart_count = WC()->cart->get_cart_contents_count();
    if( is_cart() || is_checkout() || !is_user_logged_in() ) return;
    ?>
    <a class="sage-roi-floating-cart" href="<?php echo esc_url($cart_url); ?>">
        <span class="sage-roi-cart-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                <path d="M351.9 329.506H206.81l-3.072-12.56H368.16l26.63-116.019-217.23-26.04-9.952-58.09h-50.4v21.946h31.894l35.233 191.246a32.927 32.927 0 1 0 36.363 21.462h100.244a32.825 32.825 0 1 0 30.957-21.945zM181.427 197.45l186.51 22.358-17.258 75.195H198.917z" data-name="Shopping Cart"/>
            </svg>
        </span>
        <span class="screen-reader-text">View Cart</span>
        <span class="sage-roi-cart-count cart-count <?php if( $cart_count === 0 ): ?>cart-count-hidden<?php endif; ?>" id="sage-roi-cart-count">
            <?php echo $cart_count; ?>
        </span>
    </a>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        jQuery(document.body).on('added_to_cart removed_from_cart', function () {
            jQuery.get('<?php echo admin_url('admin-ajax.php'); ?>?action=sage_roi_get_cart_count&nonce=<?php echo wp_create_nonce('sage_roi_cart_count_nonce'); ?>', function(response) {
                var sage_roi_cart_count = Number(response);
                var sage_roi_cart_count_elem = jQuery('#sage-roi-cart-count');
                if( sage_roi_cart_count === 0 ) {
                    sage_roi_cart_count_elem.addClass( "cart-count-hidden" );
                } else {
                    sage_roi_cart_count_elem.removeClass( "cart-count-hidden" );
                }
                sage_roi_cart_count_elem.text(sage_roi_cart_count);
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_sage_roi_get_cart_count', 'sage_roi_get_cart_count_ajax');
add_action('wp_ajax_nopriv_sage_roi_get_cart_count', 'sage_roi_get_cart_count_ajax');
function sage_roi_get_cart_count_ajax() {
    if ( ! check_ajax_referer('sage_roi_cart_count_nonce', 'nonce', false) ) {
        wp_send_json_error('Invalid nonce', 403);
        return;
    }

    echo WC()->cart->get_cart_contents_count();
    wp_die();
}

