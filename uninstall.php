<?php
/**
 * Uninstall GT Link Manager.
 *
 * Only removes data if the user opted in via Settings > Delete Data on Uninstall.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gtlm_settings = get_option( 'gtlm_settings', array() );

// Never leave retained analytics implicitly active on a later reinstall.
if ( is_array( $gtlm_settings ) && array_key_exists( 'enable_advanced_analytics', $gtlm_settings ) ) {
	$gtlm_settings['enable_advanced_analytics'] = 0;
	update_option( 'gtlm_settings', $gtlm_settings, false );
	wp_clear_scheduled_hook( 'gtlm_analytics_maintenance' );
}

if ( ! is_array( $gtlm_settings ) || empty( $gtlm_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Options.
delete_option( 'gtlm_settings' );
delete_option( 'gtlm_db_version' );
delete_option( 'gtlm_analytics' );
delete_option( 'gtlm_diagnostics' );
delete_option( 'gtlm_geo_probe_token' );
// Old prefix options (pre-1.4.0).
delete_option( 'gt_link_manager_settings' );
delete_option( 'gt_link_manager_db_version' );
delete_option( 'gt_link_manager_diagnostics' );

// Analytics data is covered by the same explicit uninstall deletion setting.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gtlm_analytics_events, {$wpdb->prefix}gtlm_analytics_hourly" );

// New prefix tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gtlm_links" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gtlm_categories" );

// Old prefix tables (pre-1.4.0, in case migration was incomplete).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gt_links" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gt_link_categories" );
