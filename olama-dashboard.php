<?php
/**
 * Plugin Name: Olama Dashboard
 * Plugin URI:  https://olama.online
 * Description: Card-based ERP hub — premium navigation layer for the Olama School system. Replaces the standard WordPress plugin list with a beautiful, permission-filtered landing page.
 * Version:     1.0.0
 * Author:      د. مصعب الحنيطي
 * Author URI:  https://olama.online
 * Text Domain: olama-dashboard
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OLAMA_DASH_VERSION', '1.0.0' );
define( 'OLAMA_DASH_FILE',    __FILE__ );
define( 'OLAMA_DASH_PATH',    plugin_dir_path( __FILE__ ) );
define( 'OLAMA_DASH_URL',     plugin_dir_url( __FILE__ ) );

require_once OLAMA_DASH_PATH . 'admin/class-olama-dashboard-admin.php';

add_action( 'olama_users_register_modules', function () {
    $registry = new Olama_Dashboard_Admin( false );
    $registry->register_access_module();
}, 5 );

add_action( 'olama_users_register_modules', function () {
    $registry = new Olama_Dashboard_Admin( false );
    $registry->register_card_access_modules();
}, 999 );

/**
 * Capability helper for the authenticated Hub shell.
 *
 * Hub access is intentionally independent of every individual module. The Hub
 * view applies the assigned module and submenu capabilities to its contents.
 */
function olama_dashboard_can_access() {
    return current_user_can( 'manage_options' ) || current_user_can( 'olama_hub_access' );
}

/**
 * Boot the admin controller on plugins_loaded so all sibling Olama plugins
 * are fully loaded. Menu registration itself happens inside admin_menu at
 * priority 99 — after all other plugins have registered at default priority 10.
 */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'olama-dashboard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    if ( is_admin() ) {
        new Olama_Dashboard_Admin();
    }
} );
