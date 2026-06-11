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
        global $product;
        
        if ( ! $product ) {
            return;
        }
        
        $product_id = $product->get_id();
        
        // Determine Rule ID
        $rule_id = get_post_meta( $product_id, '_filecheck_rule_id', true );
        if ( empty( $rule_id ) ) {
            $rule_id = 'none';
        }
        
        if ( 'none' === $rule_id ) {
            return;
        }
        
        if ( 'global' === $rule_id ) {
            $rule_id = get_option( 'filecheck_default_rule_id' );
        }
        
        if ( empty( $rule_id ) ) {
            // No rule configured, output a warning for admin
            if ( current_user_can( 'manage_woocommerce' ) ) {
                echo '<div style="color:red; margin: 10px 0;">' . __( 'Filecheck error: Global default rule is not configured.', 'filecheck-woocommerce' ) . '</div>';
            }
            return;
        }
        
        // Determine Presentation
        $presentation = get_post_meta( $product_id, '_filecheck_presentation', true );
        if ( empty( $presentation ) ) {
            $presentation = get_option( 'filecheck_presentation', 'inline' );
        }
        
        ?>
        <div id="fc-slot-<?php echo esc_attr( $product_id ); ?>" 
             class="fc-slot-wrapper" 
             data-product-id="<?php echo esc_attr( $product_id ); ?>"
             data-rule-id="<?php echo esc_attr( $rule_id ); ?>"
             data-presentation="<?php echo esc_attr( $presentation ); ?>"></div>
             
        <input type="hidden" name="filecheck_job_id" id="fc-jobid" value="">
        <input type="hidden" name="filecheck_can_proceed" id="fc-can-proceed" value="0">
        <?php
    }
    
    public function enqueue_frontend_assets() {
        if ( ! is_product() ) {
            return;
        }
        
        global $product;
        if ( ! $product ) {
            return;
        }
        
        $product_id = $product->get_id();
        
        // Determine Rule ID
        $rule_id = get_post_meta( $product_id, '_filecheck_rule_id', true );
        if ( empty( $rule_id ) ) {
            $rule_id = 'none';
        }
        
        if ( 'none' === $rule_id ) {
            return;
        }
        
        $pk = get_option( 'filecheck_publishable_key' );
        if ( empty( $pk ) ) {
            return; // Can't render without publishable key
        }
        
        if ( 'global' === $rule_id ) {
            $rule_id = get_option( 'filecheck_default_rule_id' );
        }
        
        $presentation = get_post_meta( $product_id, '_filecheck_presentation', true );
        if ( empty( $presentation ) ) {
            $presentation = get_option( 'filecheck_presentation', 'inline' );
        }
        
        $agent_id = get_option( 'filecheck_agent_id' );
        $block_checkout = get_option( 'filecheck_block_checkout', 'yes' );
        
        // Enqueue Filecheck CDN Element script
        wp_enqueue_script(
            'filecheck-cdn-element',
            'https://cdn.filecheck.io/element/v1/filecheck.js',
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
        
        // Localize params
        wp_localize_script( 'filecheck-frontend', 'filecheck_params', array(
            'publishable_key' => $pk,
            'agent_id'        => $agent_id,
            'rule_id'         => $rule_id,
            'presentation'    => $presentation,
            'block_checkout'  => ( 'yes' === $block_checkout ),
            'product_id'      => $product_id,
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
