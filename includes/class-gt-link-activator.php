<?php
/**
 * Plugin activation tasks.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Activator {
	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		self::create_tables();

		if ( false === get_option( 'gtlm_settings', false ) ) {
			update_option( 'gtlm_settings', GTLM_Settings::defaults(), false );
		}

		self::register_rewrite_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'gtlm_purge_trash' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gtlm_purge_trash' );
		}

		// Record the schema version now so the first admin load does not
		// re-run dbDelta and the backfills against a freshly built table.
		update_option( 'gtlm_db_version', GTLM_VERSION, true );

		// Previously consented sites resume retention maintenance, never collection.
		if ( GTLM_Settings::get_instance()->analytics_initialized() ) {
			add_filter( 'cron_schedules', 'gtlm_analytics_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Opted-in bounded maintenance.
			if ( ! wp_next_scheduled( 'gtlm_analytics_maintenance' ) ) {
				wp_schedule_event( time() + 300, 'gtlm_five_minutes', 'gtlm_analytics_maintenance' );
			}
		}
		do_action( 'gtlm_activated' );
	}

	/**
	 * Create required tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$links_table     = GTLM_DB::links_table();
		$cats_table      = GTLM_DB::categories_table();

		$sql_links = "CREATE TABLE {$links_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			url TEXT NOT NULL,
			redirect_type SMALLINT(3) NOT NULL DEFAULT 301,
			rel VARCHAR(100) DEFAULT '',
			noindex TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			link_mode VARCHAR(20) NOT NULL DEFAULT 'standard',
			regex_replacement VARCHAR(500) DEFAULT '',
			priority INT NOT NULL DEFAULT 10,
			geo_mode VARCHAR(20) NOT NULL DEFAULT 'off',
			geo_rules LONGTEXT,
			category_id BIGINT(20) UNSIGNED DEFAULT NULL,
			tags VARCHAR(255) DEFAULT '',
			notes TEXT,
			total_clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			trashed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY category_id (category_id),
			KEY redirect_type (redirect_type),
			KEY is_active (is_active),
			KEY trashed_at (trashed_at),
			KEY link_mode (link_mode),
			KEY geo_mode (geo_mode)
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
		$stored = get_option( 'gtlm_db_version', '0' );

		if ( version_compare( $stored, GTLM_VERSION, '>=' ) ) {
			return;
		}

		// Re-run dbDelta to add any missing columns / indexes.
		self::create_tables();

		// Backfill: existing rows created before 1.1.9 have NULL is_active.
		// They should be treated as active.
		global $wpdb;
		$table = GTLM_DB::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET is_active = %d WHERE is_active IS NULL",
				1
			)
		);

		// Backfill: rows created before 1.6.0 have NULL link_mode.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET link_mode = %s WHERE link_mode IS NULL OR link_mode = ''",
				'standard'
			)
		);

		// Backfill: rows created before 1.7.0 have NULL geo_mode.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET geo_mode = %s WHERE geo_mode IS NULL OR geo_mode = ''",
				'off'
			)
		);

		// Upgrades must not inherit the trash retention default.
		//
		// A fresh install opts into 30-day cleanup knowingly, because the
		// trash starts empty. An existing site may have links sitting in the
		// trash for years, and silently deleting them on the first cron run
		// after an update is data loss the owner never agreed to. Existing
		// installs are pinned to 0 (keep forever), which is exactly how the
		// plugin behaved before this setting existed. The owner can opt in
		// from Settings whenever they want.
		$settings = get_option( 'gtlm_settings' );
		if ( is_array( $settings ) && ! array_key_exists( 'trash_retention_days', $settings ) ) {
			$settings['trash_retention_days'] = 0;
			update_option( 'gtlm_settings', $settings, false );
		}

		// Backfill: rows created before 1.8.0 have NULL total_clicks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET total_clicks = %d WHERE total_clicks IS NULL",
				0
			)
		);

		update_option( 'gtlm_db_version', GTLM_VERSION, true );
	}

	/**
	 * Register rewrite rules at activation time.
	 */
	public static function register_rewrite_rules(): void {
		$settings = GTLM_Settings::get_instance();
		$prefix   = preg_quote( $settings->prefix(), '/' );

		add_rewrite_tag( '%gtlm_slug%', '([^&]+)' );
		add_rewrite_rule( '^' . $prefix . '/([^/]+)/?$', 'index.php?gtlm_slug=$matches[1]', 'top' );
	}
}
