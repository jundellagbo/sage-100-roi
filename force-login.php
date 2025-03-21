<?php

# force any user to login
add_action( 'template_redirect', 'sage_roi_force_login_webapp' );
function sage_roi_force_login_webapp() {
  if ( !is_user_logged_in() ){ 
    auth_redirect();
  }
}