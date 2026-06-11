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
        // Trigger file download when order goes to processing or completed
        add_action( 'woocommerce_order_status_processing', array( $this, 'process_order_files' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_files' ), 10, 1 );
        
        // Add meta box to admin order view (legacy CPT and HPOS screens)
        add_action( 'add_meta_boxes_shop_order', array( $this, 'add_order_metabox' ) );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_order_metabox' ) );
        
        // Handle secure file download request for admin
        add_action( 'admin_init', array( $this, 'handle_secure_download' ) );
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
        add_meta_box(
            'filecheck_order_uploads',
            __( 'Filecheck Uploads', 'filecheck-woocommerce' ),
            array( $this, 'render_order_metabox' ),
            'shop_order',
            'side',
            'default'
        );
    }
    
    /**
     * Render order edit metabox.
     */
    public function render_order_metabox( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return;
        }
        
        $has_filecheck = false;
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $job_id = $item->get_meta( '_filecheck_job_id' );
            if ( empty( $job_id ) ) {
                continue;
            }
            
            $has_filecheck = true;
            $downloaded_files = $item->get_meta( '_filecheck_downloaded_files' );
            
            echo '<div style="margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee;">';
            echo '<strong>' . esc_html( $item->get_name() ) . '</strong><br>';
            echo '<span style="font-size: 11px; color: #666;">Job ID: ' . esc_html( $job_id ) . '</span><br>';
            
            // Link to Filecheck Admin Dashboard report
            $admin_url = 'https://admin.filecheck.io/jobs/' . urlencode( $job_id );
            echo '<a href="' . esc_url( $admin_url ) . '" target="_blank" class="button button-small" style="margin-top: 5px; margin-bottom: 5px;">' . __( 'View Report in Filecheck', 'filecheck-woocommerce' ) . '</a><br>';
            
            if ( ! empty( $downloaded_files ) && is_array( $downloaded_files ) ) {
                echo '<ul style="margin: 5px 0 0 15px; list-style-type: disc;">';
                foreach ( $downloaded_files as $index => $file ) {
                    $download_url = wp_nonce_url(
                        admin_url( 'index.php?action=filecheck_download&order_id=' . $order->get_id() . '&item_id=' . $item_id . '&file_index=' . $index ),
                        'filecheck_download_file'
                    );
                    echo '<li>';
                    echo '<a href="' . esc_url( $download_url ) . '">' . esc_html( $file['filename'] ) . '</a>';
                    echo '</li>';
                }
                echo '</ul>';
            } else {
                echo '<span style="font-size: 11px; color: #d63638;">' . __( 'No output files downloaded yet. Processing order will fetch files.', 'filecheck-woocommerce' ) . '</span>';
            }
            echo '</div>';
        }
        
        if ( ! $has_filecheck ) {
            echo '<p>' . __( 'No Filecheck items in this order.', 'filecheck-woocommerce' ) . '</p>';
        }
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
