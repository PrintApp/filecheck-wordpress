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
            'label'    => __( 'Filecheck', 'filecheck-woocommerce' ),
            'target'   => 'filecheck_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable' ),
            'priority' => 75,
        );
        return $tabs;
    }
    
    public function product_data_panel_content() {
        global $post;
        
        $product_id = $post->ID;
        
        // Fetch saved metadata values
        $rule_id      = get_post_meta( $product_id, '_filecheck_rule_id', true );
        if ( empty( $rule_id ) ) {
            $rule_id = 'none';
        }
        $presentation = get_post_meta( $product_id, '_filecheck_presentation', true );
        
        // Fetch active rules from Filecheck API
        $rules = Filecheck_API_Client::instance()->get_rules();
        $rule_options = array(
            'none'   => __( 'None (Disabled)', 'filecheck-woocommerce' ),
            'global' => __( 'Use Global Default', 'filecheck-woocommerce' )
        );
        
        if ( is_array( $rules ) ) {
            foreach ( $rules as $rule ) {
                if ( isset( $rule['id'] ) && isset( $rule['title'] ) ) {
                    $rule_options[ $rule['id'] ] = $rule['title'] . ' (' . $rule['id'] . ')';
                }
            }
        }
        
        ?>
        <div id="filecheck_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                // Rule ID override dropdown
                woocommerce_wp_select( array(
                    'id'            => '_filecheck_rule_id',
                    'label'         => __( 'Validation Rule', 'filecheck-woocommerce' ),
                    'description'   => __( 'Select the rule to validate uploads against. "None" disables Filecheck for this product.', 'filecheck-woocommerce' ),
                    'options'       => $rule_options,
                    'value'         => $rule_id,
                ) );
                
                // Presentation mode override dropdown
                woocommerce_wp_select( array(
                    'id'            => '_filecheck_presentation',
                    'label'         => __( 'Presentation Mode', 'filecheck-woocommerce' ),
                    'description'   => __( 'Choose how the Filecheck element is displayed on the product page.', 'filecheck-woocommerce' ),
                    'options'       => array(
                        ''       => __( 'Use Global Default', 'filecheck-woocommerce' ),
                        'inline' => __( 'Inline (embedded in product page)', 'filecheck-woocommerce' ),
                        'dialog' => __( 'Dialog (button opens modal overlay)', 'filecheck-woocommerce' ),
                    ),
                    'value'         => $presentation,
                ) );
                ?>
            </div>
        </div>
        <?php
    }
    
    public function save_product_settings( $product_id ) {
        $rule_id      = isset( $_POST['_filecheck_rule_id'] ) ? sanitize_text_field( $_POST['_filecheck_rule_id'] ) : 'none';
        $presentation = isset( $_POST['_filecheck_presentation'] ) ? sanitize_text_field( $_POST['_filecheck_presentation'] ) : '';
        
        update_post_meta( $product_id, '_filecheck_rule_id', $rule_id );
        update_post_meta( $product_id, '_filecheck_presentation', $presentation );
    }
}
