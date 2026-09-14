<?php
/**
 * Analytics opt-in lifecycle and bounded maintenance; never loaded by ordinary pages.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gtlm-analytics-db.php';

class GTLM_Analytics {
	public const OPTION = 'gtlm_analytics';
	public const CRON   = 'gtlm_analytics_maintenance';
	public const SCHEMA = '3';

	public static function config(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Pure validation. No option writes or schema probes. */
	public static function validate( array $input, array $previous = array() ) {
		$options    = array();
		$event_days = $input['event_days'] ?? $previous['event_days'] ?? 7;
		if ( ( ! is_int( $event_days ) && ! is_string( $event_days ) ) || ! ctype_digit( (string) $event_days ) || false === filter_var( $event_days, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) {
			return new WP_Error( 'gtlm_analytics_retention', __( 'Enter a positive whole number of days for individual click records.', 'gt-link-manager' ) );
		}
		$options['event_days'] = (int) $event_days;
		foreach ( array(
			'summary_days' => array( 90, 7, 90 ),
		) as $key => $range ) {
			$value = $input[ $key ] ?? $previous[ $key ] ?? $range[0];
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || ( (int) $value < $range[1] && ! ( 'summary_days' === $key && '0' === (string) $value ) ) || (int) $value > $range[2] ) {
				return new WP_Error( 'gtlm_analytics_retention', __( 'Choose a supported retention period.', 'gt-link-manager' ) );
			}
			$options[ $key ] = (int) $value;
		}
		$options['country_source'] = $input['country_source'] ?? $previous['country_source'] ?? 'none';
		if ( ! in_array( $options['country_source'], array( 'none', 'auto', 'cloudflare', 'custom' ), true ) ) {
			return new WP_Error( 'gtlm_analytics_country', __( 'Choose a supported country header source.', 'gt-link-manager' ) );
		}
		$header = $input['country_header'] ?? $previous['country_header'] ?? '';
		if ( ! is_string( $header ) || strlen( $header ) > 100 || ( '' !== $header && ! preg_match( '/^[a-z0-9_-]+$/i', $header ) ) || ( 'custom' === $options['country_source'] && ( '' === $header || ! preg_match( '/country/i', $header ) ) ) ) {
			return new WP_Error( 'gtlm_analytics_header', __( 'Enter a country header name, such as X-Geo-Country.', 'gt-link-manager' ) );
		}
		$options['country_header'] = strtoupper( $header );
		$excluded                  = $input['exclude_links'] ?? $previous['exclude_links'] ?? array();
		if ( ! is_array( $excluded ) || count( $excluded ) > 1000 ) {
			return new WP_Error( 'gtlm_analytics_exclusions', __( 'Use a bounded list of link IDs to exclude.', 'gt-link-manager' ) );
		}
		foreach ( $excluded as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
				return new WP_Error( 'gtlm_analytics_exclusions', __( 'Excluded link IDs must be positive integers.', 'gt-link-manager' ) );
			}
		}
		$options['exclude_links'] = array_values( array_unique( array_map( 'intval', $excluded ) ) );
		$campaigns                = $input['campaigns'] ?? $previous['campaigns'] ?? array();
		if ( ! is_array( $campaigns ) || count( $campaigns ) > 100 ) {
			return new WP_Error( 'gtlm_analytics_campaigns', __( 'Use at most 100 configured campaigns.', 'gt-link-manager' ) );
		}
		$registry = array_column( $previous['campaigns'] ?? array(), null, 'id' );
		foreach ( $registry as &$item ) {
			$item['enabled'] = false;
		}
		unset( $item );
		$seen = array();
		foreach ( $campaigns as $campaign ) {
			if ( ! is_array( $campaign ) || ! isset( $campaign['id'] ) || ! is_scalar( $campaign['id'] ) || ! ctype_digit( (string) $campaign['id'] ) || (int) $campaign['id'] < 1 || (int) $campaign['id'] > 2147483647 || isset( $seen[ (int) $campaign['id'] ] ) ) {
				return new WP_Error( 'gtlm_analytics_campaigns', __( 'Campaign IDs must be unique positive integers.', 'gt-link-manager' ) );
			}
			$id          = (int) $campaign['id'];
			$seen[ $id ] = true;
			$next        = array( 'id' => $id );
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
				$value = $campaign[ $key ] ?? '';
				if ( ! is_string( $value ) || strlen( $value ) > 64 || ( '' !== $value && ! preg_match( '/^[a-z0-9._-]+$/i', $value ) ) || ( isset( $registry[ $id ] ) && $registry[ $id ][ $key ] !== $value ) ) {
					return new WP_Error( 'gtlm_analytics_campaigns', __( 'Use short campaign tokens. Existing IDs cannot be reassigned to different UTM values.', 'gt-link-manager' ) );
				}
				$next[ $key ] = $value;
			}
			if ( '' === implode( '', array_slice( $next, 1 ) ) ) {
				return new WP_Error( 'gtlm_analytics_campaigns', __( 'A campaign needs at least one UTM value.', 'gt-link-manager' ) );
			}
			$next['enabled'] = ! isset( $campaign['enabled'] ) || (bool) $campaign['enabled'];
			$registry[ $id ] = $next;
		}
		$options['campaigns'] = array_values( $registry );
		if ( count( $registry ) > 100 || strlen( wp_json_encode( $options ) ) > 24576 ) {
			return new WP_Error( 'gtlm_analytics_config_size', __( 'Analytics settings are too large. Reduce campaigns or exclusions.', 'gt-link-manager' ) );
		}
		return $options;
	}

	/** Upgrade only previously opted-in storage, preserving data, consent and generation. */
	public static function upgrade() {
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() || self::SCHEMA === ( self::config()['schema'] ?? '' ) ) {
			return true; }
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'Analytics maintenance is busy. Try again shortly.', 'gt-link-manager' ) ); }
		try {
			self::upgrade_config( $db, self::fresh_config() );
			return true;
		} catch ( Throwable $error ) {
			return new WP_Error( 'gtlm_analytics_upgrade', __( 'Analytics storage could not be upgraded. Retry from maintenance.', 'gt-link-manager' ) );
		} finally {
			$db->unlock(); }
	}

	/** The caller holds the analytics control lock. No generation change or history reset. */
	private static function upgrade_config( GTLM_Analytics_DB $db, array $config ): array {
		if ( in_array( $config['schema'] ?? '', array( '1', '2' ), true ) ) {
			if ( ! $db->install() ) {
				throw new RuntimeException( 'analytics_schema_upgrade_failed' ); }
			$config['schema']           = self::SCHEMA;
			$config['pages_started_at'] = $config['pages_started_at'] ?? gmdate( 'Y-m-d H:i:s' );
			self::save( $config );
		}
		return $config;
	}

	/** Called from an authenticated deliberate enable/resume action, including CLI. */
	public static function enable( array $input = array() ) {
		if ( is_multisite() ) {
			return new WP_Error( 'gtlm_analytics_site_scope', __( 'Advanced analytics currently supports single-site installations only. No analytics storage was created.', 'gt-link-manager' ) );
		}
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'Analytics maintenance is busy. Try again shortly.', 'gt-link-manager' ) );
		}
		try {
			$previous = self::fresh_config();
			$options  = self::validate( $input, $previous );
			if ( is_wp_error( $options ) ) {
				return $options;
			}
			if ( ! $db->install() ) {
				return new WP_Error( 'gtlm_analytics_schema', __( 'Analytics could not initialize its InnoDB tables. Collection remains off; retry or delete the partial analytics storage.', 'gt-link-manager' ) );
			}
			if ( in_array( $previous['schema'] ?? '', array( '1', '2' ), true ) ) {
				$previous['schema']           = self::SCHEMA;
				$previous['pages_started_at'] = $previous['pages_started_at'] ?? gmdate( 'Y-m-d H:i:s' );
			}
			if ( $previous && self::SCHEMA === ( $previous['schema'] ?? '' ) ) {
				self::work( $db, $previous );
				if ( ! empty( $db->health()['oldest_pending'] ) || $db->has_page_pending( $previous['generation'] ) ) {
					return new WP_Error( 'gtlm_analytics_pending', __( 'Process pending analytics before changing collection settings.', 'gt-link-manager' ) );
				}
			}
			$config              = array_merge(
				$previous,
				$options,
				array(
					'schema'           => self::SCHEMA,
					'state'            => 'paused',
					'generation'       => str_replace( '-', '', wp_generate_uuid4() ),
					'started_at'       => $previous['started_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					'pages_started_at' => $previous['pages_started_at'] ?? gmdate( 'Y-m-d H:i:s' ),
					'last_enabled_at'  => gmdate( 'Y-m-d H:i:s' ),
					'lease_until'      => 0,
					'periods'          => array_slice( (array) ( $previous['periods'] ?? array() ), -30 ),
				)
			);
			$config['periods'][] = array(
				'at'    => gmdate( 'Y-m-d H:i:s' ),
				'state' => 'active',
			);
			$health              = $db->health();
			if ( $health['bytes'] >= 100 * MB_IN_BYTES ) {
				return new WP_Error( 'gtlm_analytics_full', __( 'Analytics storage is at its safety threshold. Prune or delete old data before resuming.', 'gt-link-manager' ) );
			}
			add_filter( 'cron_schedules', 'gtlm_analytics_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Dedicated 300-second interval registered only after opt-in.
			if ( ! wp_next_scheduled( self::CRON ) && ! wp_schedule_event( time() + 60, 'gtlm_five_minutes', self::CRON ) ) {
				return new WP_Error( 'gtlm_analytics_schedule', __( 'Analytics maintenance could not be scheduled. Collection remains off.', 'gt-link-manager' ) );
			}
			$config['state']             = 'active';
			$config['lease_until']       = time() + 900;
			$config['health']            = $health;
			$config['last_processed_at'] = gmdate( 'Y-m-d H:i:s' );
			self::save( $config );
			GTLM_Settings::get_instance()->set_analytics_gate( true );
			if ( ! GTLM_Settings::get_instance()->advanced_analytics_enabled() ) {
				throw new RuntimeException( 'analytics_gate_failed' );
			}
			return self::status();
		} catch ( Throwable $error ) {
			GTLM_Settings::get_instance()->set_analytics_gate( false );
			return new WP_Error( 'gtlm_analytics_setup', __( 'Analytics setup failed. Redirects remain available and collection is off.', 'gt-link-manager' ) );
		} finally {
			$db->unlock();
		}
	}

	/** Save preferences without implicitly resuming paused collection. */
	public static function configure( array $input, bool $enabled ) {
		$settings = GTLM_Settings::get_instance();
		if ( ! $settings->analytics_initialized() ) {
			return $enabled ? self::enable( $input ) : array(
				'state'   => 'disabled',
				'enabled' => false,
			);
		}
		if ( $enabled && ! $settings->advanced_analytics_enabled() ) {
			return self::enable( $input );
		}
		if ( ! $enabled ) {
			$paused = self::pause();
			if ( is_wp_error( $paused ) ) {
				return $paused; }
		}
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'Analytics is busy. Try saving again shortly.', 'gt-link-manager' ) ); }
		try {
			$config  = self::fresh_config();
			$options = self::validate( $input, $config );
			if ( is_wp_error( $options ) ) {
				return $options; }
			self::save( array_merge( $config, $options ) );
			return self::status();
		} finally {
			$db->unlock(); }
	}

	public static function pause( bool $deactivating = false ) {
		GTLM_Settings::get_instance()->set_analytics_gate( false );
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'Collection stopped. Maintenance is busy; retry to finish pausing.', 'gt-link-manager' ) );
		}
		try {
			$config = self::fresh_config();
			if ( $config ) {
				$config['state']       = 'paused';
				$config['lease_until'] = 0;
				$config['periods']     = array_slice( (array) ( $config['periods'] ?? array() ), -30 );
				$config['periods'][]   = array(
					'at'    => gmdate( 'Y-m-d H:i:s' ),
					'state' => 'paused',
				);
				self::save( $config );
			}
			if ( $deactivating ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return self::status();
		} finally {
			$db->unlock();
		}
	}

	public static function delete() {
		GTLM_Settings::get_instance()->set_analytics_gate( false );
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'Collection stopped. Retry deleting after maintenance finishes.', 'gt-link-manager' ) );
		}
		try {
			if ( ! $db->drop() ) {
				return new WP_Error( 'gtlm_analytics_delete', __( 'Analytics storage could not be removed. Collection is off.', 'gt-link-manager' ) );
			}
			wp_clear_scheduled_hook( self::CRON );
			delete_option( self::OPTION );
			GTLM_Settings::get_instance()->set_analytics_gate( null );
			return array(
				'state'   => 'disabled',
				'enabled' => false,
			);
		} finally {
			$db->unlock();
		}
	}

	/** One invocation, bounded batches and wall time. No maintenance on redirects. */
	public static function process() {
		$db = new GTLM_Analytics_DB();
		if ( ! $db->lock() ) {
			return new WP_Error( 'gtlm_analytics_busy', __( 'A maintenance process is already running.', 'gt-link-manager' ) );
		}
		try {
			$config = self::upgrade_config( $db, self::fresh_config() );
			if ( ! $config || self::SCHEMA !== ( $config['schema'] ?? '' ) ) {
				return new WP_Error( 'gtlm_analytics_disabled', __( 'Analytics has not been initialized.', 'gt-link-manager' ) );
			}
			self::work( $db, $config );
			return self::status();
		} catch ( Throwable $error ) {
			$config = self::fresh_config();
			if ( $config ) {
				$config['state']       = 'unhealthy';
				$config['lease_until'] = 0;
				$config['last_error']  = 'maintenance_failed';
				self::save( $config );
			}
			return new WP_Error( 'gtlm_analytics_maintenance', __( 'Analytics maintenance failed. Collection is paused until maintenance succeeds.', 'gt-link-manager' ) );
		} finally {
			$db->unlock();
		}
	}

	private static function work( GTLM_Analytics_DB $db, array $config ): void {
		$start   = microtime( true );
		$cutoff  = $db->event_cutoff( $config['event_days'] );
		$expired = 0;
		do {
			$count    = $db->aggregate_batch( $config['generation'], $cutoff );
			$expired += $db->expired_events;
		} while ( 500 === $count && microtime( true ) - $start < 1.5 );
		while ( microtime( true ) - $start < 1.5 && 500 === $db->aggregate_pages( $config['generation'] ) ) {
			// Page detail backfill shares the same bounded worker budget.
			continue;
		}
		$lost       = $db->prune( $config['event_days'], $config['summary_days'] );
		$health     = $db->health();
		$was_active = GTLM_Settings::get_instance()->advanced_analytics_enabled();
		$limit      = 'unhealthy' === ( $config['state'] ?? '' ) ? 80 : 100;
		$healthy    = $health['bytes'] < $limit * MB_IN_BYTES && $health['lag'] < 900;
		$next_state = $was_active ? ( $healthy ? 'active' : 'unhealthy' ) : 'paused';
		if ( $next_state !== $config['state'] || ( $was_active && (int) ( $config['lease_until'] ?? 0 ) < time() ) ) {
			$config['periods']   = array_slice( (array) ( $config['periods'] ?? array() ), -30 );
			$config['periods'][] = array(
				'at'        => gmdate( 'Y-m-d H:i:s' ),
				'state'     => $next_state,
				'gap_since' => gmdate( 'Y-m-d H:i:s', (int) ( $config['lease_until'] ?? time() ) ),
			);
		}
		$config['state']             = $next_state;
		$config['lease_until']       = $was_active && $healthy ? time() + 900 : 0;
		$config['health']            = $health;
		$config['last_processed_at'] = gmdate( 'Y-m-d H:i:s' );
		$config['expired_pending']   = (int) ( $config['expired_pending'] ?? 0 ) + $lost + $expired;
		unset( $config['last_error'] );
		self::save( $config );
	}

	/** No table inspection; a missing gate can be answered without reading this option. */
	public static function status(): array {
		$config = self::config();
		if ( ! $config ) {
			return array(
				'state'   => 'disabled',
				'enabled' => false,
			);
		}
		$enabled = GTLM_Settings::get_instance()->advanced_analytics_enabled();
		$state   = ! $enabled ? 'paused' : ( (int) ( $config['lease_until'] ?? 0 ) < time() ? 'lease_expired' : $config['state'] );
		foreach ( array( 'started_at', 'last_enabled_at', 'last_processed_at', 'pages_started_at' ) as $key ) {
			if ( ! empty( $config[ $key ] ) ) {
				$config[ $key ] = wp_date( DATE_ATOM, strtotime( $config[ $key ] . ' UTC' ) );
			}
		}
		if ( ! empty( $config['health']['oldest_pending'] ) ) {
			$config['health']['oldest_pending'] = wp_date( DATE_ATOM, strtotime( $config['health']['oldest_pending'] . ' UTC' ) );
		}
		$config['periods'] = (array) ( $config['periods'] ?? array() );
		foreach ( $config['periods'] as &$period ) {
			foreach ( array( 'at', 'gap_since' ) as $key ) {
				if ( ! empty( $period[ $key ] ) ) {
					$period[ $key ] = wp_date( DATE_ATOM, strtotime( $period[ $key ] . ' UTC' ) );
				}
			}
		}
		unset( $period );
		return array_merge(
			$config,
			array(
				'state'    => $state,
				'enabled'  => $enabled,
				'timezone' => wp_timezone_string(),
			)
		);
	}

	/** Read fresh control state after acquiring a cross-request lock. */
	private static function fresh_config(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'gtlm_settings', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return self::config();
	}

	private static function save( array $config ): void {
		if ( strlen( wp_json_encode( $config ) ) > 32768 ) {
			throw new RuntimeException( 'analytics_config_too_large' );
		}
		update_option( self::OPTION, $config, false );
		wp_cache_delete( self::OPTION, 'options' );
		if ( self::config() !== $config ) {
			throw new RuntimeException( 'analytics_settings_failed' );
		}
	}
}
