<?php
/**
 * Explicit analytics operations for authenticated server operators.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Analytics_CLI {
	/** Show collection and processing health without creating storage. */
	public function status(): void {
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
			WP_CLI::line( '{"state":"disabled","enabled":false}' );
			return;
		}
		$this->load();
		WP_CLI::line( wp_json_encode( GTLM_Analytics::status(), JSON_PRETTY_PRINT ) );
	}

	/** Process bounded aggregation/retention batches. Safe for a system cron runner. */
	public function process(): void {
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
			$this->status();
			return;
		}
		$this->load();
		$this->result( GTLM_Analytics::process() );
	}

	/** Enforce retention and process pending events within the same bounded maintenance job. */
	public function prune(): void {
		$this->process();
	}

	/**
	 * Explicitly opt in, optionally with a local JSON settings file.
	 *
	 * ## OPTIONS
	 *
	 * --yes
	 * : Confirm collection with the displayed/documented data fields and finite retention.
	 *
	 * [--config=<file>]
	 * : Local JSON configuration file.
	 */
	public function enable( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'Pass --yes to explicitly opt in to advanced analytics.' );
		}
		$this->load();
		$config = array();
		if ( isset( $assoc_args['config'] ) ) {
			$file = $assoc_args['config'];
			if ( ! is_file( $file ) || filesize( $file ) > 24576 ) {
				WP_CLI::error( 'Use a local JSON settings file smaller than 24 KiB.' );
			}
			$config = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Explicit local CLI input.
			if ( ! is_array( $config ) ) {
				WP_CLI::error( 'Settings must be a JSON object.' );
			}
		}
		$this->result( GTLM_Analytics::enable( $config ) );
	}

	/** Stop new collection and retain existing reports under their retention policy. */
	public function pause(): void {
		if ( ! GTLM_Settings::get_instance()->analytics_initialized() ) {
			$this->status();
			return;
		}
		$this->load();
		$this->result( GTLM_Analytics::pause() );
	}

	/**
	 * Delete analytics data and jobs, preserving links and legacy counts.
	 *
	 * ## OPTIONS
	 *
	 * --yes
	 * : Confirm permanent deletion of analytics data.
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'Pass --yes to delete all analytics data.' );
		}
		$this->load();
		$this->result( GTLM_Analytics::delete() );
	}

	private function load(): void {
		require_once __DIR__ . '/class-gtlm-analytics.php';
	}

	private function result( $result ): void {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}
}
