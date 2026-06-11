<?php
/**
 * Plugin Name: Filecheck
 * Plugin URI: https://filecheck.io
 * Description: Integrates the Filecheck Element on product pages to validate customer uploads before adding to cart.
 * Version: 1.0.0
 * Author: Filecheck
 * Author URI: https://filecheck.io
 * Text Domain: filecheck-woocommerce
 * Domain Path: /languages
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'FILECHECK_VERSION', '1.0.0' );
define( 'FILECHECK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILECHECK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FILECHECK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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
