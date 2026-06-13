<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Fulfillment {
    
    protected static $_instance = null;
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function __construct() {
        // Sync order details to Filecheck immediately after order is created.
        // Three hooks cover all checkout paths:
        // - woocommerce_checkout_order_created      : WC 8.2+ classic checkout (passes $order)
        // - woocommerce_checkout_order_processed    : WC 4–8.1 classic checkout (passes $order_id, $posted, $order)
        // - woocommerce_store_api_checkout_order_processed : WC Blocks / Store API checkout (passes $order)
        add_action( 'woocommerce_checkout_order_created',              array( $this, 'sync_order_to_filecheck' ),       10, 1 );
        add_action( 'woocommerce_checkout_order_processed',            array( $this, 'sync_order_to_filecheck_by_id' ), 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed',  array( $this, 'sync_order_to_filecheck' ),       10, 1 );
        add_action( 'woocommerce_order_status_changed',                array( $this, 'sync_order_status_change' ),      10, 4 );

        // Trigger file download when order goes to processing or completed
        add_action( 'woocommerce_order_status_processing', array( $this, 'process_order_files' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_files' ), 10, 1 );
        
        // Add meta box to admin order view (legacy CPT and HPOS screens)
        add_action( 'add_meta_boxes_shop_order', array( $this, 'add_order_metabox' ) );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_order_metabox' ) );

        // Enqueue admin order-screen assets + AJAX endpoints for the live job panel
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_admin_assets' ) );
        add_action( 'wp_ajax_filecheck_get_job_details', array( $this, 'ajax_get_job_details' ) );

        // Handle secure file download request for admin
        add_action( 'admin_init', array( $this, 'handle_secure_download' ) );
    }

    /**
     * Enqueue the live job-details script on the order edit screen (CPT + HPOS).
     */
    public function enqueue_order_admin_assets( $hook ) {
        $is_order_screen = false;
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            global $post;
            if ( $post && 'shop_order' === $post->post_type ) {
                $is_order_screen = true;
            }
        }
        if ( false !== strpos( $hook, 'wc-orders' ) ) {
            $is_order_screen = true;
        }
        if ( ! $is_order_screen ) {
            return;
        }

        wp_enqueue_script(
            'filecheck-order-admin',
            FILECHECK_PLUGIN_URL . 'assets/js/order-admin.js',
            array(),
            FILECHECK_VERSION,
            true
        );
        wp_localize_script( 'filecheck-order-admin', 'filecheck_order_admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'filecheck_order_admin' ),
            'i18n'     => array(
                'loading'  => __( 'Loading file details…', 'filecheck-woocommerce' ),
                'error'    => __( 'Could not load file details.', 'filecheck-woocommerce' ),
                'download' => __( 'Download', 'filecheck-woocommerce' ),
                'view'     => __( 'View Job on Filecheck', 'filecheck-woocommerce' ),
                'noFiles'  => __( 'No processed files yet.', 'filecheck-woocommerce' ),
            ),
        ) );
    }

    /**
     * AJAX: return normalized Filecheck job details for every line item in an order.
     */
    public function ajax_get_job_details() {
        check_ajax_referer( 'filecheck_order_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'filecheck-woocommerce' ) ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'filecheck-woocommerce' ) ) );
        }

        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Secret key not configured.', 'filecheck-woocommerce' ) ) );
        }

        $items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $job_id = $item->get_meta( '_filecheck_job_id' );
            if ( empty( $job_id ) ) {
                continue;
            }

            $summary = Filecheck_API_Client::instance()->get_job_summary( $job_id, $secret_key );

            $entry = array(
                'itemId'   => $item_id,
                'itemName' => $item->get_name(),
                'jobId'    => $job_id,
                'adminUrl' => 'https://admin.filecheck.io/orders/' . rawurlencode( $order_id ) . '/' . rawurlencode( $job_id ),
            );

            if ( is_wp_error( $summary ) ) {
                $entry['error'] = $summary->get_error_message();
            } else {
                $entry['status'] = $summary['status'];
                $entry['files']  = $summary['files'];
            }

            $items[] = $entry;
        }

        wp_send_json_success( array( 'items' => $items ) );
    }

    /**
     * Callback for woocommerce_checkout_order_processed (WC < 8.2 fallback).
     * Signature: $order_id, $posted_data, $order
     */
    public function sync_order_to_filecheck_by_id( $order_id, $posted_data, $order ) {
        $this->sync_order_to_filecheck( $order );
    }

    /**
     * Sync order details to Filecheck API when an order is first placed.
     * Only runs if at least one line item has a Filecheck job attached.
     */
    public function sync_order_to_filecheck( $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) {
            return;
        }

        // Guard against double-execution (both hooks may fire in WC 8.2+)
        if ( $order->get_meta( '_filecheck_order_synced' ) === 'yes' ) {
            return;
        }

        $result = $this->push_order_to_filecheck( $order, $order->get_status() );

        if ( is_wp_error( $result ) ) {
            $order->add_order_note(
                sprintf( __( 'Filecheck: Failed to sync order — %s', 'filecheck-woocommerce' ), $result->get_error_message() )
            );
        } else {
            $order->update_meta_data( '_filecheck_order_synced', 'yes' );
            $order->save();
        }
    }

    /**
     * Re-sync order to Filecheck whenever the order status changes.
     * Signature: $order_id, $old_status, $new_status, $order
     */
    public function sync_order_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $result = $this->push_order_to_filecheck( $order, $new_status );

        if ( is_wp_error( $result ) ) {
            $order->add_order_note(
                sprintf( __( 'Filecheck: Failed to sync status change — %s', 'filecheck-woocommerce' ), $result->get_error_message() )
            );
        }
    }

    /**
     * Build the payload and POST it to Filecheck.
     * Returns true on success, WP_Error on failure, or null if the order has no Filecheck jobs.
     */
    private function push_order_to_filecheck( $order, $status ) {
        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            return null;
        }

        // Build line items — only include items that have a Filecheck job
        $line_items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $job_id = $item->get_meta( '_filecheck_job_id' );
            if ( empty( $job_id ) ) {
                continue;
            }

            $product    = $item->get_product();
            $product_id = $item->get_product_id();

            $item_data = array(
                'itemId'    => (string) $item_id,
                'productId' => (string) $product_id,
                'name'      => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'sku'       => $product ? $product->get_sku() : '',
                'total'     => $order->get_line_total( $item, true ),
                'jobId'     => $job_id,
            );

            // Include any public item meta (exclude internal WC/Filecheck keys)
            $excluded_keys = array( '_filecheck_job_id', '_filecheck_downloaded_files', '_reduced_stock', '_qty' );
            foreach ( $item->get_meta_data() as $meta ) {
                $meta_data = $meta->get_data();
                if ( ! in_array( $meta_data['key'], $excluded_keys, true ) && strpos( $meta_data['key'], '_' ) !== 0 ) {
                    $item_data['meta'][ $meta_data['key'] ] = $meta_data['value'];
                }
            }

            $line_items[] = $item_data;
        }

        // Nothing to sync if no Filecheck jobs on this order
        if ( empty( $line_items ) ) {
            return null;
        }

        // Customer details
        $customer = array(
            'id'    => $order->get_customer_id() ? (string) $order->get_customer_id() : null,
            'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );

        // Shipping address
        $shipping = array();
        if ( $order->get_shipping_address_1() ) {
            $shipping = array(
                'name'      => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
                'address1'  => $order->get_shipping_address_1(),
                'address2'  => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
                'company'   => $order->get_shipping_company(),
            );
        }

        $payload = array(
            'orderId'  => (string) $order->get_id(),
            'status'   => $status,
            'currency' => $order->get_currency(),
            'total'    => $order->get_total(),
            'customer' => $customer,
            'items'    => $line_items,
        );

        if ( ! empty( $shipping ) ) {
            $payload['shippingAddress'] = $shipping;
        }

        return Filecheck_API_Client::instance()->sync_order( $order->get_id(), $payload, $secret_key );
    }

    /**
     * Process all items in an order, fetch Filecheck jobs, and download output files.
     */
    public function process_order_files( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        // Check if we've already processed this order to prevent duplicate downloads
        if ( $order->get_meta( '_filecheck_processed' ) === 'yes' ) {
            return;
        }
        
        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            $order->add_order_note( __( 'Filecheck Error: Could not download files because Secret Key is not configured.', 'filecheck-woocommerce' ) );
            return;
        }
        
        $processed_any = false;
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $job_id = $item->get_meta( '_filecheck_job_id' );
            if ( empty( $job_id ) ) {
                continue;
            }
            
            $job = Filecheck_API_Client::instance()->get_job( $job_id, $secret_key );
            
            if ( is_wp_error( $job ) ) {
                $order->add_order_note( sprintf( __( 'Filecheck Error fetching Job %s: %s', 'filecheck-woocommerce' ), $job_id, $job->get_error_message() ) );
                continue;
            }
            
            // Check if job has runs
            $runs = isset( $job['runs'] ) ? $job['runs'] : array();
            if ( empty( $runs ) && isset( $job['runIds'] ) ) {
                // If runs are not expanded, fetch each run individually
                $runs = array();
                foreach ( $job['runIds'] as $run_id ) {
                    $run_url = Filecheck_API_Client::instance()->get_api_url() . '/runs/' . urlencode( $run_id );
                    $run_response = wp_remote_get( $run_url, array(
                        'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
                    ) );
                    if ( ! is_wp_error( $run_response ) && wp_remote_retrieve_response_code( $run_response ) === 200 ) {
                        $runs[] = json_decode( wp_remote_retrieve_body( $run_response ), true );
                    }
                }
            }
            
            if ( empty( $runs ) ) {
                continue;
            }
            
            $downloaded_files = array();
            
            foreach ( $runs as $run ) {
                $run_id = isset( $run['id'] ) ? $run['id'] : '';
                if ( empty( $run_id ) ) {
                    continue;
                }
                
                // Fetch filename if available, fallback to job/run IDs
                $filename = isset( $run['source']['name'] ) ? sanitize_file_name( $run['source']['name'] ) : '';
                if ( empty( $filename ) ) {
                    // Try to guess extension
                    $ext = '.pdf'; // default
                    if ( isset( $run['acceptKey'] ) && $run['acceptKey'] === 'raster' ) {
                        $ext = '.png';
                    }
                    $filename = $job_id . '-' . $run_id . $ext;
                }
                
                // Secure path setup
                $upload_dir = wp_upload_dir();
                $secure_dir = $upload_dir['basedir'] . '/filecheck-secure';
                
                if ( ! file_exists( $secure_dir ) ) {
                    wp_mkdir_p( $secure_dir );
                    // Protect directory using .htaccess and blank index
                    file_put_contents( $secure_dir . '/.htaccess', "Deny from all\n" );
                    file_put_contents( $secure_dir . '/index.php', "<?php // Silence\n" );
                }
                
                // Add unique prefix to avoid collisions
                $local_filename = time() . '_' . $filename;
                $local_filepath = $secure_dir . '/' . $local_filename;
                
                // API endpoint for retrieving output file
                $api_url = Filecheck_API_Client::instance()->get_api_url();
                $download_url = $api_url . '/jobs/' . urlencode( $job_id ) . '/runs/' . urlencode( $run_id ) . '/output';
                
                // Download file securely using Secret Key
                $download_response = wp_remote_get( $download_url, array(
                    'headers'  => array( 'Authorization' => 'Bearer ' . $secret_key ),
                    'timeout'  => 300, // 5 minutes for large files
                    'stream'   => true,
                    'filename' => $local_filepath,
                ) );
                
                if ( is_wp_error( $download_response ) ) {
                    $order->add_order_note( sprintf( __( 'Filecheck Error downloading output for Run %s: %s', 'filecheck-woocommerce' ), $run_id, $download_response->get_error_message() ) );
                    continue;
                }
                
                $response_code = wp_remote_retrieve_response_code( $download_response );
                if ( $response_code !== 200 ) {
                    // Clean up partial file if created
                    if ( file_exists( $local_filepath ) ) {
                        @unlink( $local_filepath );
                    }
                    $order->add_order_note( sprintf( __( 'Filecheck Error downloading output for Run %s: API returned HTTP code %d', 'filecheck-woocommerce' ), $run_id, $response_code ) );
                    continue;
                }
                
                $downloaded_files[] = array(
                    'run_id'     => $run_id,
                    'filename'   => $filename,
                    'local_path' => $local_filepath,
                    'local_name' => $local_filename,
                );
            }
            
            if ( ! empty( $downloaded_files ) ) {
                $item->update_meta_data( '_filecheck_downloaded_files', $downloaded_files );
                $item->save();
                
                $file_list = array_map( function( $f ) { return $f['filename']; }, $downloaded_files );
                $order->add_order_note( sprintf( __( 'Filecheck: Downloaded output files for item %s: %s', 'filecheck-woocommerce' ), $item->get_name(), implode( ', ', $file_list ) ) );
                $processed_any = true;
            }
        }
        
        if ( $processed_any ) {
            $order->update_meta_data( '_filecheck_processed', 'yes' );
            $order->save();
        }
    }
    
    /**
     * Add the Filecheck meta box to the admin order edit screen.
     */
    public function add_order_metabox() {
        // Use the HPOS order screen id when available, otherwise the legacy CPT screen.
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        add_meta_box(
            'filecheck_order_uploads',
            __( 'Filecheck Uploads', 'filecheck-woocommerce' ),
            array( $this, 'render_order_metabox' ),
            $screen,
            'side',
            'default'
        );
    }
    
    /**
     * Render order edit metabox — a live panel filled by order-admin.js via AJAX.
     */
    public function render_order_metabox( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        $has_filecheck = false;
        foreach ( $order->get_items() as $item ) {
            if ( ! empty( $item->get_meta( '_filecheck_job_id' ) ) ) {
                $has_filecheck = true;
                break;
            }
        }

        if ( ! $has_filecheck ) {
            echo '<p>' . esc_html__( 'No Filecheck items in this order.', 'filecheck-woocommerce' ) . '</p>';
            return;
        }

        echo '<div id="filecheck-job-panel" data-order-id="' . esc_attr( $order->get_id() ) . '">';
        echo '<p class="filecheck-loading">' . esc_html__( 'Loading file details…', 'filecheck-woocommerce' ) . '</p>';
        echo '</div>';
    }
    
    /**
     * Handle the admin secure file download request.
     */
    public function handle_secure_download() {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'filecheck_download' ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( __( 'You do not have permission to download this file.', 'filecheck-woocommerce' ) );
            }
            
            $order_id   = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
            $item_id    = isset( $_GET['item_id'] ) ? intval( $_GET['item_id'] ) : 0;
            $file_index = isset( $_GET['file_index'] ) ? intval( $_GET['file_index'] ) : 0;
            
            if ( ! $order_id || ! $item_id ) {
                wp_die( __( 'Invalid request parameters.', 'filecheck-woocommerce' ) );
            }
            
            check_admin_referer( 'filecheck_download_file' );
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_die( __( 'Order not found.', 'filecheck-woocommerce' ) );
            }
            
            $item = $order->get_item( $item_id );
            if ( ! $item ) {
                wp_die( __( 'Order item not found.', 'filecheck-woocommerce' ) );
            }
            
            $downloaded_files = $item->get_meta( '_filecheck_downloaded_files' );
            if ( empty( $downloaded_files ) || ! isset( $downloaded_files[ $file_index ] ) ) {
                wp_die( __( 'File reference not found.', 'filecheck-woocommerce' ) );
            }
            
            $file = $downloaded_files[ $file_index ];
            $filepath = $file['local_path'];
            
            if ( ! file_exists( $filepath ) ) {
                wp_die( __( 'Physical file does not exist on disk.', 'filecheck-woocommerce' ) );
            }
            
            // Clean output buffer to prevent corrupted downloads
            if ( ob_get_level() ) {
                ob_end_clean();
            }
            
            // Stream the file securely
            header( 'Content-Description: File Transfer' );
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file['filename'] ) . '"' );
            header( 'Expires: 0' );
            header( 'Cache-Control: must-revalidate' );
            header( 'Pragma: public' );
            header( 'Content-Length: ' . filesize( $filepath ) );
            
            readfile( $filepath );
            exit;
        }
    }
}
