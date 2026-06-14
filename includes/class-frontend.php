<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Frontend {
    
    protected static $_instance = null;
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_filecheck_element' ) );
    }
    
    public function render_filecheck_element() {
        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $product_id = $product->get_id();
        
        // Determine Workflow ID
        $workflow_id = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( empty( $workflow_id ) ) {
            $workflow_id = 'none';
        }
        
        if ( 'none' === $workflow_id ) {
            return;
        }
        
        if ( 'global' === $workflow_id ) {
            $workflow_id = get_option( 'filecheck_default_workflow_id' );
        }
        
        if ( empty( $workflow_id ) ) {
            // No workflow configured, output a warning for admin
            if ( current_user_can( 'manage_woocommerce' ) ) {
                echo '<div style="color:red; margin: 10px 0;">' . __( 'Filecheck error: Global default workflow is not configured.', 'filecheck-woocommerce' ) . '</div>';
            }
            return;
        }
        
        ?>
        <div id="fc-slot-<?php echo esc_attr( $product_id ); ?>" 
             class="fc-slot-wrapper" 
             data-product-id="<?php echo esc_attr( $product_id ); ?>"
             data-workflow-id="<?php echo esc_attr( $workflow_id ); ?>"></div>
             
        <input type="hidden" name="filecheck_job_id" id="fc-jobid" value="">
        <?php
    }
    
    public function enqueue_frontend_assets() {
        if ( ! is_product() ) {
            return;
        }
        
        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $product_id = $product->get_id();
        
        // Determine Workflow ID
        $workflow_id = get_post_meta( $product_id, '_filecheck_workflow_id', true );
        if ( empty( $workflow_id ) ) {
            $workflow_id = 'none';
        }
        
        if ( 'none' === $workflow_id ) {
            return;
        }
        
        $pk = get_option( 'filecheck_publishable_key' );
        if ( empty( $pk ) ) {
            return; // Can't render without publishable key
        }
        
        if ( 'global' === $workflow_id ) {
            $workflow_id = get_option( 'filecheck_default_workflow_id' );
        }
        
        $agent_id = get_option( 'filecheck_agent_id' );
        
        // Enqueue Filecheck CDN Element script (pk-specific URL embeds tenant config)
        wp_enqueue_script(
            'filecheck-cdn-element',
            'https://cdn.filecheck.io/element/' . rawurlencode( $pk ) . '/filecheck.js',
            array(),
            null,
            false // load early
        );
        
        // Enqueue our frontend script
        wp_enqueue_script(
            'filecheck-frontend',
            FILECHECK_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            FILECHECK_VERSION,
            true
        );
        
        $connector_id = get_post_meta( $product_id, '_filecheck_connector_id', true );

        // Localize params
        wp_localize_script( 'filecheck-frontend', 'filecheck_params', array(
            'publishable_key' => $pk,
            'agent_id'        => $agent_id,
            'workflow_id'     => $workflow_id,
            'connector_id'    => $connector_id,
            'product_id'      => $product_id,
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'filecheck_save_job' ),
        ) );
        
        // Enqueue our styles
        wp_enqueue_style(
            'filecheck-frontend',
            FILECHECK_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            FILECHECK_VERSION
        );
    }
}
