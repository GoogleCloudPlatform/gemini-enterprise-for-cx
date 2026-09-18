<?php
/**
 * Plugin Name: Gemini Enterprise for CX
 * Description: Drive sales with an AI agent that's already an expert on your brand and products. Go live instantly on your WooCommerce storefront.
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP:     7.4
 * Version:          0.3.5
 * Author:           Google LLC
 * Author URI:       https://cloud.google.com/gemini
 * License:          GPLv3
 * License URI:      https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:      gemini-enterprise-for-cx
 * Tested up to:     7.1
 * WC requires at least: 7.1
 * WC tested up to: 11.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! defined( 'GECX_VERSION' ) ) {
    define( 'GECX_VERSION', '0.3.5' );
}



// Load core classes.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gecx-auth.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gecx-rest-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gecx-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-gecx-storefront.php';

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, [ 'GECX_Admin', 'activate_plugin' ] );
register_deactivation_hook( __FILE__, [ 'GECX_Admin', 'deactivate_plugin' ] );

/**
 * Initializes the plugin's components if WooCommerce is active.
 *
 * Every component assumes WooCommerce: GECX_Rest_API hooks WooCommerce
 * filters and calls WC(), GECX_Storefront renders on WooCommerce templates,
 * GECX_Admin adds fields to the WooCommerce product editor, and GECX_Auth
 * authenticates WooCommerce Store API requests. The "Requires Plugins" header
 * keeps WordPress from activating this plugin without WooCommerce, but that
 * header is only honoured on WordPress 6.5 and later and this plugin supports
 * 5.9, so on 5.9 through 6.4 a store can deactivate WooCommerce and leave this
 * plugin running against classes and functions that no longer exist.
 *
 * Runs on plugins_loaded so that the check sees the final plugin set.
 * WooCommerce defines its main class while its plugin file is included, which
 * is before any plugins_loaded callback runs, and no hook registered by these
 * constructors fires earlier than this, so nothing is missed by waiting.
 */
function gecx_init_components(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    new GECX_Auth();
    new GECX_Rest_API();
    new GECX_Admin( __FILE__ );
    new GECX_Storefront( __FILE__ );
}
add_action( 'plugins_loaded', 'gecx_init_components' );

// Declare HPOS and Cart & Checkout Blocks compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
