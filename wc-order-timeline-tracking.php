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

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCOTL_VERSION', '1.5.0' );
define( 'WCOTL_PLUGIN_FILE', __FILE__ );
define( 'WCOTL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCOTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-db.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-icons.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-shortcode.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-admin.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-admin-presets.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-order-metabox.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-refund-column.php';

// Auto-tracking
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-tracking-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-17track-provider.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-auto-sync.php';
require_once WCOTL_PLUGIN_DIR . 'includes/class-wcotl-settings.php';

class WCOTL_Plugin {
    public static function init() {
        WCOTL_DB::init();
        WCOTL_Shortcode::init();
        WCOTL_Admin::init();
        WCOTL_Admin_Presets::init();
        WCOTL_Order_Metabox::init();
        WCOTL_Refund_Column::init();
        // Auto-tracking
        WCOTL_Auto_Sync::init();
        WCOTL_Settings::init();
    }
}

register_activation_hook( __FILE__, array( 'WCOTL_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCOTL_Auto_Sync', 'deactivate' ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * Must be hooked on `before_woocommerce_init` — that is the only moment
 * WooCommerce reads these declarations. The plugin already supports HPOS:
 * WCOTL_Refund_Column and WCOTL_Order_Metabox both handle the
 * `woocommerce_page_wc-orders` screen alongside the classic CPT screen.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', // HPOS feature key
            __FILE__,
            true                   // true = compatible
        );
    }
} );

WCOTL_Plugin::init();

