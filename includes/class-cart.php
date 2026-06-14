<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Cart {
    
    protected static $_instance = null;
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function __construct() {
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
        add_action( 'woocommerce_order_item_meta_end', array( $this, 'order_item_meta_end' ), 10, 4 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_item_meta' ) );
        add_action( 'wp_ajax_filecheck_save_job',        array( $this, 'save_job_id_to_session' ) );
        add_action( 'wp_ajax_nopriv_filecheck_save_job', array( $this, 'save_job_id_to_session' ) );
    }

    /**
     * AJAX: persist a Filecheck jobId into the WC session so AJAX-cart plugins
     * can still read it during add-to-cart validation.
     */
    public function save_job_id_to_session() {
        check_ajax_referer( 'filecheck_save_job', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $job_id     = sanitize_text_field( $_POST['job_id'] ?? '' );

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
        }

        if ( ! WC()->session ) {
            wp_send_json_error( array( 'message' => 'Session unavailable.' ) );
        }

        $session_key = 'fc_job_' . $product_id;

        if ( $job_id ) {
            WC()->session->set( $session_key, $job_id );
        } else {
            WC()->session->__unset( $session_key );
        }

        wp_send_json_success();
    }

    /**
     * Hide internal Filecheck meta keys from the admin order line-item display.
     */
    public function hide_internal_item_meta( $keys ) {
        $keys[] = '_filecheck_job_id';
        $keys[] = '_filecheck_downloaded_files';
        return $keys;
    }

    /**
     * Customer-friendly "Uploaded Files" line in order emails and order details
     * (instead of exposing the raw Filecheck job ID).
     */
    public function order_item_meta_end( $item_id, $item, $order, $plain_text = false ) {
        if ( ! is_a( $item, 'WC_Order_Item' ) || empty( $item->get_meta( '_filecheck_job_id' ) ) ) {
            return;
        }
        if ( $plain_text ) {
            echo "\n" . esc_html__( 'Uploaded Files: Provided', 'filecheck-woocommerce' ) . "\n";
        } else {
            echo '<p class="filecheck-uploaded" style="margin:6px 0 0;font-size:0.9em;">' . esc_html__( 'Uploaded Files: ✓ Provided', 'filecheck-woocommerce' ) . '</p>';
        }
    }
    
    /**
     * Add Filecheck Job ID to the cart item data.
     */
    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        $job_id = '';
        if ( ! empty( $_POST['filecheck_job_id'] ) ) {
            $job_id = sanitize_text_field( $_POST['filecheck_job_id'] );
        } elseif ( WC()->session ) {
            $job_id = WC()->session->get( 'fc_job_' . $product_id, '' );
        }
        if ( $job_id ) {
            $cart_item_data['filecheck_job_id'] = $job_id;
        }
        return $cart_item_data;
    }
    
    /**
     * Display Filecheck status in Cart and Checkout pages.
     */
    public function get_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['filecheck_job_id'] ) && ! empty( $cart_item['filecheck_job_id'] ) ) {
            $item_data[] = array(
                'name'    => __( 'Files Uploaded', 'filecheck-woocommerce' ),
                'display' => __( 'Yes [✓]', 'filecheck-woocommerce' ),
            );
        }
        return $item_data;
    }
    
    /**
     * Save Filecheck Job ID into Order Line Item meta.
     */
    public function add_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['filecheck_job_id'] ) && ! empty( $values['filecheck_job_id'] ) ) {
            $item->update_meta_data( '_filecheck_job_id', $values['filecheck_job_id'] );
            // Clear the WC session entry now that the job ID is committed to the order.
            if ( WC()->session ) {
                $product_id = $item->get_product_id();
                WC()->session->__unset( 'fc_job_' . $product_id );
            }
        }
    }
}
