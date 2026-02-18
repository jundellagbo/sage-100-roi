<?php

/** REST API CODE */

// app and secret handler
function sage_roi_app_secret_handler( $request ) {
    $appId = $request->get_header( 'Sage-ROI-X-App-ID' );
    $appSecret = $request->get_header( 'Sage-ROI-X-App-Secret' );
    $appCredentials = base64_encode( $appId.":".$appSecret );
    
    $fds = new FSD_Data_Encryption();
    $wpAppId = $fds->decrypt(sage_roi_get_option('app_password_id'));
    $wpAppSecret = $fds->decrypt(sage_roi_get_option('app_password_secret'));
    $wpAppCredentials = base64_encode( $wpAppId.":".$wpAppSecret );

    if(($appCredentials === $wpAppCredentials)) { return true; }
    return false;
}

// Handling credentials for app id, secret and microsoft online oauth2 token
function sage_roi_request_permission_callback( WP_REST_Request $request ) {
    $tokenCode = sage_roi_token_validate();
    if((sage_roi_app_secret_handler( $request )) && $tokenCode===200) { return true; }
    return false;
}

// Handling credentials for non insynch related request
function sage_roi_request_permission_callback_no_insynch( WP_REST_Request $request ) {
    return sage_roi_app_secret_handler( $request );
}

// if param is provided then use the param values, otherwise use the get_option values, they are optionals.
function sage_roi_token_auth() {

    $fds = new FSD_Data_Encryption();

    $isProduction = sage_roi_get_option('use_production');
    $clientId = $fds->decrypt(sage_roi_get_option('client_id'));
    $clientSecret = $fds->decrypt(sage_roi_get_option('client_secret'));
    $clientScope = $fds->decrypt(sage_roi_get_option('client_scope'));

    if($isProduction) {
        $clientId = $fds->decrypt(sage_roi_get_option('client_id_production'));
        $clientSecret = $fds->decrypt(sage_roi_get_option('client_secret_production'));
        $clientScope = $fds->decrypt(sage_roi_get_option('client_scope_production'));
    }

    $requestURL = $fds->decrypt(sage_roi_get_option('oauth_token_url'));

    $response = wp_remote_post($requestURL, array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded'
        ),
        'body' => array(
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $clientScope
        )
    ));

    if ( is_wp_error( $response ) ) {
        return $response->get_error_message();
     }

     return json_decode($response['body']);
}

// handling token expiration and refresh the token.
function sage_roi_token_validate() {
    $fds = new FSD_Data_Encryption();
    $requestURL = sage_roi_base_endpoint( "/diagnostics/throw" );
    $response = wp_remote_get($requestURL, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $fds->decrypt(sage_roi_get_option('access_token'))
        ),
    ));

    $statusCode = wp_remote_retrieve_response_code($response);
    if($statusCode === 401) {
        $tokenAuth = sage_roi_token_auth();
        if(isset($tokenAuth->access_token)) {
            $appToken = $fds->encrypt($tokenAuth->access_token);
            sage_roi_set_option( 'access_token', $appToken );
            return 200;
        }
    }

    return 200;
}


function sage_roi_base_endpoint( $api ) {
    $isProduction = sage_roi_get_option('use_production');
    if($isProduction) {
        return "https://roiconsultingapi.azurewebsites.net/api" . $api;
    } else {
        return "https://roiconsultingapidev.azurewebsites.net/api" . $api;
    }
}