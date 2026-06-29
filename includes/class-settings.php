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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'wp_ajax_filecheck_test_connection', array( $this, 'test_connection' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'Filecheck', 'filecheck' ),
            __( 'Filecheck', 'filecheck' ),
            'manage_woocommerce',
            'filecheck-settings',
            array( $this, 'render_settings_page' ),
            FILECHECK_PLUGIN_URL . 'assets/images/icon.svg',
            58
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'filecheck' ) );
        }

        $message = '';

        if ( isset( $_POST['filecheck_save_settings'] ) && check_admin_referer( 'filecheck_save_settings_action', 'filecheck_save_settings_nonce' ) ) {
            update_option( 'filecheck_publishable_key', sanitize_text_field( wp_unslash( $_POST['filecheck_publishable_key'] ?? '' ) ) );
            update_option( 'filecheck_secret_key', sanitize_text_field( wp_unslash( $_POST['filecheck_secret_key'] ?? '' ) ) );
            update_option( 'filecheck_agent_id', sanitize_text_field( wp_unslash( $_POST['filecheck_agent_id'] ?? '' ) ) );
            update_option( 'filecheck_api_url', esc_url_raw( wp_unslash( $_POST['filecheck_api_url'] ?? '' ) ) );
            update_option( 'filecheck_default_workflow_id', sanitize_text_field( wp_unslash( $_POST['filecheck_default_workflow_id'] ?? '' ) ) );

            $message = __( 'Settings saved successfully.', 'filecheck' );
        }

        $publishable_key     = get_option( 'filecheck_publishable_key' );
        $secret_key          = get_option( 'filecheck_secret_key' );
        $agent_id            = get_option( 'filecheck_agent_id' );
        $api_url             = get_option( 'filecheck_api_url', 'https://api.filecheck.io' );
        $default_workflow_id = get_option( 'filecheck_default_workflow_id' );

        $workflows = Filecheck_API_Client::instance()->get_workflows( $secret_key );

        ?>
        <div class="wrap">
            <div style="display: flex; align-items: center; margin-bottom: 20px; padding: 10px 0; border-bottom: 1px solid #ccd0d4;">
                <img src="<?php echo esc_url( FILECHECK_PLUGIN_URL . 'assets/images/icon.svg' ); ?>" style="width: 42px; height: 42px; margin-right: 15px;" alt="Filecheck Logo">
                <h1 style="margin: 0; font-size: 24px; font-weight: 600; line-height: 42px; color: #1d2327;"><?php esc_html_e( 'Filecheck Settings', 'filecheck' ); ?></h1>
            </div>

            <?php if ( ! empty( $message ) ) : ?>
                <div class="updated notice is-dismissible">
                    <p><strong><?php echo esc_html( $message ); ?></strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'filecheck_save_settings_action', 'filecheck_save_settings_nonce' ); ?>

                <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                    <h2><?php esc_html_e( 'API Credentials', 'filecheck' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="filecheck_publishable_key"><?php esc_html_e( 'Publishable Key', 'filecheck' ); ?></label></th>
                                <td>
                                    <input name="filecheck_publishable_key" type="text" id="filecheck_publishable_key" value="<?php echo esc_attr( $publishable_key ); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Your Filecheck Publishable Key (pk_live_... or pk_test_...).', 'filecheck' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_secret_key"><?php esc_html_e( 'Secret Key', 'filecheck' ); ?></label></th>
                                <td>
                                    <input name="filecheck_secret_key" type="password" id="filecheck_secret_key" value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Your Filecheck Secret Key (sk_live_... or sk_test_...). Keep this private.', 'filecheck' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_agent_id"><?php esc_html_e( 'Agent ID (Optional)', 'filecheck' ); ?></label></th>
                                <td>
                                    <input name="filecheck_agent_id" type="text" id="filecheck_agent_id" value="<?php echo esc_attr( $agent_id ); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Optional sub-tenant or agent identifier.', 'filecheck' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="filecheck_api_url"><?php esc_html_e( 'API Base URL', 'filecheck' ); ?></label></th>
                                <td>
                                    <input name="filecheck_api_url" type="text" id="filecheck_api_url" value="<?php echo esc_url( $api_url ); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e( 'Override URL for development environment. Default is https://api.filecheck.io', 'filecheck' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                    <h2><?php esc_html_e( 'Global Configuration', 'filecheck' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="filecheck_default_workflow_id"><?php esc_html_e( 'Default Workflow', 'filecheck' ); ?></label></th>
                                <td>
                                    <select name="filecheck_default_workflow_id" id="filecheck_default_workflow_id" style="min-width: 25em;">
                                        <option value=""><?php esc_html_e( 'Select a workflow...', 'filecheck' ); ?></option>
                                        <?php if ( is_array( $workflows ) ) : ?>
                                            <?php foreach ( $workflows as $workflow ) : ?>
                                                <?php if ( isset( $workflow['id'] ) && isset( $workflow['title'] ) ) : ?>
                                                    <option value="<?php echo esc_attr( $workflow['id'] ); ?>" <?php selected( $default_workflow_id, $workflow['id'] ); ?>>
                                                        <?php echo esc_html( $workflow['title'] ); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Default workflow. Can be overridden per product.', 'filecheck' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-bottom: 20px;">
                    <input type="submit" name="filecheck_save_settings" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'filecheck' ); ?>">
                </div>
            </form>

            <div class="card" style="max-width: 800px; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Connection Status', 'filecheck' ); ?></h3>
                <p><?php esc_html_e( 'Test if your API keys can communicate with the Filecheck servers.', 'filecheck' ); ?></p>
                <button type="button" id="filecheck-test-connection" class="button button-secondary"><?php esc_html_e( 'Test Connection', 'filecheck' ); ?></button>
                <span id="filecheck-connection-result" style="margin-left: 10px; font-weight: 500; display: inline-block; vertical-align: middle;"></span>
            </div>
        </div>
        <?php
    }

    public function test_connection() {
        check_ajax_referer( 'filecheck_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'filecheck' ) ) );
        }

        $publishable_key = isset( $_POST['publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['publishable_key'] ) ) : '';
        $secret_key      = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) : '';

        if ( empty( $publishable_key ) || empty( $secret_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Both Publishable Key and Secret Key are required to test connection.', 'filecheck' ) ) );
        }

        $result = Filecheck_API_Client::instance()->verify_keys( $publishable_key, $secret_key );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Connection successful! Keys are valid.', 'filecheck' ) ) );
    }

    public function enqueue_admin_assets( $hook ) {
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
