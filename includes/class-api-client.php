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

    public function get_connectors( $secret_key = '' ) {
        if ( empty( $secret_key ) ) {
            $secret_key = get_option( 'filecheck_secret_key' );
        }

        if ( empty( $secret_key ) ) {
            return array();
        }

        $url = $this->api_url . '/connectors/';
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
        if ( isset( $body['connectors'] ) && is_array( $body['connectors'] ) ) {
            return $body['connectors'];
        }

        return is_array( $body ) ? $body : array();
    }

    public function sync_order( $order_id, $payload, $secret_key = '' ) {
        if ( empty( $secret_key ) ) {
            $secret_key = get_option( 'filecheck_secret_key' );
        }

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'missing_key', __( 'Secret key is missing.', 'filecheck-woocommerce' ) );
        }

        $url = $this->api_url . '/orders/' . urlencode( $order_id );
        $response = wp_remote_post( $url, array(
            'headers' => $this->get_headers( $secret_key ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body    = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = isset( $body['message'] ) ? $body['message'] : sprintf( __( 'API returned HTTP code %d', 'filecheck-woocommerce' ), $code );
            return new WP_Error( 'sync_failed', $message );
        }

        return true;
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

    /**
     * Fetch a job and normalize it into a flat structure the admin UI can render:
     * overall status plus per-file outcome, proof thumbnails and output availability.
     */
    public function get_job_summary( $job_id, $secret_key = '' ) {
        $job = $this->get_job( $job_id, $secret_key );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $files = array();
        $runs  = isset( $job['runs'] ) && is_array( $job['runs'] ) ? $job['runs'] : array();

        foreach ( $runs as $run ) {
            $run_id = isset( $run['id'] ) ? $run['id'] : '';
            if ( empty( $run_id ) ) {
                continue;
            }

            $name = isset( $run['name'] ) ? $run['name'] : $run_id;

            // Collect proof thumbnails if the run exposes them.
            $proofs = array();
            if ( isset( $run['proofs'] ) && is_array( $run['proofs'] ) ) {
                foreach ( $run['proofs'] as $proof ) {
                    if ( ! empty( $proof['url'] ) ) {
                        $proofs[] = array(
                            'url' => esc_url_raw( $proof['url'] ),
                        );
                    }
                }
            }

            $files[] = array(
                'runId'       => $run_id,
                'name'        => $name,
                'outcome'     => isset( $run['outcome'] ) ? $run['outcome'] : null,
                'status'      => isset( $run['status'] ) ? $run['status'] : '',
                'hasOutput'   => ! empty( $run['hasOutput'] ),
                'downloadUrl' => isset( $run['downloadUrl'] ) ? esc_url_raw( $run['downloadUrl'] ) : '',
                'proofs'      => $proofs,
            );
        }

        return array(
            'jobId'  => $job_id,
            'status' => isset( $job['status'] ) ? $job['status'] : '',
            'files'  => $files,
        );
    }
}
