<?php
/**
 * Plugin Name:       GT Link Manager
 * Plugin URI:        https://wordpress.org/plugins/gt-link-manager/
 * Description:       Fast pretty-link manager with direct redirects and low overhead.
 * Version:           1.4.0
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

if ( ! defined( 'GTLM_VERSION' ) ) {
	define( 'GTLM_VERSION', '1.4.0' );
}

if ( ! defined( 'GTLM_FILE' ) ) {
	define( 'GTLM_FILE', __FILE__ );
}

if ( ! defined( 'GTLM_PATH' ) ) {
	define( 'GTLM_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GTLM_URL' ) ) {
	define( 'GTLM_URL', plugin_dir_url( __FILE__ ) );
}

require_once GTLM_PATH . 'includes/class-gt-link-settings.php';
require_once GTLM_PATH . 'includes/class-gt-link-activator.php';
require_once GTLM_PATH . 'includes/class-gt-link-deactivator.php';
require_once GTLM_PATH . 'includes/class-gt-link-db.php';
require_once GTLM_PATH . 'includes/class-gt-link-redirect.php';
require_once GTLM_PATH . 'includes/class-gt-link-admin-pages.php';
require_once GTLM_PATH . 'includes/class-gt-link-admin.php';
require_once GTLM_PATH . 'includes/class-gt-link-rest-api.php';
require_once GTLM_PATH . 'includes/class-gt-link-block-editor.php';
require_once GTLM_PATH . 'includes/class-gt-link-import.php';

register_activation_hook( GTLM_FILE, array( 'GTLM_Activator', 'activate' ) );
register_deactivation_hook( GTLM_FILE, array( 'GTLM_Deactivator', 'deactivate' ) );

/**
 * Bootstrap plugin services.
 */
function gtlm_bootstrap(): void {
	// Run schema migrations when DB version is behind plugin version.
	if ( is_admin() ) {
		GTLM_Activator::maybe_upgrade();
	}

	$settings = GTLM_Settings::get_instance();
	$db       = new GTLM_DB();

	GTLM_Redirect::init( $db, $settings );
	GTLM_Admin::init( $db, $settings );
	GTLM_REST_API::init( $db, $settings );
	GTLM_Block_Editor::init( $settings );
}
add_action( 'plugins_loaded', 'gtlm_bootstrap' );
