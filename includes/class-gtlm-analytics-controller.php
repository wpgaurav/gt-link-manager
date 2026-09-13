<?php
/**
 * Authenticated analytics controls and native WordPress reports. No frontend assets.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Analytics_Controller {
	private static function load(): void {
		require_once __DIR__ . '/class-gtlm-analytics.php';
	}

	public static function rest( WP_REST_Request $request, string $action ) {
		if ( strlen( $request->get_body() ) > 32768 ) {
			return new WP_Error( 'gtlm_analytics_request', __( 'Request too large.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}
		self::load();
		try {
			if ( in_array( $action, array( 'enable', 'resume' ), true ) ) {
				if ( ! in_array( $request->get_param( 'consent' ), array( true, 1, '1' ), true ) ) {
					return new WP_Error( 'gtlm_analytics_consent', __( 'Explicitly opt in before enabling analytics.', 'gt-link-manager' ), array( 'status' => 400 ) );
				}
				$result = GTLM_Analytics::enable( $request->get_params() );
			} elseif ( 'pause' === $action ) {
				$result = GTLM_Analytics::pause();
			} elseif ( 'delete' === $action ) {
				if ( 'DELETE_ANALYTICS' !== $request->get_param( 'confirm' ) ) {
					return new WP_Error( 'gtlm_analytics_confirm', __( 'Confirm deletion of all analytics data.', 'gt-link-manager' ), array( 'status' => 400 ) );
				}
				$result = GTLM_Analytics::delete();
			} elseif ( 'status' === $action ) {
				$result = GTLM_Analytics::status();
			} else {
				$result = self::report( $request->get_params() );
			}
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
			$response = new WP_REST_Response( $result );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( Throwable $error ) {
			return new WP_Error( 'gtlm_analytics_unavailable', __( 'Analytics is temporarily unavailable. Redirects remain available.', 'gt-link-manager' ), array( 'status' => 503 ) );
		}
	}

	/** Strict bounded report filters. Dates are inclusive calendar dates in the WordPress site timezone. */
	public static function filters( array $input ) {
		$now  = current_datetime();
		$from = $input['from'] ?? $now->modify( '-29 days' )->format( 'Y-m-d' );
		$to   = $input['to'] ?? $now->format( 'Y-m-d' );
		foreach ( array( $from, $to ) as $date ) {
			if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || gmdate( 'Y-m-d', (int) strtotime( $date . ' UTC' ) ) !== $date ) {
				return new WP_Error( 'gtlm_analytics_dates', __( 'Use valid dates in YYYY-MM-DD format.', 'gt-link-manager' ) );
			}
		}
		$start = new DateTimeImmutable( $from, wp_timezone() );
		$end   = ( new DateTimeImmutable( $to, wp_timezone() ) )->modify( '+1 day' );
		if ( $start >= $end || $start->diff( $end )->days > 90 || $to > $now->format( 'Y-m-d' ) || $from < $now->modify( '-89 days' )->format( 'Y-m-d' ) ) {
			return new WP_Error( 'gtlm_analytics_dates', __( 'Select a range within the last 90 days in the WordPress site timezone.', 'gt-link-manager' ) );
		}
		$dimension = $input['dimension'] ?? 'source';
		if ( ! in_array( $dimension, array( 'source', 'page', 'country', 'device', 'browser', 'os', 'campaign', 'status', 'mode', 'geo' ), true ) ) {
			return new WP_Error( 'gtlm_analytics_dimension', __( 'Choose a supported breakdown.', 'gt-link-manager' ) );
		}
		$result = array(
			'from'           => $from,
			'to'             => $to,
			'from_utc'       => $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'from_local'     => $from,
			'to_local'       => $to,
			'prior_from_utc' => $start->modify( '-' . $start->diff( $end )->days . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'to_utc'         => $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'dimension'      => $dimension,
			'granularity'    => $input['granularity'] ?? 'day',
		);
		if ( ! in_array( $result['granularity'], array( 'day', 'hour' ), true ) ) {
			return new WP_Error( 'gtlm_analytics_granularity', __( 'Choose daily or hourly trends.', 'gt-link-manager' ) );
		}
		foreach ( array( 'link_id', 'category_id' ) as $key ) {
			$value = $input[ $key ] ?? 0;
			if ( '' === $value ) {
				$value = 0;
			}
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || strlen( (string) $value ) > 10 ) {
				return new WP_Error( 'gtlm_analytics_filter', __( 'Use a valid numeric link or category ID.', 'gt-link-manager' ) );
			}
			$result[ $key ] = (int) $value;
		}
		return $result;
	}

	public static function report( array $input ) {
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
			return new WP_Error( 'gtlm_analytics_disabled', __( 'Enable analytics before requesting reports.', 'gt-link-manager' ) );
		}
		$filters = self::filters( $input );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		self::load();
		$config = GTLM_Analytics::config();
		if ( GTLM_Analytics::SCHEMA !== ( $config['schema'] ?? '' ) ) {
			return new WP_Error( 'gtlm_analytics_schema', __( 'Analytics storage is not ready.', 'gt-link-manager' ) );
		}
		$report                       = ( new GTLM_Analytics_DB() )->report( $filters );
		$report['collection']         = GTLM_Analytics::status();
		$span                         = strtotime( $filters['to_utc'] . ' UTC' ) - strtotime( $filters['from_utc'] . ' UTC' );
		$prior_start                  = strtotime( $filters['prior_from_utc'] . ' UTC' );
		$complete                     = $prior_start >= max( strtotime( $config['started_at'] . ' UTC' ), time() - $config['summary_days'] * DAY_IN_SECONDS );
		$report['previous_available'] = $complete;
		if ( ! $complete ) {
			$report['previous'] = null;
		}
		return $report;
	}

	/** Native admin POST handler, called before rendering. */
	public static function handle_admin(): void {
		if ( empty( $_POST['gtlm_analytics_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below before parsing the action.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change analytics settings.', 'gt-link-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gtlm_analytics_settings' );
		$action = is_string( $_POST['gtlm_analytics_action'] ) ? sanitize_key( wp_unslash( $_POST['gtlm_analytics_action'] ) ) : '';
		self::load();
		try {
			if ( in_array( $action, array( 'enable', 'save_settings' ), true ) ) {
				if ( 'enable' === $action && empty( $_POST['consent'] ) ) {
					wp_die( esc_html__( 'Explicitly opt in before enabling analytics.', 'gt-link-manager' ) );
				}
				$campaigns       = isset( $_POST['campaigns'] ) && is_array( $_POST['campaigns'] ) ? wp_unslash( $_POST['campaigns'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict bounded validation follows.
				$previous        = GTLM_Analytics::config();
				$next_id         = max( array_merge( array( 0 ), array_column( $previous['campaigns'] ?? array(), 'id' ) ) ) + 1;
				$clean_campaigns = array();
				if ( count( $campaigns ) > 100 ) {
					wp_die( esc_html__( 'Too many campaign rows.', 'gt-link-manager' ) );
				}
				foreach ( $campaigns as $campaign ) {
					if ( ! is_array( $campaign ) ) {
						wp_die( esc_html__( 'Invalid campaign row.', 'gt-link-manager' ) );
					}
					if ( empty( $campaign['utm_source'] ) && empty( $campaign['utm_medium'] ) && empty( $campaign['utm_campaign'] ) ) {
						continue;
					}
					if ( empty( $campaign['id'] ) ) {
						$campaign['id'] = $next_id++;
					}
					$clean_campaigns[] = $campaign;
				}
				$excluded = isset( $_POST['exclude_links'] ) && is_string( $_POST['exclude_links'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_links'] ) ) : '';
				$advanced = array(
					'campaigns'     => $clean_campaigns,
					'exclude_links' => preg_split( '/[\s,]+/', $excluded, -1, PREG_SPLIT_NO_EMPTY ),
				);
				$input    = array();
				foreach ( array( 'event_days', 'summary_days', 'country_source', 'country_header' ) as $key ) {
					$input[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
				}
				$input['country_source'] = 'save_settings' === $action && empty( $_POST['countries'] ) ? 'none' : $input['country_source'];
				$result                  = 'save_settings' === $action ? GTLM_Analytics::configure( array_merge( $advanced, $input ), ! empty( $_POST['enabled'] ) ) : GTLM_Analytics::enable( array_merge( $advanced, $input ) );
			} elseif ( 'pause' === $action ) {
				$result = GTLM_Analytics::pause();
			} elseif ( 'process' === $action ) {
				$result = GTLM_Analytics::process();
			} elseif ( 'delete' === $action && isset( $_POST['confirm_delete'] ) && 'DELETE_ANALYTICS' === $_POST['confirm_delete'] ) {
				$result = GTLM_Analytics::delete();
			} else {
				wp_die( esc_html__( 'Invalid analytics action or missing confirmation.', 'gt-link-manager' ) );
			}
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
			}
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'Analytics could not complete this action. Redirects remain available.', 'gt-link-manager' ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=gtlm-links-analytics&view=settings&saved=1' ) );
		exit;
	}

	public static function render(): void {
		require_once __DIR__ . '/class-gtlm-analytics-view.php';
		GTLM_Analytics_View::render();
	}

	public static function export(): void {
		if ( ! current_user_can( (string) apply_filters( 'gtlm_analytics_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You cannot export analytics.', 'gt-link-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gtlm_analytics_export' );
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
			wp_die( esc_html__( 'Analytics is disabled.', 'gt-link-manager' ) );
		}
		$filters = self::filters( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict typed filter validation.
		if ( is_wp_error( $filters ) ) {
			wp_die( esc_html( $filters->get_error_message() ) );
		}
		self::load();
		require_once __DIR__ . '/class-gtlm-csv.php';
		$db = new GTLM_Analytics_DB();
		$db->start_export();
		if ( ! $db->export_size_allowed( $filters ) ) {
			$db->finish_export();
			wp_die( esc_html__( 'This export is too large or unavailable. Select a smaller date range or a single link.', 'gt-link-manager' ) );
		}
		try {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="gtlm-analytics-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $output ) {
				return;
			}
			fputcsv( $output, array( 'link_id', 'name', 'time', 'timezone', 'dimension', 'value', 'clicks' ), ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			$cursor = array();
			do {
				$rows = $db->export_page( $filters, $cursor );
				foreach ( $rows as $row ) {
					$values = array( $row['link_id'], $row['name'], wp_date( 'Y-m-d H:i:s P', strtotime( $row['bucket_start'] . ' UTC' ) ), wp_timezone_string(), $row['dimension'], $row['value'], $row['clicks'] );
					$values = array_map(
						static function ( $value ): string {
							$value = (string) $value;
							return GTLM_CSV::text( $value );
						},
						$values
					);
					fputcsv( $output, $values, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
					$cursor = array( $row['link_id'], $row['bucket_start'], $row['dimension'], $row['value'] );
				}
				$more = 500 === count( $rows );
			} while ( $more && ! connection_aborted() );
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} finally {
			$db->finish_export();
		}
		exit;
	}
}
