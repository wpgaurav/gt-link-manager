<?php
/**
 * Opt-in analytics storage. No method is called on a never-enabled frontend.
 *
 * @package GTLinkManager
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Dedicated data layer; writes and fresh maintenance reads cannot be object-cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names below are generated exclusively from the WordPress table prefix.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Analytics_DB extends GTLM_DB {
	public int $expired_events = 0;

	public const DIMENSIONS = array( 'total', 'source', 'page', 'country', 'device', 'browser', 'os', 'campaign', 'status', 'mode', 'geo' );

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gtlm_analytics_events';
	}

	public static function hourly_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gtlm_analytics_hourly';
	}

	/** A single, nonblocking control lock shared by lifecycle and maintenance. */
	public function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'gtlm:' . md5( DB_NAME . ':' . $wpdb->prefix ) ) );
	}

	public function unlock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'gtlm:' . md5( DB_NAME . ':' . $wpdb->prefix ) ) );
	}

	/** Called only after explicit opt-in, or an opted-in administrative upgrade. */
	public function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$events  = self::events_table();
		$hourly  = self::hourly_table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta does not replace an existing primary key. Add the cohort key explicitly.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $hourly ) );
		if ( $exists ) {
			$primary = $wpdb->get_col( "SHOW INDEX FROM {$hourly} WHERE Key_name='PRIMARY'", 4 );
			if ( ! in_array( 'page_key', $primary, true ) ) {
				$column = $wpdb->get_var( "SHOW COLUMNS FROM {$hourly} LIKE 'page_key'" );
				$add    = $column ? '' : "ADD page_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', ";
				if ( false === $wpdb->query( "ALTER TABLE {$hourly} {$add}DROP PRIMARY KEY, ADD PRIMARY KEY (link_id,bucket_start,page_key,dimension,value)" ) ) {
					return false; }
			}
		}

		dbDelta(
			"CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_id bigint(20) unsigned NOT NULL,
			occurred_at datetime NOT NULL,
			generation char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			processed tinyint(1) NOT NULL DEFAULT 0,
			page_processed tinyint(1) NOT NULL DEFAULT 0,
			source varchar(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			page varchar(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			country char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			device varchar(16) NOT NULL DEFAULT 'unknown',
			browser varchar(16) NOT NULL DEFAULT 'unknown',
			os varchar(16) NOT NULL DEFAULT 'unknown',
			campaign int unsigned NOT NULL DEFAULT 0,
			status smallint unsigned NOT NULL,
			mode varchar(16) NOT NULL,
			geo varchar(16) NOT NULL,
			PRIMARY KEY  (id),
			KEY pending (processed,id),
			KEY page_pending (processed,page_processed,id),
			KEY expiry (occurred_at,id),
			KEY link_time (link_id,occurred_at,id)
			) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$hourly} (
			link_id bigint(20) unsigned NOT NULL,
			bucket_start datetime NOT NULL,
			page_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			dimension varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			value varchar(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (link_id,bucket_start,page_key,dimension,value),
			KEY date_dimension (bucket_start,dimension),
			KEY page_date (page_key,bucket_start,dimension)
			) ENGINE=InnoDB {$charset};"
		);
		foreach ( array(
			$events => array( 'PRIMARY', 'pending', 'page_pending', 'expiry', 'link_time' ),
			$hourly => array( 'PRIMARY', 'date_dimension', 'page_date' ),
		) as $table => $required ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			$keys   = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
			if ( 'InnoDB' !== $engine || array_diff( $required, $keys ) ) {
				return false;
			}
		}
		return 'varchar(1024)' === $wpdb->get_var( "SHOW COLUMNS FROM {$events} LIKE 'page'", 1 ) && 'varchar(1024)' === $wpdb->get_var( "SHOW COLUMNS FROM {$hourly} LIKE 'value'", 1 );
	}

	public function drop(): bool {
		global $wpdb;
		$events = self::events_table();
		$hourly = self::hourly_table();
		return false !== $wpdb->query( "DROP TABLE IF EXISTS {$events}, {$hourly}" );
	}

	/** One append; no cache invalidation, retry, diagnostics write, or repair. */
	public function append( array $event ): bool {
		return $this->append_analytics_event( $event );
	}

	/** Process one committed batch under the caller's advisory lock. */
	public function aggregate_batch( string $generation, string $cutoff, int $limit = 500 ): int {
		global $wpdb;
		$events               = self::events_table();
		$hourly               = self::hourly_table();
		$links                = self::links_table();
		$this->expired_events = 0;
		$this->must_query( 'START TRANSACTION' );
		try {
			// No high-water ID and no gap locks: a later commit with a lower ID is still pending.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE processed = 0 ORDER BY id LIMIT %d", min( 500, max( 1, $limit ) ) ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'analytics_read_failed' );
			}
			if ( ! $rows ) {
				$this->must_query( 'COMMIT' );
				return 0;
			}
			$link_ids = implode( ',', array_unique( array_map( 'intval', array_column( $rows, 'link_id' ) ) ) );
			$existing = array_flip( $wpdb->get_col( "SELECT id FROM {$links} WHERE id IN ({$link_ids})" ) );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'analytics_links_failed' );
			}
			$ignored = array();
			$counts  = array();
			$hosts   = array();
			$pages   = array();
			foreach ( $rows as $row ) {
				if ( $row['occurred_at'] < $cutoff ) {
					++$this->expired_events;
				}
				if ( $row['generation'] !== $generation || $row['occurred_at'] < $cutoff || ! isset( $existing[ $row['link_id'] ] ) ) {
					$ignored[] = (int) $row['id'];
					continue;
				}
				$bucket = substr( $row['occurred_at'], 0, 16 ) . ':00';
				$day    = substr( $bucket, 0, 10 );
				$key    = $row['link_id'] . ':' . $day;
				if ( '' !== $row['source'] ) {
					if ( ! isset( $hosts[ $key ] ) ) {
						$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT value FROM {$hourly} WHERE link_id = %d AND bucket_start >= %s AND bucket_start < %s AND page_key = '' AND dimension = 'source' AND value <> '_other' AND value <> '' LIMIT 100", $row['link_id'], $day . ' 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( $day . ' UTC' ) + DAY_IN_SECONDS ) ) );
						if ( '' !== $wpdb->last_error ) {
							throw new RuntimeException( 'analytics_hosts_failed' );
						}
						$hosts[ $key ] = array_fill_keys( $values, true );
					}
					if ( ! isset( $hosts[ $key ][ $row['source'] ] ) && count( $hosts[ $key ] ) >= 100 ) {
						$row['source'] = '_other';
					} else {
						$hosts[ $key ][ $row['source'] ] = true;
					}
				}
				$page_key = $row['link_id'] . ':' . $day;
				if ( '' !== $row['page'] ) {
					if ( ! isset( $pages[ $page_key ] ) ) {
						$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT value FROM {$hourly} WHERE link_id = %d AND bucket_start >= %s AND bucket_start < %s AND page_key = '' AND dimension = 'page' AND value <> '_other' AND value <> '' LIMIT 100", $row['link_id'], $day . ' 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( $day . ' UTC' ) + DAY_IN_SECONDS ) ) );
						if ( '' !== $wpdb->last_error ) {
							throw new RuntimeException( 'analytics_pages_failed' ); }
						$pages[ $page_key ] = array_fill_keys( $values, true );
					}
					if ( ! isset( $pages[ $page_key ][ $row['page'] ] ) && count( $pages[ $page_key ] ) >= 100 ) {
						$row['page'] = '_other'; } else {
						$pages[ $page_key ][ $row['page'] ] = true; }
				}
				foreach ( self::DIMENSIONS as $dimension ) {
					$value = 'total' === $dimension ? '' : (string) $row[ $dimension ];
					$key   = $row['link_id'] . '|' . $bucket . '|' . $dimension . '|' . $value;
					if ( ! isset( $counts[ $key ] ) ) {
						$counts[ $key ] = array( (int) $row['link_id'], $bucket, $dimension, $value, 0 );
					}
					++$counts[ $key ][4];
				}
			}
			foreach ( array_chunk( $counts, 500 ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $count ) {
					$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%d)', $count[0], $count[1], $count[2], $count[3], $count[4] );
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each tuple above is prepared.
				$this->must_query( "INSERT INTO {$hourly} (link_id,bucket_start,dimension,value,clicks) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks)' );
			}
			$ids = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
			$this->must_query( "UPDATE {$events} SET processed = 1 WHERE id IN ({$ids})" );
			if ( $ignored ) {
				$skip = implode( ',', $ignored );
				$this->must_query( "UPDATE {$events} SET page_processed=1 WHERE id IN ({$skip})" );}
			$this->must_query( 'COMMIT' );
			return count( $rows );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** Build page-specific details from retained processed events, exactly once per event. */
	public function aggregate_pages( string $generation, int $limit = 500 ): int {
		global $wpdb;
		$events = self::events_table();
		$hourly = self::hourly_table();
		$links  = self::links_table();
		$this->must_query( 'START TRANSACTION' );
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE processed=1 AND page_processed=0 ORDER BY id LIMIT %d", min( 500, max( 1, $limit ) ) ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'analytics_page_read_failed' ); }
			if ( ! $rows ) {
				$this->must_query( 'COMMIT' );
				return 0; }
			$known  = array();
			$counts = array();
			foreach ( $rows as $row ) {
				if ( '' === $row['page'] || $row['generation'] !== $generation ) {
					continue; }
				$day = substr( $row['occurred_at'], 0, 10 );
				$key = $row['link_id'] . ':' . $day;
				if ( ! isset( $known[ $key ] ) ) {
					$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT h.value FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE h.link_id=%d AND h.bucket_start>=%s AND h.bucket_start<%s AND h.page_key='' AND h.dimension='page' LIMIT 102", $row['link_id'], $day . ' 00:00:00', gmdate( 'Y-m-d H:i:s', strtotime( $day . ' UTC' ) + DAY_IN_SECONDS ) ) );
					if ( '' !== $wpdb->last_error ) {
						throw new RuntimeException( 'analytics_page_lookup_failed' ); }
					$known[ $key ] = array_fill_keys( $values, true );
				}
				$page = $row['page'];
				if ( ! isset( $known[ $key ][ $page ] ) ) {
					if ( ! isset( $known[ $key ]['_other'] ) ) {
						continue; }
					$page = '_other';
				}
				$page_key = hash( 'sha256', $page );
				$bucket   = substr( $row['occurred_at'], 0, 16 ) . ':00';
				foreach ( self::DIMENSIONS as $dimension ) {
					if ( 'page' === $dimension ) {
						continue; }
					$value = 'total' === $dimension ? '' : (string) $row[ $dimension ];
					$key   = $row['link_id'] . '|' . $bucket . '|' . $page_key . '|' . $dimension . '|' . $value;
					if ( ! isset( $counts[ $key ] ) ) {
						$counts[ $key ] = array( (int) $row['link_id'], $bucket, $page_key, $dimension, $value, 0 ); }
					++$counts[ $key ][5];
				}
			}
			foreach ( array_chunk( $counts, 500 ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $count ) {
					$values[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%d)', $count[0], $count[1], $count[2], $count[3], $count[4], $count[5] ); }
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every value tuple is prepared above.
				$this->must_query( "INSERT INTO {$hourly} (link_id,bucket_start,page_key,dimension,value,clicks) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE clicks=clicks+VALUES(clicks)' );
			}
			$ids = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
			$this->must_query( "UPDATE {$events} SET page_processed=1 WHERE id IN ({$ids})" );
			$this->must_query( 'COMMIT' );
			return count( $rows );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error; }
	}

	/** Prevent consent-generation changes from abandoning unfinished page detail batches. */
	public function has_page_pending( string $generation ): bool {
		global $wpdb;
		$table = self::events_table();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE processed=1 AND page_processed=0 AND generation=%s LIMIT 1", $generation ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_page_pending_failed' );}
		return null !== $id;
	}

	/** Bounded indexed deletion; retention also applies to unprocessed events. */
	public function prune( int $event_days, int $summary_days ): int {
		global $wpdb;
		$events  = self::events_table();
		$hourly  = self::hourly_table();
		$links   = self::links_table();
		$expired = $wpdb->get_results( $wpdb->prepare( "SELECT id,processed FROM {$events} WHERE occurred_at < %s ORDER BY occurred_at,id LIMIT 500", gmdate( 'Y-m-d H:i:s', time() - $event_days * DAY_IN_SECONDS ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_prune_failed' );
		}
		$lost = 0;
		if ( $expired ) {
			$ids = implode( ',', array_map( 'intval', array_column( $expired, 'id' ) ) );
			$this->must_query( "DELETE FROM {$events} WHERE id IN ({$ids})" );
			$lost = count( array_filter( $expired, static fn( $row ) => ! $row['processed'] ) );
		}

		if ( $summary_days > 0 ) {
			$this->must_query( $wpdb->prepare( "DELETE FROM {$hourly} WHERE bucket_start < %s ORDER BY bucket_start LIMIT 500", gmdate( 'Y-m-d H:00:00', time() - $summary_days * DAY_IN_SECONDS ) ) );
		}
		// Permanent deletion is uncommon; remove at most 100 orphaned link IDs per run.
		$orphans = $wpdb->get_col( "SELECT DISTINCT h.link_id FROM {$hourly} h LEFT JOIN {$links} l ON l.id = h.link_id WHERE l.id IS NULL LIMIT 10" );
		foreach ( $orphans as $id ) {
			$this->must_query( $wpdb->prepare( "DELETE FROM {$hourly} WHERE link_id = %d LIMIT 500", $id ) );
			$this->must_query( $wpdb->prepare( "DELETE FROM {$events} WHERE link_id = %d LIMIT 500", $id ) );
		}
		return $lost;
	}

	/** Maintenance-only health inspection. Never called by the collector. */
	public function health(): array {
		global $wpdb;
		$events = self::events_table();
		$hourly = self::hourly_table();
		$oldest = $wpdb->get_var( "SELECT occurred_at FROM {$events} WHERE processed = 0 ORDER BY id LIMIT 1" );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_health_failed' );
		}
		$bytes = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s)', $events, $hourly ) );
		if ( null === $bytes || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_storage_failed' );
		}
		return array(
			'bytes'          => (int) $bytes,
			'oldest_pending' => $oldest,
			'lag'            => $oldest ? max( 0, time() - strtotime( $oldest . ' UTC' ) ) : 0,
		);
	}

	/** Return bounded report data using hourly summaries, never raw events. */
	public function report( array $filters ): array {
		global $wpdb;
		$hourly       = self::hourly_table();
		$links        = self::links_table();
		$where        = $this->report_where( $filters );
		$total_where  = $this->totals_where( $filters );
		$detail_where = $where . $wpdb->prepare( ' AND h.page_key=%s', empty( $filters['referrer'] ) ? '' : hash( 'sha256', $filters['referrer'] ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- report_where prepares values; remaining SQL is static.
		$total = (int) $wpdb->get_var( "SELECT COALESCE(SUM(h.clicks),0) FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$total_where}" );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_report_failed' );
		}
		$bucket = $this->local_bucket_sql( $filters );
		$trend  = $wpdb->get_results( "SELECT {$bucket} day, MIN(h.bucket_start) first_utc, SUM(h.clicks) clicks FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$total_where} GROUP BY day ORDER BY first_utc", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_report_failed' );
		}
		$top = $wpdb->get_results( "SELECT h.link_id, l.name, SUM(h.clicks) clicks FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$total_where} GROUP BY h.link_id,l.name ORDER BY clicks DESC,h.link_id LIMIT 50", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_report_failed' );
		}
		$dimension = $filters['dimension'];
		$breakdown = $wpdb->get_results( $wpdb->prepare( "SELECT h.value, SUM(h.clicks) clicks FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$detail_where} AND h.dimension=%s GROUP BY h.value ORDER BY clicks DESC,h.value LIMIT 200", $dimension ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_report_failed' );
		}
		$period     = strtotime( $filters['to_utc'] . ' UTC' ) - strtotime( $filters['from_utc'] . ' UTC' );
		$prior      = array_merge(
			$filters,
			array(
				'from_utc' => $filters['prior_from_utc'] ?? gmdate( 'Y-m-d H:i:s', strtotime( $filters['from_utc'] . ' UTC' ) - $period ),
				'to_utc'   => $filters['from_utc'],
			)
		);
		$prev_where = $this->totals_where( $prior );
		$previous   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(h.clicks),0) FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$prev_where}" );
		// SQL below is also prepared by this data layer.
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_report_failed' );
		}
		$missing = 0;
		if ( ! empty( $filters['referrer'] ) ) {
			$covered = (int) $wpdb->get_var( "SELECT COALESCE(SUM(h.clicks),0) FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$detail_where} AND h.dimension='total'" );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'analytics_page_coverage_failed' ); }
			$missing = max( 0, $total - $covered );
			if ( $missing ) {
				$breakdown[] = array(
					'value'  => '_not_recorded',
					'clicks' => $missing,
				); }
		}
		if ( ! empty( $filters['referrer'] ) && 'page' === $dimension ) {
			$breakdown = array(
				array(
					'value'  => $filters['referrer'],
					'clicks' => $total,
				),
			);
			$missing   = 0;
		}
		// Include historical clicks whose page was never captured, without inventing URLs.
		$pages = ! empty( $filters['referrer'] ) ? array(
			array(
				'value'  => $filters['referrer'],
				'clicks' => $total,
			),
		) : $wpdb->get_results( "SELECT value, SUM(clicks) clicks FROM (SELECT h.value, SUM(h.clicks) clicks FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$where} AND h.page_key='' AND h.dimension='page' GROUP BY h.value UNION ALL SELECT '', {$total} - COALESCE(SUM(h.clicks),0) FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$where} AND h.page_key='' AND h.dimension='page') page_counts GROUP BY value HAVING SUM(clicks) > 0 ORDER BY clicks DESC, value LIMIT 20", ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_pages_report_failed' ); }
		return array(
			'total'               => $total,
			'previous'            => $previous,
			'trend'               => $trend,
			'links'               => $top,
			'pages'               => $pages,
			'details_unavailable' => $missing,
			'breakdown'           => $breakdown,
			'filters'             => $filters,
			'timezone'            => wp_timezone_string(),
		);
	}

	/** Keyset pagination within the export's consistent read snapshot. */
	public function export_page( array $filters, array $cursor ): array {
		global $wpdb;
		$hourly = self::hourly_table();
		$links  = self::links_table();
		$where  = $this->export_where( $filters );
		$sql    = "SELECT h.*,l.name FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$where}";
		if ( $cursor ) {
			$sql .= $wpdb->prepare( ' AND (h.link_id,h.bucket_start,h.dimension,h.value) > (%d,%s,%s,%s)', $cursor[0], $cursor[1], $cursor[2], $cursor[3] );
		}
		$sql .= ' ORDER BY h.link_id,h.bucket_start,h.dimension,h.value LIMIT 500';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All clauses prepared in this method.
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'analytics_export_failed' );
		}
		return $rows;
	}

	/** Refuse an oversized export before sending a partial CSV. */
	public function export_size_allowed( array $filters ): bool {
		global $wpdb;
		$hourly = self::hourly_table();
		$links  = self::links_table();
		$where  = $this->export_where( $filters );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- report_where prepares all values.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$hourly} h INNER JOIN {$links} l ON l.id=h.link_id WHERE {$where} LIMIT 100001) bounded_export" );
		return null !== $count && '' === $wpdb->last_error && (int) $count <= 100000;
	}

	public function start_export(): void {
		$this->must_query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->must_query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' );
	}

	public function finish_export(): void {
		$this->must_query( 'COMMIT' );
	}

	/** Calendar grouping uses the WordPress timezone, including fractional offsets and DST folds. */
	private function local_bucket_sql( array $filters ): string {
		global $wpdb;
		$tz       = wp_timezone();
		$start    = strtotime( $filters['from_utc'] . ' UTC' );
		$end      = strtotime( $filters['to_utc'] . ' UTC' );
		$segments = $tz->getTransitions( $start, $end );
		if ( false === $segments ) {
			$segments = array(
				array(
					'ts'     => $start,
					'offset' => $tz->getOffset( new DateTimeImmutable( '@' . $start ) ),
				),
			);
		}
		$clauses = array();
		foreach ( $segments as $index => $segment ) {
			$offset = (int) $segment['offset'];
			$until  = $segments[ $index + 1 ]['ts'] ?? $end;
			if ( 'hour' === ( $filters['granularity'] ?? 'day' ) ) {
				$label     = sprintf( ' %s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( abs( $offset ), 3600 ), intdiv( abs( $offset ) % 3600, 60 ) );
				$clauses[] = $wpdb->prepare( "WHEN h.bucket_start >= %s AND h.bucket_start < %s THEN CONCAT(DATE_FORMAT(DATE_ADD(h.bucket_start, INTERVAL %d SECOND), '%%Y-%%m-%%d %%H:00:00'), %s)", gmdate( 'Y-m-d H:i:s', $segment['ts'] ), gmdate( 'Y-m-d H:i:s', $until ), $offset, $label );
			} else {
				$clauses[] = $wpdb->prepare( "WHEN h.bucket_start >= %s AND h.bucket_start < %s THEN DATE_FORMAT(DATE_ADD(h.bucket_start, INTERVAL %d SECOND), '%%Y-%%m-%%d')", gmdate( 'Y-m-d H:i:s', $segment['ts'] ), gmdate( 'Y-m-d H:i:s', $until ), $offset );
			}
		}
		return 'CASE ' . implode( ' ', $clauses ) . ' END';
	}

	private function totals_where( array $filters ): string {
		global $wpdb;
		$where = $this->report_where( $filters ) . " AND h.page_key=''";
		return empty( $filters['referrer'] ) ? $where . " AND h.dimension='total'" : $where . $wpdb->prepare( " AND h.dimension='page' AND h.value=%s", $filters['referrer'] );
	}

	private function export_where( array $filters ): string {
		global $wpdb;
		$where = $this->report_where( $filters );
		if ( empty( $filters['referrer'] ) ) {
			return $where . " AND h.page_key=''";}
		return $where . $wpdb->prepare( " AND ((h.page_key='' AND h.dimension='page' AND h.value=%s) OR (h.page_key=%s AND h.dimension<>'total'))", $filters['referrer'], hash( 'sha256', $filters['referrer'] ) );
	}

	private function report_where( array $filters ): string {
		global $wpdb;
		$where = $wpdb->prepare( 'h.bucket_start >= %s AND h.bucket_start < %s', $filters['from_utc'], $filters['to_utc'] );
		if ( ! empty( $filters['link_id'] ) ) {
			$where .= $wpdb->prepare( ' AND h.link_id = %d', $filters['link_id'] );
		}
		if ( ! empty( $filters['category_id'] ) ) {
			$where .= $wpdb->prepare( ' AND l.category_id = %d', $filters['category_id'] );
		}
		return $where;
	}

	private function must_query( string $sql ): int {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Private method accepts only prepared internal statements.
			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				throw new RuntimeException( 'analytics_database_failed' );
			}
			return (int) $result;
		} finally {
			// Aggregate values must not escape their retention policy via database error logs.
			$wpdb->suppress_errors( $old );
		}
	}
}
