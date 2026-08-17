<?php


function sage_roi_nonce_post_check() {
  $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
  if ( ! wp_verify_nonce( $nonce, 'sage_roi_nonce' ) ) {
    wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
  }
}


function sage_roi_nonce_get_check() {
  $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
  if ( ! wp_verify_nonce( $nonce, 'sage_roi_nonce' ) ) {
    wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
  }
}

/**
 * Admin-only: backs the customer picker on the Order Dates screen. A nonce alone would not
 * gate this — logged-out nonces are predictable per-session — so without the capability check
 * the wp_ajax_nopriv registration exposed every customer's name, email, address and phone to
 * anonymous search.
 */
add_action( 'wp_ajax_sage_roi_customer_search', 'sage_roi_customer_search' );
function sage_roi_customer_search() {

  sage_roi_nonce_get_check();
  if ( ! current_user_can( 'list_users' ) ) {
    wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
  }
  if ( ! isset( $_GET['term'] ) || strlen( sanitize_text_field( wp_unslash( $_GET['term'] ) ) ) < 3 ) {
    echo json_encode( [] );
    exit;
  }

  $search_term = sanitize_text_field( wp_unslash( $_GET['term'] ) );

  $args = array(
    'role'       => 'customer',
    'order'      => 'asc',
    'orderby'    => 'display_name',
    'search'     => '*' . $search_term . '*',
    'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
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