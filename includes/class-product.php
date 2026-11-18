<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Product {

    protected static $_instance = null;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel_content' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_settings' ) );
    }

    public function add_product_data_tab( $tabs ) {
        $tabs['filecheck'] = array(
            'label'    => __( 'Filecheck', 'filecheck' ),
            'target'   => 'filecheck_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable' ),
            'priority' => 75,
        );
        return $tabs;
    }

    /** Artwork timing choices (artwork after checkout). */
    public static function timing_options( $with_default = true ) {
        $options = array(
            'checkout'    => __( 'At checkout (before add to cart)', 'filecheck' ),
            'either'      => __( 'Optional, or after checkout', 'filecheck' ),
            'after_order' => __( 'After checkout only', 'filecheck' ),
        );
        return $with_default ? array( '' => __( 'Use Global Default', 'filecheck' ) ) + $options : $options;
    }

    /** This product's resolved timing: product setting, then the global default. */
    public static function artwork_timing( $product_id ) {
        $valid  = array( 'checkout', 'either', 'after_order' );
        $timing = get_post_meta( $product_id, '_filecheck_artwork_timing', true );
        if ( ! in_array( $timing, $valid, true ) ) {
            $timing = get_option( 'filecheck_artwork_timing', 'checkout' );
        }
        return in_array( $timing, $valid, true ) ? $timing : 'checkout';
    }

    /** The product's workflow id with "global" resolved; '' when Filecheck is off for it. */
    public static function workflow_id( $product_id ) {
        $workflow_id = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( 'global' === $workflow_id ) {
            $workflow_id = get_option( 'filecheck_default_workflow_id' );
        }
        return ( empty( $workflow_id ) || 'none' === $workflow_id ) ? '' : $workflow_id;
    }

    public function product_data_panel_content() {
        global $post;

        $product_id = $post->ID;

        $workflow_id  = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( empty( $workflow_id ) ) {
            $workflow_id = 'none';
        }
        $connector_id = get_post_meta( $product_id, '_filecheck_connector_id', true );
        $presentation = get_post_meta( $product_id, '_filecheck_presentation', true );
        $timing       = get_post_meta( $product_id, '_filecheck_artwork_timing', true );

        $workflows = Filecheck_API_Client::instance()->get_workflows();
        $workflow_options = array(
            'none'   => __( 'None (Disabled)', 'filecheck' ),
            'global' => __( 'Use Global Default', 'filecheck' ),
        );

        if ( is_array( $workflows ) ) {
            foreach ( $workflows as $workflow ) {
                if ( isset( $workflow['id'] ) && isset( $workflow['title'] ) ) {
                    $workflow_options[ $workflow['id'] ] = $workflow['title'];
                }
            }
        }

        $connectors = Filecheck_API_Client::instance()->get_connectors();
        $connector_options = array(
            '' => __( 'None', 'filecheck' ),
        );

        if ( is_array( $connectors ) ) {
            foreach ( $connectors as $connector ) {
                if ( isset( $connector['id'] ) && isset( $connector['title'] ) ) {
                    $connector_options[ $connector['id'] ] = $connector['title'];
                }
            }
        }

        ?>
        <div id="filecheck_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_select( array(
                    'id'            => '_filecheck_workflow_id',
                    'label'         => __( 'Workflow', 'filecheck' ),
                    'description'   => __( 'Select the workflow to validate uploads against. "None" disables Filecheck for this product.', 'filecheck' ),
                    'options'       => $workflow_options,
                    'value'         => $workflow_id,
                ) );

                woocommerce_wp_select( array(
                    'id'          => '_filecheck_connector_id',
                    'label'       => __( 'Connector', 'filecheck' ),
                    'description' => __( 'Optional. Syncs Filecheck file details to elements on this product page.', 'filecheck' ),
                    'options'     => $connector_options,
                    'value'       => $connector_id,
                ) );

                woocommerce_wp_select( array(
                    'id'            => '_filecheck_presentation',
                    'label'         => __( 'Presentation Mode', 'filecheck' ),
                    'description'   => __( 'Choose how the Filecheck element is displayed on the product page.', 'filecheck' ),
                    'options'       => array(
                        ''       => __( 'Use Global Default', 'filecheck' ),
                        'inline' => __( 'Inline (embedded in product page)', 'filecheck' ),
                        'dialog' => __( 'Dialog (button opens modal overlay)', 'filecheck' ),
                    ),
                    'value'         => $presentation,
                ) );

                woocommerce_wp_select( array(
                    'id'            => '_filecheck_artwork_timing',
                    'label'         => __( 'When to Collect Artwork', 'filecheck' ),
                    'description'   => __( 'At checkout: upload before add to cart. Optional, or after checkout: upload now or later. After checkout only: customers upload after paying, from a link in their order email.', 'filecheck' ),
                    'desc_tip'      => true,
                    'options'       => self::timing_options(),
                    'value'         => $timing,
                ) );
                ?>
            </div>
        </div>
        <?php
    }

    public function save_product_settings( $product_id ) {
        // The woocommerce_process_product_meta hook fires only after WooCommerce has
        // verified the product editor nonce, so these reads are already nonce-protected.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $workflow_id  = isset( $_POST['_filecheck_workflow_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_filecheck_workflow_id'] ) ) : 'none';
        $connector_id = isset( $_POST['_filecheck_connector_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_filecheck_connector_id'] ) ) : '';
        $presentation = isset( $_POST['_filecheck_presentation'] ) ? sanitize_text_field( wp_unslash( $_POST['_filecheck_presentation'] ) ) : '';
        $timing       = isset( $_POST['_filecheck_artwork_timing'] ) ? sanitize_text_field( wp_unslash( $_POST['_filecheck_artwork_timing'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_post_meta( $product_id, '_filecheck_workflow_id', $workflow_id );
        update_post_meta( $product_id, '_filecheck_connector_id', $connector_id );
        update_post_meta( $product_id, '_filecheck_presentation', $presentation );
        update_post_meta( $product_id, '_filecheck_artwork_timing', array_key_exists( $timing, self::timing_options() ) ? $timing : '' );
    }
}
