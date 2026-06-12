<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_API_Client {
    
    protected static $_instance = null;
    protected $api_url = 'https://api.filecheck.io';
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function __construct() {
        // Allow URL override via option
        $override = get_option( 'filecheck_api_url' );
        if ( ! empty( $override ) ) {
            $this->api_url = esc_url_raw( $override );
        }
    }
    
    public function get_api_url() {
        return $this->api_url;
    }
    
    protected function get_headers( $secret_key ) {
        return array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );
    }
    
    public function verify_keys( $publishable_key, $secret_key ) {
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'missing_key', __( 'Secret key is required.', 'filecheck-woocommerce' ) );
        }
        
        $url = $this->api_url . '/workflows/';
        $response = wp_remote_get( $url, array(
            'headers' => $this->get_headers( $secret_key ),
            'timeout' => 15,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            return true;
        }
        
        if ( $code === 401 || $code === 403 ) {
            return new WP_Error( 'auth_failed', __( 'Authentication failed. Please check your keys.', 'filecheck-woocommerce' ) );
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $message = isset( $body['message'] ) ? $body['message'] : sprintf( __( 'API returned HTTP code %d', 'filecheck-woocommerce' ), $code );
        return new WP_Error( 'auth_failed', $message );
    }
    
    public function get_workflows( $secret_key = '' ) {
        if ( empty( $secret_key ) ) {
            $secret_key = get_option( 'filecheck_secret_key' );
        }
        
        if ( empty( $secret_key ) ) {
            return array();
        }
        
        $url = $this->api_url . '/workflows/';
        $response = wp_remote_get( $url, array(
            'headers' => $this->get_headers( $secret_key ),
            'timeout' => 15,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array();
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return array();
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        // Handle both possible envelope keys from the API
        if ( isset( $body['workflows'] ) && is_array( $body['workflows'] ) ) {
            return $body['workflows'];
        }
        if ( isset( $body['rules'] ) && is_array( $body['rules'] ) ) {
            return $body['rules'];
        }
        
        return is_array( $body ) ? $body : array();
    }
    
    public function get_job( $job_id, $secret_key = '' ) {
        if ( empty( $secret_key ) ) {
            $secret_key = get_option( 'filecheck_secret_key' );
        }
        
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'missing_key', __( 'Secret key is missing.', 'filecheck-woocommerce' ) );
        }
        
        $url = $this->api_url . '/jobs/' . urlencode( $job_id ) . '?expand=runs';
        $response = wp_remote_get( $url, array(
            'headers' => $this->get_headers( $secret_key ),
            'timeout' => 15,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', sprintf( __( 'API returned HTTP code %d', 'filecheck-woocommerce' ), $code ) );
        }
        
        $job = json_decode( wp_remote_retrieve_body( $response ), true );
        return $job;
    }
}
