<?php

# force any user to login
add_action( 'template_redirect', 'sage_roi_force_login_webapp' );
function sage_roi_force_login_webapp() {
  if ( !is_user_logged_in() ){ 
    auth_redirect();
  }
}

// logout link shortcode
function sage_roi_wp_logout_link_shortcode() {
  if (is_user_logged_in()) {
      return esc_url( wp_logout_url( home_url() ) );
  }
  return '#';
}
add_shortcode('logout_link', 'sage_roi_wp_logout_link_shortcode');