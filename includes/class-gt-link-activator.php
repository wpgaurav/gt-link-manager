<?php
/**
 * Plugin activation tasks.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Activator {
	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		self::create_tables();

		if ( false === get_option( 'gt_link_manager_settings', false ) ) {
			update_option( 'gt_link_manager_settings', GT_Link_Settings::defaults(), false );
		}

		self::register_rewrite_rules();
		flush_rewrite_rules();

		do_action( 'gt_link_manager_activated' );
	}

	/**
	 * Create required tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$links_table     = GT_Link_DB::links_table();
		$cats_table      = GT_Link_DB::categories_table();

		$sql_links = "CREATE TABLE {$links_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			url TEXT NOT NULL,
			redirect_type SMALLINT(3) NOT NULL DEFAULT 301,
			rel VARCHAR(100) DEFAULT '',
			noindex TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			category_id BIGINT(20) UNSIGNED DEFAULT NULL,
			tags VARCHAR(255) DEFAULT '',
			notes TEXT,
			trashed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY category_id (category_id),
			KEY redirect_type (redirect_type),
			KEY is_active (is_active),
			KEY trashed_at (trashed_at)
		) {$charset_collate};";

		$sql_cats = "CREATE TABLE {$cats_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			description TEXT,
			parent_id BIGINT(20) UNSIGNED DEFAULT 0,
			count BIGINT(20) UNSIGNED DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY parent_id (parent_id)
		) {$charset_collate};";

		dbDelta( $sql_links );
		dbDelta( $sql_cats );
	}

	/**
	 * Check if schemas need updating and run migrations.
	 *
	 * Called on every admin_init so plugin updates that add columns
	 * (like 1.1.9 adding is_active / trashed_at) take effect without
	 * requiring a manual deactivate → reactivate cycle.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( 'gt_link_manager_db_version', '0' );

		if ( version_compare( $stored, GT_LINK_MANAGER_VERSION, '>=' ) ) {
			return;
		}

		// Re-run dbDelta to add any missing columns / indexes.
		self::create_tables();

		// Backfill: existing rows created before 1.1.9 have NULL is_active.
		// They should be treated as active.
		global $wpdb;
		$table = GT_Link_DB::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_active = %d WHERE is_active IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				1
			)
		);

		update_option( 'gt_link_manager_db_version', GT_LINK_MANAGER_VERSION, true );
	}

	/**
	 * Register rewrite rules at activation time.
	 */
	public static function register_rewrite_rules(): void {
		$settings = GT_Link_Settings::get_instance();
		$prefix   = preg_quote( $settings->prefix(), '/' );

		add_rewrite_tag( '%gt_link_slug%', '([^&]+)' );
		add_rewrite_rule( '^' . $prefix . '/([^/]+)/?$', 'index.php?gt_link_slug=$matches[1]', 'top' );
	}
}
