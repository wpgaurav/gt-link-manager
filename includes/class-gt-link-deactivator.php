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
	}
}
