<?php


function sage_roi_nonce_post_check() {
  if(!wp_verify_nonce( $_POST['nonce'], 'sage_roi_nonce' )) {
    throw new Exception("Sage ROI noce is invalid");
    die();
  }
}


function sage_roi_nonce_get_check() {
  if(!wp_verify_nonce( $_GET['nonce'], 'sage_roi_nonce' )) {
    throw new Exception("Sage ROI noce is invalid");
    die();
  }
}

add_action( 'wp_ajax_nopriv_sage_roi_customer_search', 'sage_roi_customer_search');
add_action( 'wp_ajax_sage_roi_customer_search', 'sage_roi_customer_search' );
function sage_roi_customer_search() {

  sage_roi_nonce_get_check();
  if(!isset( $_GET['term'] )) {
    echo json_encode([]);
  }

  $search_term = $_GET['term'];

  $args = array();

  $args = array (
    'role'       => 'customer',
    'order'      => 'asc',
    'orderby'    => 'display_name',
    'meta_query' => array(
      'relation' => 'or',
      array(
        'key'     => 'name',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
          'key'     => 'nickname',
          'value'   => $search_term,
          'compare' => 'like'
      ),
      array(
        'key'     => 'email',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'last_name',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_address_1',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_address_2',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_state',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_country',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_postcode',
        'value'   => $search_term,
        'compare' => 'like'
      ),
      array(
        'key'     => 'billing_phone',
        'value'   => $search_term,
        'compare' => 'like'
      ),
    )
  );


  $wp_user_query = new WP_User_Query( $args );

  $customers = $wp_user_query->get_results();

  $results = [];
  foreach ( $customers as $customer ) {
    $results[] = array(
      'id' => $customer->ID,
      'text' => $customer->first_name . " " . $customer->last_name
    );
  }

  echo json_encode($results);

  die();
}