<?php
/**
 * Database access layer.
 *
 * Uses custom tables for link storage. All direct database queries
 * are intentional — this plugin does not use CPTs.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_DB {
	public const CACHE_GROUP = 'gtlm_links';

	/**
	 * Column list used in SELECT statements.
	 */
	private const LINK_COLUMNS = 'id, name, slug, url, redirect_type, rel, noindex, is_active, link_mode, regex_replacement, priority, geo_mode, geo_rules, category_id, tags, notes, total_clicks, trashed_at, created_at, updated_at';

	/**
	 * wpdb placeholder per writable column.
	 *
	 * Keyed so insert/update formats are derived from the payload rather than
	 * hand-maintained positionally.
	 */
	private const COLUMN_FORMATS = array(
		'name'              => '%s',
		'slug'              => '%s',
		'url'               => '%s',
		'redirect_type'     => '%d',
		'rel'               => '%s',
		'noindex'           => '%d',
		'is_active'         => '%d',
		'link_mode'         => '%s',
		'regex_replacement' => '%s',
		'priority'          => '%d',
		'geo_mode'          => '%s',
		'geo_rules'         => '%s',
		'category_id'       => '%d',
		'tags'              => '%s',
		'notes'             => '%s',
		'total_clicks'      => '%d',
	);

	/**
	 * @return string
	 */
	public static function links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gtlm_links';
	}

	/**
	 * @return string
	 */
	public static function categories_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gtlm_categories';
	}

	/**
	 * Fetch a single link by slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_link_by_slug( string $slug ): ?array {
		$slug = $this->sanitize_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = $this->cache_key_for_slug( $slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE slug = %s LIMIT 1", $slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row  = $wpdb->get_row( $sql, ARRAY_A );
		$link = is_array( $row ) ? $this->normalize_link_row( $row ) : null;

		$ttl = (int) apply_filters( 'gtlm_cache_ttl', 0, $slug, $link );
		wp_cache_set( $cache_key, $link, self::CACHE_GROUP, max( 0, $ttl ) );

		return $link;
	}

	/** Protected WordPress endpoints are never link-manager routes. */
	public static function protected_path( string $path ): bool {
		for ( $pass = 0; $pass < 3; ++$pass ) {
			$decoded = rawurldecode( $path );
			if ( $decoded === $path ) {
				break;
			}
			$path = $decoded;
		}
		if ( preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			return true;
		}
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', strtolower( $path ) ) ) as $part ) {
			if ( '..' === $part ) {
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		$path = implode( '/', $parts );
		$home = strtolower( trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) );
		if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		$first = explode( '/', $path )[0];
		return in_array( $first, array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-cron.php', 'wp-json', 'xmlrpc.php', 'wp-signup.php', 'wp-activate.php', 'wp-trackback.php', 'wp-config.php', 'robots.txt', 'wp-sitemap.xml', 'sitemap.xml', 'feed', 'comments' ), true );
	}

	/** Validate regex syntax before any write, including admin and imports. */
	public static function valid_link_definition( array $data ): bool {
		if ( 'direct' === ( $data['link_mode'] ?? '' ) && self::protected_path( (string) ( $data['slug'] ?? '' ) ) ) {
			return false;
		}
		if ( 'regex' !== ( $data['link_mode'] ?? 'standard' ) ) {
			return true;
		}
		$pattern = (string) ( $data['slug'] ?? '' );
		// A malformed user-supplied pattern is a validation failure, not a PHP warning.
		return '' !== $pattern && strlen( $pattern ) <= 255 && false !== @preg_match( '#' . $pattern . '#', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** Exact lookup for write/import uniqueness; never apply standard-slug normalization here. */
	public function get_link_by_exact_slug( string $slug ): ?array {
		global $wpdb;
		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );
		return is_array( $row ) ? $this->normalize_link_row( $row ) : null;
	}

	/**
	 * Insert a link record.
	 */
	public function insert_link( array $data ): int {
		global $wpdb;

		if ( ! self::valid_link_definition( $data ) ) {
			return 0;
		}

		$insert = $this->normalize_link_for_write( $data );
		$format = $this->formats_for( $insert );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( self::links_table(), $insert, $format );
		if ( false === $result ) {
			return 0;
		}

		$link_id = (int) $wpdb->insert_id;

		if ( $link_id > 0 ) {
			$this->maybe_increment_category_count( (int) $insert['category_id'] );
			$this->delete_slug_cache( (string) $insert['slug'], (string) ( $insert['link_mode'] ?? 'standard' ) );
			do_action( 'gtlm_after_save', $link_id, $insert );
		}

		return $link_id;
	}

	/**
	 * Update an existing link.
	 */
	public function update_link( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$existing = $this->get_link_by_id( $id );
		if ( null === $existing ) {
			return false;
		}

		global $wpdb;

		if ( ! self::valid_link_definition( $data ) ) {
			return false;
		}

		$update = $this->normalize_link_for_write( $data );
		$format = $this->formats_for( $update );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::links_table(),
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$old_cat = (int) ( $existing['category_id'] ?? 0 );
		$new_cat = (int) ( $update['category_id'] ?? 0 );
		if ( $old_cat !== $new_cat ) {
			$this->maybe_decrement_category_count( $old_cat );
			$this->maybe_increment_category_count( $new_cat );
		}

		$this->delete_slug_cache( (string) $existing['slug'], (string) ( $existing['link_mode'] ?? 'standard' ) );
		$this->delete_slug_cache( (string) $update['slug'], (string) ( $update['link_mode'] ?? 'standard' ) );

		do_action( 'gtlm_after_save', $id, $update );
		return true;
	}

	/**
	 * Soft-delete: move a link to trash.
	 */
	public function trash_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::links_table(),
			array( 'trashed_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'], (string) ( $link['link_mode'] ?? 'standard' ) );
		return true;
	}

	/**
	 * Restore a link from trash.
	 */
	public function restore_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET trashed_at = NULL WHERE id = %d",
				$id
			)
		);

		if ( false === $result ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( is_array( $link ) ) {
			$this->delete_slug_cache( (string) $link['slug'], (string) ( $link['link_mode'] ?? 'standard' ) );
		}

		return true;
	}

	/**
	 * Permanently delete a link by ID.
	 */
	public function delete_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( self::links_table(), array( 'id' => $id ), array( '%d' ) );

		if ( false === $result || 0 === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'], (string) ( $link['link_mode'] ?? 'standard' ) );
		$this->maybe_decrement_category_count( (int) ( $link['category_id'] ?? 0 ) );
		do_action( 'gtlm_after_delete', $id, $link );

		return true;
	}

	/**
	 * Increment a link's click counter.
	 *
	 * A single atomic UPDATE rather than read-modify-write, so concurrent
	 * clicks on the same link cannot lose counts. The slug cache is left
	 * alone on purpose: the counter is not part of redirect resolution, and
	 * busting the cache on every click would defeat the point of caching.
	 *
	 * @param int $id Link ID.
	 */
	public function increment_clicks( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET total_clicks = total_clicks + 1, updated_at = updated_at WHERE id = %d",
				$id
			)
		);

		return false !== $result && $result > 0;
	}

	/** Append a pre-normalized analytics event; called only after explicit opt-in. */
	public function append_analytics_event( array $event ): bool {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$table        = $wpdb->prefix . 'gtlm_analytics_events';
			$page_columns = array_key_exists( 'page', $event ) ? ',page' : '';
			$page_value   = array_key_exists( 'page', $event ) ? ',%s' : '';
			$values       = array( $event['link_id'], $event['occurred_at'], $event['generation'], $event['source'], $event['country'], $event['device'], $event['browser'], $event['os'], $event['campaign'], $event['status'], $event['mode'], $event['geo'] );
			if ( array_key_exists( 'page', $event ) ) {
				$values[] = $event['page'];
			}
			// Schema-1 requests can finish safely during an administrative schema upgrade.
			// Fixed, bounded ASCII fields avoid wpdb::insert's per-request column metadata lookup.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One write after explicit analytics consent.
			return 1 === $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The values array holds exactly 12 fields, or 13 when the fixed page column/placeholder is included.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table prefix and fixed optional column/placeholder.
					"INSERT INTO {$table} (link_id,occurred_at,generation,source,country,device,browser,os,campaign,status,mode,geo{$page_columns}) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s{$page_value})",
					$values
				)
			);
		} finally {
			$wpdb->suppress_errors( $old );
		}
	}

	/** Merge settings atomically so an ordinary form cannot resurrect or erase concurrent consent. */
	public function merge_settings( array $values, array $remove = array(), array $existing_only = array() ): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-swap requires the stored value, not an object-cache snapshot.
			$raw = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'gtlm_settings'" );
			if ( null === $raw ) {
				$initial = array_diff_key( $values, array_flip( $existing_only ) );
				return $initial ? add_option( 'gtlm_settings', $initial, '', false ) : false;
			}
			$old   = maybe_unserialize( $raw );
			$old   = is_array( $old ) ? $old : array();
			$patch = $values;
			foreach ( $existing_only as $key ) {
				if ( ! array_key_exists( $key, $old ) ) {
					unset( $patch[ $key ] );
				}
			}
			$next = array_merge( $old, $patch );
			foreach ( $remove as $key ) {
				unset( $next[ $key ] );
			}
			if ( $old === $next ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preserve unrelated changes made between the read and write.
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'gtlm_settings' AND BINARY option_value = BINARY %s", maybe_serialize( $next ), $raw ) );
			if ( false === $result ) {
				return false;
			}
			if ( 1 === $result ) {
				wp_cache_delete( 'gtlm_settings', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				do_action( 'update_option_gtlm_settings', $old, $next, 'gtlm_settings' );
				do_action( 'updated_option', 'gtlm_settings', $old, $next );
				return true;
			}
		}
		return false;
	}

	/**
	 * Reset one link's click counter, or every link's when no ID is given.
	 *
	 * @param int $id Link ID, or 0 for all links.
	 * @return int Rows affected.
	 */
	public function reset_clicks( int $id = 0 ): int {
		global $wpdb;
		$table = self::links_table();

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET total_clicks = 0, updated_at = updated_at WHERE id = %d",
					$id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "UPDATE {$table} SET total_clicks = 0, updated_at = updated_at WHERE total_clicks > 0" );
		}

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Permanently delete every trashed link.
	 *
	 * Deletes row by row through delete_link() so per-link cache invalidation,
	 * category counts, and the gtlm_after_delete hook all still fire.
	 *
	 * @return int Number of links deleted.
	 */
	public function empty_trash(): int {
		global $wpdb;
		$table = self::links_table();

		// Table name comes from $wpdb->prefix, never user input; there are no
		// value placeholders in this query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE trashed_at IS NOT NULL" );

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $this->delete_link( (int) $id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Permanently delete links trashed longer ago than the retention window.
	 *
	 * @param int $days Retention window in days. 0 or less keeps trash forever.
	 * @return int Number of links purged.
	 */
	public function purge_trash_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table  = self::links_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE trashed_at IS NOT NULL AND trashed_at < %s",
				$cutoff
			)
		);

		$purged = 0;
		foreach ( $ids as $id ) {
			if ( $this->delete_link( (int) $id ) ) {
				++$purged;
			}
		}

		return $purged;
	}

	/**
	 * Toggle is_active status for a link.
	 */
	public function toggle_active( int $id, bool $active ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::links_table(),
			array( 'is_active' => $active ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'], (string) ( $link['link_mode'] ?? 'standard' ) );
		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_link_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1", $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->normalize_link_row( $row ) : null;
	}

	public function delete_slug_cache( string $slug, string $link_mode = 'standard' ): void {
		if ( '' === $slug ) {
			return;
		}

		if ( 'standard' === $link_mode ) {
			$sanitized = $this->sanitize_slug( $slug );
			if ( '' !== $sanitized ) {
				wp_cache_delete( $this->cache_key_for_slug( $sanitized ), self::CACHE_GROUP );
			}
		} elseif ( 'direct' === $link_mode ) {
			wp_cache_delete( 'direct:' . sanitize_text_field( $slug ), self::CACHE_GROUP );
		} elseif ( 'regex' === $link_mode ) {
			wp_cache_delete( 'regex_rules', self::CACHE_GROUP );
		}
	}

	/**
	 * Lightweight search for editor inserter.
	 * Excludes trashed and inactive links.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search_links( string $search = '', int $limit = 20 ): array {
		global $wpdb;

		$table = self::links_table();
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT id, name, slug, rel FROM {$table} WHERE trashed_at IS NULL AND is_active = 1 AND (link_mode = 'standard' OR link_mode IS NULL)";
		$args = array();

		$search = sanitize_text_field( $search );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$sql   .= ' AND (name LIKE %s OR slug LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$sql   .= ' ORDER BY name ASC LIMIT %d';
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'   => (int) $row['id'],
					'name' => sanitize_text_field( (string) $row['name'] ),
					'slug' => sanitize_title( (string) $row['slug'] ),
					'rel'  => sanitize_text_field( (string) $row['rel'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Count links with filters.
	 *
	 * Supported filters: search, category_id, redirect_type, rel, status (active|inactive|all), trashed (bool).
	 */
	public function count_links( array $filters = array() ): int {
		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql    = "SELECT COUNT(*) FROM {$table} WHERE 1=1";
		$params = array();

		$this->apply_status_filters( $sql, $params, $filters );
		$this->apply_common_filters( $sql, $params, $filters );

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * List links with pagination/sorting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_links(
		array $filters = array(),
		int $page = 1,
		int $per_page = 20,
		string $orderby = 'id',
		string $order = 'DESC'
	): array {
		global $wpdb;

		$allowed_orderby = array( 'id', 'name', 'slug', 'url', 'redirect_type', 'rel', 'category_id', 'is_active', 'link_mode', 'priority', 'total_clicks', 'created_at', 'updated_at' );
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'id';
		$order           = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$page            = max( 1, $page );
		$per_page        = max( 1, $per_page );
		$offset          = ( $page - 1 ) * $per_page;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql    = 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE 1=1";
		$params = array();

		$this->apply_status_filters( $sql, $params, $filters );
		$this->apply_common_filters( $sql, $params, $filters );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is validated to ASC/DESC above.
		$sql     .= " ORDER BY %i {$order} LIMIT %d OFFSET %d";
		$params[] = $orderby;
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_link_row' ), $rows );
	}

	/**
	 * List all links for CSV export (excludes trashed).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_links_for_export( array $filters = array() ): array {
		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql    = 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE trashed_at IS NULL";
		$params = array();

		$this->apply_common_filters( $sql, $params, $filters );

		$sql .= ' ORDER BY id DESC';
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_link_row' ), $rows );
	}

	/**
	 * Apply trash/status WHERE clauses.
	 *
	 * @param string              $sql    SQL string (modified by reference).
	 * @param array<int, mixed>   $params Params array (modified by reference).
	 * @param array<string,mixed> $filters Filters.
	 */
	private function apply_status_filters( string &$sql, array &$params, array $filters ): void {
		$trashed = ! empty( $filters['trashed'] );

		if ( $trashed ) {
			$sql .= ' AND trashed_at IS NOT NULL';
		} else {
			$sql .= ' AND trashed_at IS NULL';
		}

		$status = (string) ( $filters['status'] ?? '' );
		if ( 'active' === $status ) {
			$sql .= ' AND is_active = 1';
		} elseif ( 'inactive' === $status ) {
			$sql .= ' AND is_active = 0';
		}
	}

	/**
	 * Apply common WHERE clauses (search, category, redirect_type, rel).
	 *
	 * @param string              $sql
	 * @param array<int, mixed>   $params
	 * @param array<string,mixed> $filters
	 */
	private function apply_common_filters( string &$sql, array &$params, array $filters ): void {
		global $wpdb;

		if ( ! empty( $filters['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['search'] ) ) . '%';
			$sql     .= ' AND (name LIKE %s OR slug LIKE %s OR url LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		if ( ! empty( $filters['category_id'] ) ) {
			$sql     .= ' AND category_id = %d';
			$params[] = absint( $filters['category_id'] );
		}

		if ( ! empty( $filters['redirect_type'] ) ) {
			$sql     .= ' AND redirect_type = %d';
			$params[] = (int) $filters['redirect_type'];
		}

		if ( ! empty( $filters['rel'] ) ) {
			$rel_value = sanitize_key( (string) $filters['rel'] );
			if ( in_array( $rel_value, array( 'nofollow', 'sponsored', 'ugc' ), true ) ) {
				$sql     .= ' AND FIND_IN_SET(%s, rel)';
				$params[] = $rel_value;
			}
		}

		if ( ! empty( $filters['link_mode'] ) ) {
			$mode     = $this->sanitize_link_mode( (string) $filters['link_mode'] );
			$sql     .= ' AND link_mode = %s';
			$params[] = $mode;
		}

		if ( ! empty( $filters['geo_mode'] ) ) {
			$sql     .= ' AND geo_mode = %s';
			$params[] = $this->sanitize_geo_mode( (string) $filters['geo_mode'] );
		}

		if ( ! empty( $filters['m'] ) ) {
			$m     = absint( $filters['m'] );
			$year  = (int) ( $m / 100 );
			$month = $m % 100;
			if ( $year > 0 && $month > 0 && $month <= 12 ) {
				$sql     .= ' AND YEAR(created_at) = %d AND MONTH(created_at) = %d';
				$params[] = $year;
				$params[] = $month;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_categories(): array {
		global $wpdb;

		$table = self::categories_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT id, name, slug, description, parent_id, count FROM {$table} ORDER BY name ASC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$row['id']          = (int) $row['id'];
				$row['parent_id']   = (int) $row['parent_id'];
				$row['count']       = (int) $row['count'];
				$row['name']        = sanitize_text_field( (string) $row['name'] );
				$row['slug']        = sanitize_title( (string) $row['slug'] );
				$row['description'] = sanitize_textarea_field( (string) $row['description'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_category( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = self::categories_table();
		$sql   = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, name, slug, description, parent_id, count FROM {$table} WHERE id = %d LIMIT 1",
			$id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['id']          = (int) $row['id'];
		$row['parent_id']   = (int) $row['parent_id'];
		$row['count']       = (int) $row['count'];
		$row['name']        = sanitize_text_field( (string) $row['name'] );
		$row['slug']        = sanitize_title( (string) $row['slug'] );
		$row['description'] = sanitize_textarea_field( (string) $row['description'] );

		return $row;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert_category( array $data ): int {
		global $wpdb;

		$insert = array(
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'parent_id'   => absint( $data['parent_id'] ?? 0 ),
		);

		if ( '' === $insert['slug'] ) {
			$insert['slug'] = sanitize_title( $insert['name'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			self::categories_table(),
			$insert,
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return 0;
		}

		wp_cache_delete( 'gtlm_admin_categories', 'gtlm_links' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_category( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$update = array(
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'parent_id'   => absint( $data['parent_id'] ?? 0 ),
		);

		if ( '' === $update['slug'] ) {
			$update['slug'] = sanitize_title( $update['name'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::categories_table(),
			$update,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'gtlm_admin_categories', 'gtlm_links' );
		}

		return false !== $result;
	}

	public function delete_category( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::links_table(),
			array( 'category_id' => 0 ),
			array( 'category_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::categories_table(),
			array( 'parent_id' => 0 ),
			array( 'parent_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( self::categories_table(), array( 'id' => $id ), array( '%d' ) );

		if ( false !== $result && $result > 0 ) {
			wp_cache_delete( 'gtlm_admin_categories', 'gtlm_links' );
		}

		return false !== $result && $result > 0;
	}

	/**
	 * Get distinct year/month pairs for the date filter dropdown.
	 *
	 * @return array<int, array{year: int, month: int}>
	 */
	public function get_link_months(): array {
		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT DISTINCT YEAR(created_at) AS year, MONTH(created_at) AS month FROM {$table} WHERE trashed_at IS NULL ORDER BY year DESC, month DESC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'year'  => (int) $row['year'],
					'month' => (int) $row['month'],
				);
			},
			$rows
		);
	}

	public function flush_cache_group(): void {
		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}

		// Never flush another plugin's cache. Edits already evict their exact keys.
		wp_cache_delete( 'regex_rules', self::CACHE_GROUP );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_link_row( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['redirect_type']     = (int) $row['redirect_type'];
		$row['noindex']           = (int) $row['noindex'];
		$row['is_active']         = (int) ( $row['is_active'] ?? 1 );
		$row['category_id']       = isset( $row['category_id'] ) ? (int) $row['category_id'] : 0;
		$row['link_mode']         = $this->sanitize_link_mode( (string) ( $row['link_mode'] ?? 'standard' ) );
		$row['regex_replacement'] = sanitize_text_field( (string) ( $row['regex_replacement'] ?? '' ) );
		$row['priority']          = (int) ( $row['priority'] ?? 10 );
		$row['geo_mode']          = $this->sanitize_geo_mode( (string) ( $row['geo_mode'] ?? 'off' ) );
		$row['rel']               = $this->sanitize_rel_string( (string) $row['rel'] );
		$row['url']               = esc_url_raw( (string) $row['url'] );
		$row['trashed_at']        = isset( $row['trashed_at'] ) ? (string) $row['trashed_at'] : null;
		$row['total_clicks']      = (int) ( $row['total_clicks'] ?? 0 );

		// geo_rules stays a raw JSON string here on purpose. Decoding it would
		// cost every link read — including the redirect hot path and list
		// tables — for a field only geo-enabled links ever use. GTLM_Geo
		// decodes lazily, so links with geo_mode 'off' never pay for it.
		$row['geo_rules'] = isset( $row['geo_rules'] ) ? (string) $row['geo_rules'] : '';

		// Only sanitize_title for standard links; direct/regex slugs need special characters preserved.
		if ( 'standard' === $row['link_mode'] ) {
			$row['slug'] = $this->sanitize_slug( (string) $row['slug'] );
		} else {
			$row['slug'] = sanitize_text_field( (string) $row['slug'] );
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, int|string>
	 */
	private function normalize_link_for_write( array $data ): array {
		$rel       = isset( $data['rel'] ) ? $this->sanitize_rel_string( (string) $data['rel'] ) : '';
		$link_mode = $this->sanitize_link_mode( (string) ( $data['link_mode'] ?? 'standard' ) );
		$geo_mode  = $this->sanitize_geo_mode( (string) ( $data['geo_mode'] ?? 'off' ) );

		// Standard links use sanitize_title; direct/regex links preserve special characters.
		if ( 'standard' === $link_mode ) {
			$slug = $this->sanitize_slug( (string) ( $data['slug'] ?? '' ) );
		} else {
			$slug = sanitize_text_field( (string) ( $data['slug'] ?? '' ) );
		}

		return array(
			'name'              => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'              => $slug,
			'url'               => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'redirect_type'     => $this->sanitize_redirect_type( (int) ( $data['redirect_type'] ?? 301 ) ),
			'rel'               => $rel,
			'noindex'           => ! empty( $data['noindex'] ) ? 1 : 0,
			'is_active'         => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) ( $data['regex_replacement'] ?? '' ) ),
			'priority'          => max( 0, (int) ( $data['priority'] ?? 10 ) ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => 'off' === $geo_mode ? '' : GTLM_Geo::encode_rules( $data['geo_rules'] ?? '' ),
			'category_id'       => absint( $data['category_id'] ?? 0 ),
			'tags'              => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
		);
	}

	/**
	 * Fetch a single direct-mode link by its path.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_direct_link_by_path( string $path ): ?array {
		$path = sanitize_text_field( $path );
		if ( '' === $path ) {
			return null;
		}

		$cache_key = 'direct:' . $path;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE link_mode = 'direct' AND slug = %s LIMIT 1", $path );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row  = $wpdb->get_row( $sql, ARRAY_A );
		$link = is_array( $row ) ? $this->normalize_link_row( $row ) : null;

		$ttl = (int) apply_filters( 'gtlm_cache_ttl', 0, $path, $link );
		wp_cache_set( $cache_key, $link, self::CACHE_GROUP, max( 0, $ttl ) );

		return $link;
	}

	/**
	 * Get all active regex rules ordered by priority.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_regex_rules(): array {
		$cache_key = 'regex_rules';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		global $wpdb;

		$table = self::links_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$sql = 'SELECT ' . self::LINK_COLUMNS . " FROM {$table} WHERE link_mode = 'regex' AND is_active = 1 AND trashed_at IS NULL ORDER BY priority ASC, id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rules = array_map( array( $this, 'normalize_link_row' ), $rows );

		$ttl = (int) apply_filters( 'gtlm_cache_ttl', 0, 'regex_rules', $rules );
		wp_cache_set( $cache_key, $rules, self::CACHE_GROUP, max( 0, $ttl ) );

		return $rules;
	}

	private function sanitize_link_mode( string $mode ): string {
		$allowed = array( 'standard', 'direct', 'regex' );
		return in_array( $mode, $allowed, true ) ? $mode : 'standard';
	}

	private function sanitize_geo_mode( string $mode ): string {
		$allowed = array( 'off', 'targeted' );
		return in_array( $mode, $allowed, true ) ? $mode : 'off';
	}

	/**
	 * Derive wpdb placeholders for a write payload.
	 *
	 * @param array<string, mixed> $row Payload from normalize_link_for_write().
	 * @return array<int, string>
	 */
	private function formats_for( array $row ): array {
		$formats = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = self::COLUMN_FORMATS[ $column ] ?? '%s';
		}

		return $formats;
	}

	private function sanitize_slug( string $slug ): string {
		return sanitize_title( $slug );
	}

	private function sanitize_redirect_type( int $type ): int {
		$allowed = array( 301, 302, 307 );
		return in_array( $type, $allowed, true ) ? $type : 301;
	}

	private function sanitize_rel_string( string $rel ): string {
		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$parts   = preg_split( '/[\s,]+/', strtolower( $rel ), -1, PREG_SPLIT_NO_EMPTY );
		$parts   = array_map( 'sanitize_key', $parts );
		$parts   = array_values( array_intersect( $parts, $allowed ) );

		return implode( ',', array_unique( $parts ) );
	}

	private function cache_key_for_slug( string $slug ): string {
		return 'slug:' . $slug;
	}

	private function maybe_increment_category_count( int $category_id ): void {
		if ( $category_id <= 0 ) {
			return;
		}

		global $wpdb;

		$table = self::categories_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET count = count + 1 WHERE id = %d",
				$category_id
			)
		);
	}

	private function maybe_decrement_category_count( int $category_id ): void {
		if ( $category_id <= 0 ) {
			return;
		}

		global $wpdb;

		$table = self::categories_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET count = GREATEST(count - 1, 0) WHERE id = %d",
				$category_id
			)
		);
	}
}
