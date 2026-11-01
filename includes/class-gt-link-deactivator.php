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
		// The rule was registered earlier in this request, and flushing rebuilds from
		// what is registered. Left in, it outlives the plugin and serves the front page.
		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			unset( $wp_rewrite->extra_rules_top[ '^' . preg_quote( GTLM_Settings::get_instance()->prefix(), '/' ) . '/([^/]+)/?$' ] );
		}
		flush_rewrite_rules();
		if ( GTLM_Settings::get_instance()->analytics_initialized() ) {
			require_once GTLM_PATH . 'includes/class-gtlm-analytics.php';
			GTLM_Analytics::pause( true );
			wp_clear_scheduled_hook( 'gtlm_analytics_maintenance' );
		}

		$timestamp = wp_next_scheduled( 'gtlm_purge_trash' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'gtlm_purge_trash' );
		}
	}
}
