<?php
/**
 * Uninstall GT Link Manager.
 *
 * Removes plugin options and custom tables when the plugin is deleted.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// New prefix options.
delete_option( 'gtlm_settings' );
delete_option( 'gtlm_db_version' );
delete_option( 'gtlm_diagnostics' );
// Old prefix options (pre-1.4.0).
delete_option( 'gt_link_manager_settings' );
delete_option( 'gt_link_manager_db_version' );
delete_option( 'gt_link_manager_diagnostics' );

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
