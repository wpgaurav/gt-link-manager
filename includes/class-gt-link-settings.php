<?php
/**
 * Settings handler.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Settings {
	private const OPTION_KEY = 'gtlm_settings';

	private static ?GTLM_Settings $instance = null;

	/**
	 * Singleton instance.
	 */
	public static function get_instance(): GTLM_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'base_prefix'               => 'go',
			'default_redirect_type'     => 301,
			'default_rel'               => array(),
			'default_noindex'           => 0,
			'delete_data_on_uninstall'  => 0,
			'trash_retention_days'      => 30,
			'enable_click_tracking'     => 0,
			'enable_advanced_redirects' => 0,
			'enable_geo_targeting'      => 0,
			'geo_detection_method'      => 'auto',
			'geo_custom_header'         => '',
			'geo_debug_header'          => 0,
		);
	}

	/**
	 * Allowed geolocation detection methods.
	 *
	 * @return array<int, string>
	 */
	public static function geo_detection_methods(): array {
		return array( 'auto', 'cloudflare', 'custom' );
	}

	/**
	 * All settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		$settings['base_prefix']               = $this->sanitize_prefix( (string) $settings['base_prefix'] );
		$settings['default_redirect_type']     = $this->sanitize_redirect_type( (int) $settings['default_redirect_type'] );
		$settings['default_noindex']           = (int) ! empty( $settings['default_noindex'] );
		$settings['default_rel']               = $this->sanitize_rel_array( $settings['default_rel'] );
		$settings['delete_data_on_uninstall']  = (int) ! empty( $settings['delete_data_on_uninstall'] );
		$settings['trash_retention_days']      = $this->sanitize_retention_days( $settings['trash_retention_days'] ?? 30 );
		$settings['enable_click_tracking']     = (int) ! empty( $settings['enable_click_tracking'] );
		$settings['enable_advanced_redirects'] = (int) ! empty( $settings['enable_advanced_redirects'] );
		$settings['enable_geo_targeting']      = (int) ! empty( $settings['enable_geo_targeting'] );
		$settings['geo_detection_method']      = $this->sanitize_geo_method( (string) ( $settings['geo_detection_method'] ?? 'auto' ) );
		$settings['geo_custom_header']         = $this->sanitize_header_name( (string) ( $settings['geo_custom_header'] ?? '' ) );
		$settings['geo_debug_header']          = (int) ! empty( $settings['geo_debug_header'] );

		/**
		 * Filter effective plugin settings.
		 *
		 * @param array<string, mixed> $settings Settings.
		 */
		return (array) apply_filters( 'gtlm_settings', $settings );
	}

	/**
	 * Prefix from settings/filter.
	 */
	public function prefix(): string {
		$settings = $this->all();
		$prefix   = isset( $settings['base_prefix'] ) ? (string) $settings['base_prefix'] : 'go';

		/**
		 * Filter redirect prefix.
		 *
		 * @param string $prefix Prefix.
		 */
		$prefix = (string) apply_filters( 'gtlm_prefix', $prefix );

		return $this->sanitize_prefix( $prefix );
	}

	/**
	 * Update settings in one write.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 */
	public function update( array $settings ): bool {
		$next = array(
			'base_prefix'               => $this->sanitize_prefix( (string) ( $settings['base_prefix'] ?? 'go' ) ),
			'default_redirect_type'     => $this->sanitize_redirect_type( (int) ( $settings['default_redirect_type'] ?? 301 ) ),
			'default_noindex'           => (int) ! empty( $settings['default_noindex'] ),
			'default_rel'               => $this->sanitize_rel_array( $settings['default_rel'] ?? array() ),
			'delete_data_on_uninstall'  => (int) ! empty( $settings['delete_data_on_uninstall'] ),
			'trash_retention_days'      => $this->sanitize_retention_days( $settings['trash_retention_days'] ?? 30 ),
			'enable_click_tracking'     => (int) ! empty( $settings['enable_click_tracking'] ),
			'enable_advanced_redirects' => (int) ! empty( $settings['enable_advanced_redirects'] ),
			'enable_geo_targeting'      => (int) ! empty( $settings['enable_geo_targeting'] ),
			'geo_detection_method'      => $this->sanitize_geo_method( (string) ( $settings['geo_detection_method'] ?? 'auto' ) ),
			'geo_custom_header'         => $this->sanitize_header_name( (string) ( $settings['geo_custom_header'] ?? '' ) ),
			'geo_debug_header'          => (int) ! empty( $settings['geo_debug_header'] ),
		);

		// Merge only ordinary fields; consent belongs exclusively to its explicit controls.
		$updated = ( new GTLM_DB() )->merge_settings( $next );

		if ( $updated ) {
			do_action( 'gtlm_settings_saved', $next );
		}

		return (bool) $updated;
	}

	/**
	 * Days to keep trashed links. 0 keeps them until deleted by hand.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_retention_days( mixed $value ): int {
		return max( 0, min( 365, absint( $value ) ) );
	}

	/**
	 * Whether the opt-in click counter is enabled.
	 *
	 * Off by default: with tracking disabled the plugin's privacy statement
	 * that it does not log requests stays literally true.
	 */
	public function click_tracking_enabled(): bool {
		return ! empty( $this->all()['enable_click_tracking'] );
	}

	/** Persisted owner consent only; generic settings filters cannot manufacture it. */
	public function advanced_analytics_enabled(): bool {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) && ! empty( $stored['enable_advanced_analytics'] );
	}

	public function analytics_initialized(): bool {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) && array_key_exists( 'enable_advanced_analytics', $stored );
	}

	/** Lifecycle controllers own this flag. null forgets analytics after explicit deletion. */
	public function set_analytics_gate( ?bool $enabled ): void {
		if ( null === $enabled ) {
			( new GTLM_DB() )->merge_settings( array(), array( 'enable_advanced_analytics' ) );
		} else {
			( new GTLM_DB() )->merge_settings( array( 'enable_advanced_analytics' => (int) $enabled ), array(), $enabled ? array() : array( 'enable_advanced_analytics' ) );
		}
	}

	/**
	 * Retention window for trashed links, in days. 0 means keep forever.
	 */
	public function trash_retention_days(): int {
		return (int) $this->all()['trash_retention_days'];
	}

	private function sanitize_prefix( string $prefix ): string {
		$prefix = strtolower( trim( $prefix ) );
		$prefix = preg_replace( '/[^a-z0-9-]/', '', $prefix ) ?? '';

		if ( '' === $prefix ) {
			$prefix = 'go';
		}

		return trim( $prefix, '/' );
	}

	private function sanitize_redirect_type( int $type ): int {
		$allowed = array( 301, 302, 307 );
		return in_array( $type, $allowed, true ) ? $type : 301;
	}

	private function sanitize_geo_method( string $method ): string {
		$method = strtolower( trim( $method ) );

		return in_array( $method, self::geo_detection_methods(), true ) ? $method : 'auto';
	}

	/**
	 * Sanitize an HTTP header name (letters, digits, dashes, underscores).
	 */
	private function sanitize_header_name( string $header ): string {
		$header = strtoupper( trim( $header ) );
		$header = preg_replace( '/[^A-Z0-9_-]/', '', $header ) ?? '';

		return substr( $header, 0, 100 );
	}

	/**
	 * @param mixed $values Values.
	 * @return array<int, string>
	 */
	private function sanitize_rel_array( mixed $values ): array {
		if ( is_string( $values ) ) {
			$values = array_filter( array_map( 'trim', explode( ',', $values ) ) );
		}

		if ( ! is_array( $values ) ) {
			return array();
		}

		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$clean   = array();

		foreach ( $values as $value ) {
			$token = sanitize_key( (string) $value );
			if ( in_array( $token, $allowed, true ) ) {
				$clean[] = $token;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
