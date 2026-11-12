<?php
/**
 * Plugin Name: SyncGuard Lite – Supplier Sync Safety for WooCommerce
 * Plugin URI: https://github.com/your-github-username/syncguard-lite
 * Description: Preview supplier CSV changes, detect risky product data, and approve WooCommerce price and stock updates before they are applied.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: SyncGuard Lite Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: syncguard-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYNCGUARD_LITE_VERSION', '1.0.1' );
define( 'SYNCGUARD_LITE_FILE', __FILE__ );
define( 'SYNCGUARD_LITE_PATH', plugin_dir_path( __FILE__ ) );

require_once SYNCGUARD_LITE_PATH . 'includes/class-syncguard-lite.php';

add_action( 'plugins_loaded', array( 'SyncGuard_Lite', 'init' ) );
