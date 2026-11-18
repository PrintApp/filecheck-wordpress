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

        // Artwork after checkout: the "Upload your artwork" link in the store's
        // own order emails, on the order received page and in My Account.
        add_action( 'woocommerce_email_order_details', array( $this, 'email_upload_link' ), 5, 4 );
        add_action( 'woocommerce_order_details_before_order_table', array( $this, 'render_upload_link' ), 5, 1 );
    }

    /**
     * Order statuses at which lines bought without artwork are sent to
     * Filecheck. WooCommerce creates the order before payment (pending,
     * checkout-draft), so abandoned checkouts never get an upload page.
     */
    const AWAIT_STATUSES = array( 'processing', 'completed', 'on-hold' );

    /** Orders already synced for their upload link in this request. */
    private $synced_for_link = array();

    /**
     * The workflow a line without a file is collected on after checkout, or
     * '' when the line is not waiting for artwork (it has a file, the product
     * has no Filecheck workflow, or the product collects at checkout).
     */
    protected function await_workflow( $item ) {
        if ( ! $item instanceof WC_Order_Item_Product || $item->get_meta( '_filecheck_job_id' ) ) {
            return '';
        }
        $product_id = $item->get_product_id();
        $workflow   = Filecheck_Product::workflow_id( $product_id );
        if ( ! $workflow || 'checkout' === Filecheck_Product::artwork_timing( $product_id ) ) {
            return '';
        }
        return $workflow;
    }

    /** Does this paid order have a line waiting for artwork? */
    protected function has_lines_awaiting( $order ) {
        if ( ! in_array( $order->get_status(), self::AWAIT_STATUSES, true ) ) {
            return false;
        }
        foreach ( $order->get_items() as $item ) {
            if ( $this->await_workflow( $item ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signed upload page link for an order that still needs artwork, cached
     * on the order. $refresh re-asks Filecheck (pages the customer views);
     * emails use the cached link. Null when nothing on the order waits.
     *
     * WooCommerce sends the "processing" email before the status-change hook
     * that syncs the order, so an order whose lines wait for artwork but has
     * not been synced yet is synced here first (once per request).
     */
    public function get_upload_link( $order, $refresh = false ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            return null;
        }
        if ( 'yes' !== $order->get_meta( '_filecheck_awaits_artwork' ) ) {
            $id = $order->get_id();
            if ( isset( $this->synced_for_link[ $id ] ) || ! $this->has_lines_awaiting( $order ) ) {
                return null;
            }
            $this->synced_for_link[ $id ] = true;
            if ( true !== $this->push_order_to_filecheck( $order, $order->get_status() ) ) {
                return null;
            }
            $refresh = false; // the sync just fetched it
        }
        $cached = $order->get_meta( '_filecheck_upload_url' );
        if ( $cached && ! $refresh ) {
            return array( 'url' => $cached, 'status' => $order->get_meta( '_filecheck_upload_status' ) );
        }
        $link = Filecheck_API_Client::instance()->get_upload_link( $order->get_id() );
        if ( ! $link ) {
            return $cached ? array( 'url' => $cached, 'status' => $order->get_meta( '_filecheck_upload_status' ) ) : null;
        }
        $order->update_meta_data( '_filecheck_upload_url', esc_url_raw( $link['url'] ) );
        $order->update_meta_data( '_filecheck_upload_status', sanitize_key( $link['status'] ?? '' ) );
        $order->save_meta_data();
        return array( 'url' => $link['url'], 'status' => $link['status'] ?? '' );
    }

    protected function upload_link_enabled() {
        return 'yes' === get_option( 'filecheck_upload_link_in_emails', 'yes' );
    }

    public function email_upload_link( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
        if ( $sent_to_admin || ! $this->upload_link_enabled() ) {
            return;
        }
        $link = $this->get_upload_link( $order );
        if ( ! $link || 'complete' === $link['status'] ) {
            return;
        }
        $heading = __( 'Upload your artwork', 'filecheck' );
        $text    = __( 'We need your artwork for some items on this order before we can print them. Upload your files any time using the link below, and we will check them before we print.', 'filecheck' );
        if ( $plain_text ) {
            echo "\n" . esc_html( strtoupper( $heading ) ) . "\n\n" . esc_html( $text ) . "\n" . esc_url( $link['url'] ) . "\n\n";
            return;
        }
        ?>
        <div style="margin:0 0 24px;padding:16px;border:1px solid #e5e5e5;border-radius:4px;">
            <h2 style="margin-top:0;"><?php echo esc_html( $heading ); ?></h2>
            <p><?php echo esc_html( $text ); ?></p>
            <p style="margin-bottom:0;"><a href="<?php echo esc_url( $link['url'] ); ?>" style="font-weight:bold;"><?php esc_html_e( 'Upload artwork', 'filecheck' ); ?></a></p>
        </div>
        <?php
    }

    public function render_upload_link( $order ) {
        if ( ! $this->upload_link_enabled() ) {
            return;
        }
        $link = $this->get_upload_link( $order, true );
        if ( ! $link || 'complete' === $link['status'] ) {
            return;
        }
        ?>
        <div class="woocommerce-info filecheck-upload-artwork">
            <?php esc_html_e( 'Some items on this order still need your artwork.', 'filecheck' ); ?>
            <a class="button" href="<?php echo esc_url( $link['url'] ); ?>"><?php esc_html_e( 'Upload artwork', 'filecheck' ); ?></a>
        </div>
        <?php
    }

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
                'loading'  => __( 'Loading file details...', 'filecheck' ),
                'error'    => __( 'Could not load file details.', 'filecheck' ),
                'download' => __( 'Download', 'filecheck' ),
                'view'     => __( 'View Job on Filecheck', 'filecheck' ),
                'noFiles'  => __( 'No processed files yet.', 'filecheck' ),
            ),
        ) );
    }

    public function ajax_get_job_details() {
        check_ajax_referer( 'filecheck_order_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'filecheck' ) ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'filecheck' ) ) );
        }

        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Secret key not configured.', 'filecheck' ) ) );
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

    public function sync_order_to_filecheck_by_id( $order_id, $posted_data, $order ) {
        $this->sync_order_to_filecheck( $order );
    }

    public function sync_order_to_filecheck( $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_filecheck_order_synced' ) === 'yes' ) {
            return;
        }

        $result = $this->push_order_to_filecheck( $order, $order->get_status() );

        if ( is_wp_error( $result ) ) {
            // translators: %s: error message.
            $order->add_order_note( sprintf( __( 'Filecheck: Failed to sync order - %s', 'filecheck' ), $result->get_error_message() ) );
        } else {
            $order->update_meta_data( '_filecheck_order_synced', 'yes' );
            $order->save();
        }
    }

    public function sync_order_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $result = $this->push_order_to_filecheck( $order, $new_status );

        if ( is_wp_error( $result ) ) {
            // translators: %s: error message.
            $order->add_order_note( sprintf( __( 'Filecheck: Failed to sync status change - %s', 'filecheck' ), $result->get_error_message() ) );
        }
    }

    private function push_order_to_filecheck( $order, $status ) {
        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            return null;
        }

        $line_items    = array();
        $awaiting      = false;
        $collect_later = in_array( $status, self::AWAIT_STATUSES, true );
        foreach ( $order->get_items() as $item_id => $item ) {
            $job_id     = $item->get_meta( '_filecheck_job_id' );
            $product_id = $item->get_product_id();

            // Artwork after checkout: a line bought without a file, on a
            // product that collects artwork after the order, goes to Filecheck
            // too, so the customer can upload it on the Filecheck upload page.
            // Only once the order is paid (or on hold): see AWAIT_STATUSES.
            $await_workflow = '';
            if ( empty( $job_id ) ) {
                $await_workflow = $collect_later ? $this->await_workflow( $item ) : '';
                if ( ! $await_workflow ) {
                    continue;
                }
                $awaiting = true;
            }

            $product = $item->get_product();

            $item_data = array(
                'itemId'    => (string) $item_id,
                'productId' => (string) $product_id,
                'name'      => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'sku'       => $product ? $product->get_sku() : '',
                'total'     => $order->get_line_total( $item, true ),
                'jobId'     => $job_id ? $job_id : null,
            );
            if ( $await_workflow ) {
                $item_data['awaitArtwork'] = true;
                $item_data['workflowId']   = $await_workflow;
            }

            $excluded_keys = array( '_filecheck_job_id', '_filecheck_downloaded_files', '_reduced_stock', '_qty' );
            foreach ( $item->get_meta_data() as $meta ) {
                $meta_data = $meta->get_data();
                if ( ! in_array( $meta_data['key'], $excluded_keys, true ) && strpos( $meta_data['key'], '_' ) !== 0 ) {
                    $item_data['meta'][ $meta_data['key'] ] = $meta_data['value'];
                }
            }

            $line_items[] = $item_data;
        }

        if ( empty( $line_items ) ) {
            return null;
        }

        $customer = array(
            'id'    => $order->get_customer_id() ? (string) $order->get_customer_id() : null,
            'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );

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
            // What the customer sees and types on the upload page.
            'orderName' => (string) $order->get_order_number(),
            'status'   => $status,
            'currency' => $order->get_currency(),
            'total'    => $order->get_total(),
            'customer' => $customer,
            'items'    => $line_items,
        );

        if ( ! empty( $shipping ) ) {
            $payload['shippingAddress'] = $shipping;
        }

        $result = Filecheck_API_Client::instance()->sync_order( $order->get_id(), $payload, $secret_key );

        // Fetch the upload link now, so the order emails that follow can show it.
        if ( true === $result && $awaiting ) {
            $order->update_meta_data( '_filecheck_awaits_artwork', 'yes' );
            $order->save_meta_data();
            $this->get_upload_link( $order, true );
        }

        return $result;
    }

    public function process_order_files( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_filecheck_processed' ) === 'yes' ) {
            return;
        }

        $secret_key = get_option( 'filecheck_secret_key' );
        if ( empty( $secret_key ) ) {
            $order->add_order_note( __( 'Filecheck Error: Could not download files because Secret Key is not configured.', 'filecheck' ) );
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
                // translators: 1: Filecheck job ID, 2: error message.
                $order->add_order_note( sprintf( __( 'Filecheck Error fetching Job %1$s: %2$s', 'filecheck' ), $job_id, $job->get_error_message() ) );
                continue;
            }

            $runs = isset( $job['runs'] ) ? $job['runs'] : array();
            if ( empty( $runs ) && isset( $job['runIds'] ) ) {
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

                // The signed URL of the file to fulfil (the corrected file,
                // else the upload). Missing while the run is still processing.
                $download_url = isset( $run['downloadUrl'] ) ? $run['downloadUrl'] : '';
                if ( empty( $download_url ) ) {
                    // translators: %s: Filecheck run ID.
                    $order->add_order_note( sprintf( __( 'Filecheck: No file is available to download yet for Run %s.', 'filecheck' ), $run_id ) );
                    continue;
                }

                $filename = isset( $run['name'] ) ? sanitize_file_name( $run['name'] ) : '';
                if ( empty( $filename ) ) {
                    $ext = '.pdf';
                    if ( isset( $run['acceptKey'] ) && $run['acceptKey'] === 'raster' ) {
                        $ext = '.png';
                    }
                    $filename = $job_id . '-' . $run_id . $ext;
                }

                $upload_dir = wp_upload_dir();
                $secure_dir = $upload_dir['basedir'] . '/filecheck-secure';

                if ( ! file_exists( $secure_dir ) ) {
                    wp_mkdir_p( $secure_dir );
                    file_put_contents( $secure_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a protective .htaccess to the uploads dir.
                    file_put_contents( $secure_dir . '/index.php', "<?php // Silence\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a blank index for directory protection.
                }

                // Run id keeps two same-named files in one order apart.
                $local_filename = time() . '_' . $run_id . '_' . $filename;
                $local_filepath = $secure_dir . '/' . $local_filename;

                // No Authorization header: the URL is presigned, and storage
                // rejects a request that carries both.
                $download_response = wp_remote_get( $download_url, array(
                    'timeout'  => 300,
                    'stream'   => true,
                    'filename' => $local_filepath,
                ) );

                if ( is_wp_error( $download_response ) ) {
                    // translators: 1: Filecheck run ID, 2: error message.
                    $order->add_order_note( sprintf( __( 'Filecheck Error downloading output for Run %1$s: %2$s', 'filecheck' ), $run_id, $download_response->get_error_message() ) );
                    continue;
                }

                $response_code = wp_remote_retrieve_response_code( $download_response );
                if ( $response_code !== 200 ) {
                    if ( file_exists( $local_filepath ) ) {
                        wp_delete_file( $local_filepath );
                    }
                    // translators: 1: Filecheck run ID, 2: HTTP status code.
                    $order->add_order_note( sprintf( __( 'Filecheck Error downloading output for Run %1$s: API returned HTTP code %2$d', 'filecheck' ), $run_id, $response_code ) );
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
                // translators: 1: order item name, 2: comma-separated list of file names.
                $order->add_order_note( sprintf( __( 'Filecheck: Downloaded output files for item %1$s: %2$s', 'filecheck' ), $item->get_name(), implode( ', ', $file_list ) ) );
                $processed_any = true;
            }
        }

        if ( $processed_any ) {
            $order->update_meta_data( '_filecheck_processed', 'yes' );
            $order->save();
        }
    }

    public function add_order_metabox() {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        add_meta_box(
            'filecheck_order_uploads',
            __( 'Filecheck Uploads', 'filecheck' ),
            array( $this, 'render_order_metabox' ),
            $screen,
            'side',
            'default'
        );
    }

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
            echo '<p>' . esc_html__( 'No Filecheck items in this order.', 'filecheck' ) . '</p>';
            return;
        }

        echo '<div id="filecheck-job-panel" data-order-id="' . esc_attr( $order->get_id() ) . '">';
        echo '<p class="filecheck-loading">' . esc_html__( 'Loading file details...', 'filecheck' ) . '</p>';
        echo '</div>';
    }

    public function handle_secure_download() {
        if ( isset( $_GET['action'] ) && 'filecheck_download' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to download this file.', 'filecheck' ) );
            }

            $order_id   = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
            $item_id    = isset( $_GET['item_id'] ) ? intval( $_GET['item_id'] ) : 0;
            $file_index = isset( $_GET['file_index'] ) ? intval( $_GET['file_index'] ) : 0;

            if ( ! $order_id || ! $item_id ) {
                wp_die( esc_html__( 'Invalid request parameters.', 'filecheck' ) );
            }

            check_admin_referer( 'filecheck_download_file' );

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_die( esc_html__( 'Order not found.', 'filecheck' ) );
            }

            $item = $order->get_item( $item_id );
            if ( ! $item ) {
                wp_die( esc_html__( 'Order item not found.', 'filecheck' ) );
            }

            $downloaded_files = $item->get_meta( '_filecheck_downloaded_files' );
            if ( empty( $downloaded_files ) || ! isset( $downloaded_files[ $file_index ] ) ) {
                wp_die( esc_html__( 'File reference not found.', 'filecheck' ) );
            }

            $file = $downloaded_files[ $file_index ];
            $filepath = $file['local_path'];

            if ( ! file_exists( $filepath ) ) {
                wp_die( esc_html__( 'Physical file does not exist on disk.', 'filecheck' ) );
            }

            if ( ob_get_level() ) {
                ob_end_clean();
            }

            header( 'Content-Description: File Transfer' );
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file['filename'] ) . '"' );
            header( 'Expires: 0' );
            header( 'Cache-Control: must-revalidate' );
            header( 'Pragma: public' );
            header( 'Content-Length: ' . filesize( $filepath ) );

            // Stream the file. WP_Filesystem has no streaming read API and loading large
            // print files fully into memory is not viable, so readfile() is used here.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a binary download; no WP_Filesystem equivalent.
            readfile( $filepath );
            exit;
        }
    }
}
