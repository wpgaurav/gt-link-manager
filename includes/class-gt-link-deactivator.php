<?php
/**
 * Plugin deactivation tasks.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Deactivator {
	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( 'gtlm_purge_trash' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'gtlm_purge_trash' );
		}
	}
}
