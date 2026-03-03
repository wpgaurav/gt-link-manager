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

delete_option( 'gt_link_manager_settings' );
delete_option( 'gt_link_manager_db_version' );
delete_option( 'gt_link_manager_diagnostics' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gt_links" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gt_link_categories" );
