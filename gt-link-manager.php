<?php
/**
 * Plugin Name:       GT Link Manager
 * Plugin URI:        https://wordpress.org/plugins/gt-link-manager/
 * Description:       Fast pretty-link manager with direct redirects and low overhead.
 * Version:           1.3.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Gaurav Tiwari
 * Author URI:        https://gauravtiwari.org/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gt-link-manager
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GT_LINK_MANAGER_VERSION' ) ) {
	define( 'GT_LINK_MANAGER_VERSION', '1.3.1' );
}

if ( ! defined( 'GT_LINK_MANAGER_FILE' ) ) {
	define( 'GT_LINK_MANAGER_FILE', __FILE__ );
}

if ( ! defined( 'GT_LINK_MANAGER_PATH' ) ) {
	define( 'GT_LINK_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GT_LINK_MANAGER_URL' ) ) {
	define( 'GT_LINK_MANAGER_URL', plugin_dir_url( __FILE__ ) );
}

require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-settings.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-activator.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-deactivator.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-db.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-redirect.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-admin-pages.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-admin.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-rest-api.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-block-editor.php';
require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-import.php';

register_activation_hook( GT_LINK_MANAGER_FILE, array( 'GT_Link_Activator', 'activate' ) );
register_deactivation_hook( GT_LINK_MANAGER_FILE, array( 'GT_Link_Deactivator', 'deactivate' ) );

/**
 * Bootstrap plugin services.
 */
function gt_link_manager_bootstrap(): void {
	load_plugin_textdomain( 'gt-link-manager', false, dirname( plugin_basename( GT_LINK_MANAGER_FILE ) ) . '/languages' );

	// Run schema migrations when DB version is behind plugin version.
	if ( is_admin() ) {
		GT_Link_Activator::maybe_upgrade();
	}

	$settings = GT_Link_Settings::get_instance();
	$db       = new GT_Link_DB();

	GT_Link_Redirect::init( $db, $settings );
	GT_Link_Admin::init( $db, $settings );
	GT_Link_REST_API::init( $db, $settings );
	GT_Link_Block_Editor::init( $settings );
}
add_action( 'plugins_loaded', 'gt_link_manager_bootstrap' );
