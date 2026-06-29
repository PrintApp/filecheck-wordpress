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

    public function product_data_panel_content() {
        global $post;

        $product_id = $post->ID;

        $workflow_id  = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( empty( $workflow_id ) ) {
            $workflow_id = 'none';
        }
        $connector_id = get_post_meta( $product_id, '_filecheck_connector_id', true );
        $presentation = get_post_meta( $product_id, '_filecheck_presentation', true );

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
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_post_meta( $product_id, '_filecheck_workflow_id', $workflow_id );
        update_post_meta( $product_id, '_filecheck_connector_id', $connector_id );
        update_post_meta( $product_id, '_filecheck_presentation', $presentation );
    }
}
