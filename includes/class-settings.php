<?php
defined( 'ABSPATH' ) || exit;

class Filecheck_Settings {
    
    protected static $_instance = null;
    
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    public function __construct() {
        // Add left sidebar admin menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // Add AJAX actions for test connection
        add_action( 'wp_ajax_filecheck_test_connection', array( $this, 'test_connection' ) );
        
        // Enqueue settings assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __( 'Filecheck', 'filecheck-woocommerce' ),
            __( 'Filecheck', 'filecheck-woocommerce' ),
            'manage_woocommerce',
            'filecheck-settings',
            array( $this, 'render_settings_page' ),
            FILECHECK_PLUGIN_URL . 'assets/images/icon.svg',
            58 // Position below WooCommerce
        );
    }
    
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'filecheck-woocommerce' ) );
        }
        
        // Process Save Request
        $message = '';
        $error = false;
        
        if ( isset( $_POST['filecheck_save_settings'] ) && check_admin_referer( 'filecheck_save_settings_action', 'filecheck_save_settings_nonce' ) ) {
            update_option( 'filecheck_publishable_key', sanitize_text_field( $_POST['filecheck_publishable_key'] ) );
            update_option( 'filecheck_secret_key', sanitize_text_field( $_POST['filecheck_secret_key'] ) );
            update_option( 'filecheck_agent_id', sanitize_text_field( $_POST['filecheck_agent_id'] ) );
            update_option( 'filecheck_api_url', esc_url_raw( $_POST['filecheck_api_url'] ) );
            update_option( 'filecheck_default_rule_id', sanitize_text_field( $_POST['filecheck_default_rule_id'] ) );
            update_option( 'filecheck_presentation', sanitize_text_field( $_POST['filecheck_presentation'] ) );
            update_option( 'filecheck_block_checkout', isset( $_POST['filecheck_block_checkout'] ) ? 'yes' : 'no' );
            
            $message = __( 'Settings saved successfully.', 'filecheck-woocommerce' );
        }
        
        // Retrieve settings values
        $publishable_key = get_option( 'filecheck_publishable_key' );
        $secret_key      = get_option( 'filecheck_secret_key' );
        $agent_id        = get_option( 'filecheck_agent_id' );
        $api_url         = get_option( 'filecheck_api_url', 'https://api.filecheck.io' );
        $default_rule_id = get_option( 'filecheck_default_rule_id' );
        $presentation    = get_option( 'filecheck_presentation', 'inline' );
        $block_checkout   = get_option( 'filecheck_block_checkout', 'yes' );
        
        // Fetch active rules
        $rules = Filecheck_API_Client::instance()->get_rules( $secret_key );
        
        ?>
        <div class="wrap">
            <div style="display: flex; align-items: center; margin-bottom: 20px; padding: 10px 0; border-bottom: 1px solid #ccd0d4;">
                <img src="<?php echo esc_url( FILECHECK_PLUGIN_URL . 'assets/images/icon.svg' ); ?>" style="width: 42px; height: 42px; margin-right: 15px;" alt="Filecheck Logo">
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; line-height: 42px; color: #1d2327;"><?php _e( 'Filecheck Settings', 'filecheck-woocommerce' ); ?></h1>
            </div>
            
            <?php if ( ! empty( $message ) ) : ?>
                <div class="updated notice is-dismissible">
                    <p><strong><?php echo esc_html( $message ); ?></strong></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'filecheck_save_settings_action', 'filecheck_save_settings_nonce' ); ?>
                
                <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                    <h2><?php _e( 'API Credentials', 'filecheck-woocommerce' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="filecheck_publishable_key"><?php _e( 'Publishable Key', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <input name="filecheck_publishable_key" type="text" id="filecheck_publishable_key" value="<?php echo esc_attr( $publishable_key ); ?>" class="regular-text">
                                    <p class="description"><?php _e( 'Your Filecheck Publishable Key (pk_live_... or pk_test_...).', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_secret_key"><?php _e( 'Secret Key', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <input name="filecheck_secret_key" type="password" id="filecheck_secret_key" value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text">
                                    <p class="description"><?php _e( 'Your Filecheck Secret Key (sk_live_... or sk_test_...). Keep this private.', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_agent_id"><?php _e( 'Agent ID (Optional)', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <input name="filecheck_agent_id" type="text" id="filecheck_agent_id" value="<?php echo esc_attr( $agent_id ); ?>" class="regular-text">
                                    <p class="description"><?php _e( 'Optional sub-tenant or agent identifier.', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_api_url"><?php _e( 'API Base URL', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <input name="filecheck_api_url" type="text" id="filecheck_api_url" value="<?php echo esc_url( $api_url ); ?>" class="regular-text">
                                    <p class="description"><?php _e( 'Override URL for development environment. Default is https://api.filecheck.io', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                    <h2><?php _e( 'Global Configuration', 'filecheck-woocommerce' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="filecheck_default_rule_id"><?php _e( 'Default Rule', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <select name="filecheck_default_rule_id" id="filecheck_default_rule_id" style="min-width: 25em;">
                                        <option value=""><?php _e( 'Select a rule...', 'filecheck-woocommerce' ); ?></option>
                                        <?php if ( is_array( $rules ) ) : ?>
                                            <?php foreach ( $rules as $rule ) : ?>
                                                <?php if ( isset( $rule['id'] ) && isset( $rule['title'] ) ) : ?>
                                                    <option value="<?php echo esc_attr( $rule['id'] ); ?>" <?php selected( $default_rule_id, $rule['id'] ); ?>>
                                                        <?php echo esc_html( $rule['title'] . ' (' . $rule['id'] . ')' ); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description"><?php _e( 'Default validation rule. Can be overridden per product.', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_presentation"><?php _e( 'Presentation Mode', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <select name="filecheck_presentation" id="filecheck_presentation">
                                        <option value="inline" <?php selected( $presentation, 'inline' ); ?>><?php _e( 'Inline (embedded in product page)', 'filecheck-woocommerce' ); ?></option>
                                        <option value="dialog" <?php selected( $presentation, 'dialog' ); ?>><?php _e( 'Dialog (button opens modal overlay)', 'filecheck-woocommerce' ); ?></option>
                                    </select>
                                    <p class="description"><?php _e( 'How the upload widget is presented to customers.', 'filecheck-woocommerce' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_block_checkout"><?php _e( 'Enable Gating', 'filecheck-woocommerce' ); ?></label></th>
                                <td>
                                    <input name="filecheck_block_checkout" type="checkbox" id="filecheck_block_checkout" value="yes" <?php checked( $block_checkout, 'yes' ); ?>>
                                    <span class="description"><?php _e( 'Disable "Add to Cart" button until valid files are uploaded.', 'filecheck-woocommerce' ); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <input type="submit" name="filecheck_save_settings" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'filecheck-woocommerce' ); ?>">
                </div>
            </form>
            
            <div class="card" style="max-width: 800px; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;"><?php _e( 'Connection Status', 'filecheck-woocommerce' ); ?></h3>
                <p><?php _e( 'Test if your API keys can communicate with the Filecheck servers.', 'filecheck-woocommerce' ); ?></p>
                <button type="button" id="filecheck-test-connection" class="button button-secondary"><?php _e( 'Test Connection', 'filecheck-woocommerce' ); ?></button>
                <span id="filecheck-connection-result" style="margin-left: 10px; font-weight: 500; display: inline-block; vertical-align: middle;"></span>
            </div>
        </div>
        <?php
    }
    
    public function test_connection() {
        check_ajax_referer( 'filecheck_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'filecheck-woocommerce' ) ) );
        }
        
        $publishable_key = isset( $_POST['publishable_key'] ) ? sanitize_text_field( $_POST['publishable_key'] ) : '';
        $secret_key      = isset( $_POST['secret_key'] ) ? sanitize_text_field( $_POST['secret_key'] ) : '';
        
        if ( empty( $publishable_key ) || empty( $secret_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Both Publishable Key and Secret Key are required to test connection.', 'filecheck-woocommerce' ) ) );
        }
        
        $result = Filecheck_API_Client::instance()->verify_keys( $publishable_key, $secret_key );
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        
        wp_send_json_success( array( 'message' => __( 'Connection successful! Keys are valid.', 'filecheck-woocommerce' ) ) );
    }
    
    public function enqueue_admin_assets( $hook ) {
        // Enqueue only on our custom admin settings page
        if ( 'toplevel_page_filecheck-settings' !== $hook ) {
            return;
        }
        
        wp_enqueue_script(
            'filecheck-admin',
            FILECHECK_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            FILECHECK_VERSION,
            true
        );
        
        wp_localize_script( 'filecheck-admin', 'filecheck_admin_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'filecheck_admin_nonce' ),
        ) );
        
        wp_enqueue_style(
            'filecheck-admin',
            FILECHECK_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            FILECHECK_VERSION
        );
    }
}
