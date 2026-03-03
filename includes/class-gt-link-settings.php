<?php
/**
 * Settings handler.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Settings {
	private const OPTION_KEY = 'gt_link_manager_settings';

	private static ?GT_Link_Settings $instance = null;

	/**
	 * Singleton instance.
	 */
	public static function get_instance(): GT_Link_Settings {
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
			'base_prefix'           => 'go',
			'default_redirect_type' => 301,
			'default_rel'           => array(),
			'default_noindex'       => 0,
		);
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

		$settings['base_prefix'] = $this->sanitize_prefix( (string) $settings['base_prefix'] );
		$settings['default_redirect_type'] = $this->sanitize_redirect_type( (int) $settings['default_redirect_type'] );
		$settings['default_noindex'] = (int) ! empty( $settings['default_noindex'] );
		$settings['default_rel'] = $this->sanitize_rel_array( $settings['default_rel'] );

		/**
		 * Filter effective plugin settings.
		 *
		 * @param array<string, mixed> $settings Settings.
		 */
		return (array) apply_filters( 'gt_link_manager_settings', $settings );
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
		$prefix = (string) apply_filters( 'gt_link_manager_prefix', $prefix );

		return $this->sanitize_prefix( $prefix );
	}

	/**
	 * Update settings in one write.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 */
	public function update( array $settings ): bool {
		$next = array(
			'base_prefix'           => $this->sanitize_prefix( (string) ( $settings['base_prefix'] ?? 'go' ) ),
			'default_redirect_type' => $this->sanitize_redirect_type( (int) ( $settings['default_redirect_type'] ?? 301 ) ),
			'default_noindex'       => (int) ! empty( $settings['default_noindex'] ),
			'default_rel'           => $this->sanitize_rel_array( $settings['default_rel'] ?? array() ),
		);

		$updated = update_option( self::OPTION_KEY, $next, false );

		if ( $updated ) {
			do_action( 'gt_link_manager_settings_saved', $next );
		}

		return (bool) $updated;
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
