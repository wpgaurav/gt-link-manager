<?php
/**
 * Redirect runtime.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Redirect {
	private GT_Link_DB $db;

	private GT_Link_Settings $settings;

	public static function init( GT_Link_DB $db, GT_Link_Settings $settings ): void {
		$instance = new self( $db, $settings );
		$instance->hooks();
	}

	private function __construct( GT_Link_DB $db, GT_Link_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	private function hooks(): void {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 1 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'init', array( $this, 'maybe_redirect' ), 0 );
		add_action( 'gt_link_manager_settings_saved', array( $this, 'on_settings_saved' ), 10, 1 );
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'gt_link_slug';
		return $vars;
	}

	public function register_rewrite_rules(): void {
		$prefix = preg_quote( $this->settings->prefix(), '/' );

		add_rewrite_tag( '%gt_link_slug%', '([^&]+)' );
		add_rewrite_rule( '^' . $prefix . '/([^/]+)/?$', 'index.php?gt_link_slug=$matches[1]', 'top' );
	}

	/**
	 * Early redirect resolver.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$slug = $this->extract_slug_from_request();
		if ( '' === $slug ) {
			return;
		}

		$link = $this->db->get_link_by_slug( $slug );
		if ( null === $link || empty( $link['url'] ) ) {
			return;
		}

		// Skip trashed or inactive links.
		if ( ! empty( $link['trashed_at'] ) || empty( $link['is_active'] ) ) {
			return;
		}

		$target_url = (string) apply_filters( 'gt_link_manager_redirect_url', $link['url'], $link, $slug );
		$status     = (int) apply_filters( 'gt_link_manager_redirect_code', (int) $link['redirect_type'], $link, $slug );
		$status     = in_array( $status, array( 301, 302, 307 ), true ) ? $status : 301;

		$target_url = trim( $target_url );
		if ( '' === $target_url ) {
			return;
		}

		// Support site-relative targets while keeping external URL redirects functional.
		if ( str_starts_with( $target_url, '/' ) ) {
			$target_url = home_url( $target_url );
		}

		$target_url = wp_sanitize_redirect( $target_url );
		if ( '' === $target_url || ! wp_http_validate_url( $target_url ) ) {
			return;
		}

		$rel_values = $this->parse_rel( (string) ( $link['rel'] ?? '' ) );
		$rel_values = (array) apply_filters( 'gt_link_manager_rel_attributes', $rel_values, $link, $slug );

		$headers = array(
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
		);

		if ( ! empty( $link['noindex'] ) ) {
			$headers['X-Robots-Tag'] = 'noindex, nofollow';
		}

		if ( ! empty( $rel_values ) ) {
			$headers['Link'] = '<' . esc_url_raw( $target_url ) . '>; rel="' . implode( ' ', array_map( 'sanitize_key', $rel_values ) ) . '"';
		}

		/**
		 * Filter redirect headers.
		 *
		 * @param array<string, string> $headers Headers.
		 */
		$headers = (array) apply_filters( 'gt_link_manager_headers', $headers, $link, $slug );

		do_action( 'gt_link_manager_before_redirect', $link, $target_url, $status, $headers );

		foreach ( $headers as $name => $value ) {
			if ( '' !== $name && '' !== $value ) {
				$safe_name  = str_replace( array( "\r", "\n", ':' ), '', (string) $name );
				$safe_value = str_replace( array( "\r", "\n" ), '', (string) $value );
				header( $safe_name . ': ' . $safe_value, true );
			}
		}

		nocache_headers();
		header( 'X-Redirect-By: GT Link Manager', true );
		header( 'Location: ' . $target_url, true, $status );
		exit;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function on_settings_saved( array $settings ): void {
		flush_rewrite_rules();

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( GT_Link_DB::CACHE_GROUP );
		}
	}

	private function extract_slug_from_request(): string {
		$slug = get_query_var( 'gt_link_slug', '' );
		if ( is_string( $slug ) && '' !== $slug ) {
			return sanitize_title( $slug );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return '';
		}

		$path   = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$prefix = trim( $this->settings->prefix(), '/' );
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		// Support WordPress installs in subdirectories like /blog.
		if ( '' !== $home_path ) {
			if ( $path === $home_path ) {
				$path = '';
			} elseif ( str_starts_with( $path, $home_path . '/' ) ) {
				$path = substr( $path, strlen( $home_path ) + 1 );
			}
		}

		if ( '' === $path || '' === $prefix ) {
			return '';
		}

		if ( ! str_starts_with( $path, $prefix . '/' ) ) {
			return '';
		}

		$slug = substr( $path, strlen( $prefix ) + 1 );
		if ( false === $slug || '' === $slug ) {
			return '';
		}

		$parts = explode( '/', $slug );
		$slug  = (string) $parts[0];

		return sanitize_title( $slug );
	}

	/**
	 * @return array<int, string>
	 */
	private function parse_rel( string $rel ): array {
		$parts = array_filter( array_map( 'trim', explode( ',', strtolower( $rel ) ) ) );
		$parts = array_map( 'sanitize_key', $parts );

		return array_values( array_unique( $parts ) );
	}
}
