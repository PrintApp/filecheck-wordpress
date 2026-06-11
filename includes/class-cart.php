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
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 2 );
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_line_item_meta' ), 10, 4 );
    }
    
    /**
     * Server-side guard: block add-to-cart if Filecheck is required but no job ID was submitted.
     */
    public function validate_add_to_cart( $valid, $product_id ) {
        if ( get_option( 'filecheck_block_checkout', 'yes' ) !== 'yes' ) {
            return $valid;
        }

        $workflow_id = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( empty( $workflow_id ) || 'none' === $workflow_id ) {
            return $valid;
        }

        if ( 'global' === $workflow_id ) {
            $workflow_id = get_option( 'filecheck_default_workflow_id' );
            if ( empty( $workflow_id ) ) {
                return $valid;
            }
        }

        if ( empty( $_POST['filecheck_job_id'] ) ) {
            wc_add_notice( __( 'Please upload and validate your file before adding to cart.', 'filecheck-woocommerce' ), 'error' );
            return false;
        }

        return $valid;
    }

    /**
     * Add Filecheck Job ID to the cart item data.
     */
    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( isset( $_POST['filecheck_job_id'] ) && ! empty( $_POST['filecheck_job_id'] ) ) {
            $cart_item_data['filecheck_job_id'] = sanitize_text_field( $_POST['filecheck_job_id'] );
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
        }
    }
}
