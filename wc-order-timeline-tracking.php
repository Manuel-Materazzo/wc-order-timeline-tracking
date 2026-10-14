<?php
/**
 * Plugin Name:       WC Order Timeline Tracking
 * Plugin URI:        https://github.com/your-org/wc-order-timeline-tracking
 * Description:       Personalized order tracking management with editable timeline and auto-tracking via 17TRACK. Tracking page Shortcode: [wc_order_timeline_tracking]
 * Version:           1.5.0
 * Author:            Custom
 * Text Domain:       wc-order-timeline
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const WCOTL_VERSION     = '1.5.0';
const WCOTL_PLUGIN_FILE = __FILE__;
define( 'WCOTL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCOTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Core
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-db.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-icons.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-shortcode.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-admin.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-admin-presets.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-order-metabox.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-refund-column.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-order-actions.php';

// Auto-tracking
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-tracking-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-17track-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-track123-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-packlink-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-paccofacile-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-fedex-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-auto-sync.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-settings.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-rate-comparator.php';

class WCOTL_Plugin {
    public static function init(): void {
        WCOTL_DB::init();
        WCOTL_Shortcode::init();
        WCOTL_Admin::init();
        WCOTL_Admin_Presets::init();
        WCOTL_Order_Metabox::init();
        WCOTL_Refund_Column::init();
        WCOTL_Order_Actions::init();
        // Auto-tracking
        WCOTL_Auto_Sync::init();
        WCOTL_Settings::init();
        // Rate comparison
        WCOTL_Rate_Comparator::init();
    }
}

register_activation_hook( __FILE__, function() {
    WCOTL_DB::activate();
    WCOTL_Auto_Sync::ensure_scheduled();
} );
register_deactivation_hook( __FILE__, array( 'WCOTL_Auto_Sync', 'deactivate' ) );

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility(
            'custom_order_tables', // HPOS feature key
            __FILE__,
            true                   // true = compatible
        );
    }
} );

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WC Order Timeline Tracking requires WooCommerce to be installed and active.', 'wc-order-timeline' ) . '</p></div>';
        } );
        return;
    }
    WCOTL_Plugin::init();
} );

