<?php
/**
 * Plugin Name:       GT Link Manager
 * Plugin URI:        https://wordpress.org/plugins/gt-link-manager/
 * Description:       Fast pretty-link manager with direct redirects and low overhead.
 * Version:           1.9.0-rc.4
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
	define( 'GTLM_VERSION', '1.9.0-rc.4' );
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
require_once GTLM_PATH . 'includes/class-gt-link-geo.php';
require_once GTLM_PATH . 'includes/class-gt-link-redirect.php';

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
	if ( is_admin() ) {
		require_once GTLM_PATH . 'includes/class-gt-link-import.php';
		require_once GTLM_PATH . 'includes/class-gt-link-admin-pages.php';
		require_once GTLM_PATH . 'includes/class-gt-link-admin.php';
		require_once GTLM_PATH . 'includes/class-gt-link-block-editor.php';
		GTLM_Admin::init( $db, $settings );
		GTLM_Block_Editor::init( $settings );
	}
	add_action( 'rest_api_init', 'gtlm_register_rest_api' );
	if ( $settings->analytics_initialized() ) {
		add_filter( 'cron_schedules', 'gtlm_analytics_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Opt-in bounded five-minute aggregation with a 15-minute health lease.
		add_action( 'gtlm_analytics_maintenance', 'gtlm_run_analytics_maintenance' );
		add_action( 'admin_init', 'gtlm_upgrade_analytics' );
		// Recover a missing job only in control/worker contexts, never on a visitor redirect.
		if ( ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) && ! wp_next_scheduled( 'gtlm_analytics_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'gtlm_five_minutes', 'gtlm_analytics_maintenance' );
		}
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once GTLM_PATH . 'includes/class-gtlm-analytics-cli.php';
		WP_CLI::add_command( 'gt-link-manager analytics', 'GTLM_Analytics_CLI' );
	}

	add_action( 'gtlm_purge_trash', 'gtlm_run_trash_purge' );
	add_action(
		'gtlm_import_expire',
		static function ( string $token ): void {
			require_once GTLM_PATH . 'includes/class-gtlm-import-file.php';
			GTLM_Import_File::delete( $token );
		}
	);

	// Self-heal the schedule for sites that updated in place rather than
	// deactivating and reactivating.
	if ( is_admin() && ! wp_next_scheduled( 'gtlm_purge_trash' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gtlm_purge_trash' );
	}
}
add_action( 'plugins_loaded', 'gtlm_bootstrap' );

/**
 * Permanently remove links that have outlived the trash retention window.
 */
function gtlm_run_trash_purge(): void {
	$settings = GTLM_Settings::get_instance();
	$days     = $settings->trash_retention_days();

	if ( $days <= 0 ) {
		return;
	}

	$db     = new GTLM_DB();
	$purged = $db->purge_trash_older_than( $days );

	if ( $purged > 0 ) {
		/**
		 * Fires after trashed links are auto-purged.
		 *
		 * @param int $purged Number of links removed.
		 * @param int $days   Retention window used.
		 */
		do_action( 'gtlm_trash_purged', $purged, $days );
	}
}

/** Defer REST classes until a REST request is actually served. */
function gtlm_register_rest_api(): void {
	require_once GTLM_PATH . 'includes/class-gt-link-rest-api.php';
	GTLM_REST_API::register( new GTLM_DB(), GTLM_Settings::get_instance() );
}

/** Only registered for sites that previously opted in. */
function gtlm_analytics_schedules( array $schedules ): array {
	$schedules['gtlm_five_minutes'] = array(
		'interval' => 300,
		'display'  => __( 'Every five minutes', 'gt-link-manager' ),
	);
	return $schedules;
}

function gtlm_run_analytics_maintenance(): void {
	if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
		return;
	}
	require_once GTLM_PATH . 'includes/class-gtlm-analytics.php';
	GTLM_Analytics::process();
}

/** Administrative schema migration only; redirects keep using their compatible event format. */
function gtlm_upgrade_analytics(): void {
	if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
		return; }
	require_once GTLM_PATH . 'includes/class-gtlm-analytics.php';
	GTLM_Analytics::upgrade();
}
