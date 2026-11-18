<?php
/**
 * Plugin Name: Filecheck
 * Plugin URI: https://filecheck.io/docs/tutorials/wordpress-installation
 * Description: Print preflight for WooCommerce. Embeds the Filecheck Element on product pages to validate customer artwork (resolution, bleed, CMYK, fonts) as it is uploaded, keeps Add to Cart locked until every file passes, and delivers print-ready output files to the order.
 * Version: 1.0.7
 * Author: Print.App ApS
 * Author URI: https://filecheck.io
 * Text Domain: filecheck
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'FILECHECK_VERSION', '1.0.7' );
define( 'FILECHECK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILECHECK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FILECHECK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Add a "Settings" action link on the Plugins screen (next to Deactivate)
add_filter( 'plugin_action_links_' . FILECHECK_PLUGIN_BASENAME, 'filecheck_plugin_action_links' );
function filecheck_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=filecheck-settings' ) ) . '">' . esc_html__( 'Settings', 'filecheck' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

// Add a "Docs" link to the plugin meta row (Version | By ... | Visit plugin site)
add_filter( 'plugin_row_meta', 'filecheck_plugin_row_meta', 10, 2 );
function filecheck_plugin_row_meta( $links, $file ) {
    if ( FILECHECK_PLUGIN_BASENAME === $file ) {
        $links[] = '<a href="https://filecheck.io/docs/tutorials/wordpress-installation" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'filecheck' ) . '</a>';
    }
    return $links;
}

// Declare compatibility with High-Performance Order Storage (HPOS)
add_action( 'before_woocommerce_init', 'filecheck_declare_hpos_compatibility' );
function filecheck_declare_hpos_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}

// Include required classes
require_once FILECHECK_PLUGIN_DIR . 'includes/class-api-client.php';
require_once FILECHECK_PLUGIN_DIR . 'includes/class-settings.php';
require_once FILECHECK_PLUGIN_DIR . 'includes/class-product.php';
require_once FILECHECK_PLUGIN_DIR . 'includes/class-frontend.php';
require_once FILECHECK_PLUGIN_DIR . 'includes/class-cart.php';
require_once FILECHECK_PLUGIN_DIR . 'includes/class-fulfillment.php';

/**
 * Main Filecheck WooCommerce Plugin Class
 */
class Filecheck_WooCommerce {

    protected static $_instance = null;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {
        // Only run if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Initialize components
        Filecheck_API_Client::instance();
        Filecheck_Settings::instance();
        Filecheck_Product::instance();
        Filecheck_Frontend::instance();
        Filecheck_Cart::instance();
        Filecheck_Fulfillment::instance();
    }
}

Filecheck_WooCommerce::instance();
