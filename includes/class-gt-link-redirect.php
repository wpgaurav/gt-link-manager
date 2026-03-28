<?php
/**
 * Redirect runtime.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Redirect {
	private GTLM_DB $db;

	private GTLM_Settings $settings;

	public static function init( GTLM_DB $db, GTLM_Settings $settings ): void {
		$instance = new self( $db, $settings );
		$instance->hooks();
	}

	private function __construct( GTLM_DB $db, GTLM_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	private function hooks(): void {
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 1 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'init', array( $this, 'maybe_redirect' ), 0 );
		add_action( 'gtlm_settings_saved', array( $this, 'on_settings_saved' ), 10, 1 );
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'gtlm_slug';
		return $vars;
	}

	public function register_rewrite_rules(): void {
		$prefix = preg_quote( $this->settings->prefix(), '/' );

		add_rewrite_tag( '%gtlm_slug%', '([^&]+)' );
		add_rewrite_rule( '^' . $prefix . '/([^/]+)/?$', 'index.php?gtlm_slug=$matches[1]', 'top' );
	}

	/**
	 * Early redirect resolver.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$link         = null;
		$match_slug   = '';
		$regex_result = null;

		// Step 1: Try standard prefix-based lookup (fastest path).
		$slug = $this->extract_slug_from_request();
		if ( '' !== $slug ) {
			$link = $this->db->get_link_by_slug( $slug );
			if ( is_array( $link ) ) {
				$match_slug = $slug;
			}
		}

		// Step 2 & 3: Try direct and regex matches if advanced redirects are enabled.
		if ( null === $link && ! empty( $this->settings->all()['enable_advanced_redirects'] ) ) {
			$path = $this->extract_path_from_request();

			if ( '' !== $path ) {
				// Step 2: Direct (prefix-free) exact match.
				$link = $this->db->get_direct_link_by_path( $path );
				if ( is_array( $link ) ) {
					$match_slug = $path;
				}

				// Step 3: Regex pattern match.
				if ( null === $link ) {
					$regex_result = $this->match_regex_rules( $path );
					if ( null !== $regex_result ) {
						$link       = $regex_result['link'];
						$match_slug = $path;
					}
				}
			}
		}

		if ( null === $link || empty( $link['url'] ) ) {
			return;
		}

		// Skip trashed or inactive links.
		if ( ! empty( $link['trashed_at'] ) || empty( $link['is_active'] ) ) {
			return;
		}

		$target_url = (string) $link['url'];

		// For regex links, apply capture group substitution.
		if ( 'regex' === ( $link['link_mode'] ?? 'standard' ) && isset( $regex_result ) ) {
			$target_url = $regex_result['target_url'];
		}

		$target_url = (string) apply_filters( 'gtlm_redirect_url', $target_url, $link, $match_slug );
		$status     = (int) apply_filters( 'gtlm_redirect_code', (int) $link['redirect_type'], $link, $match_slug );
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
		$rel_values = (array) apply_filters( 'gtlm_rel_attributes', $rel_values, $link, $match_slug );

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
		$headers = (array) apply_filters( 'gtlm_headers', $headers, $link, $match_slug );

		do_action( 'gtlm_before_redirect', $link, $target_url, $status, $headers );

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
			wp_cache_flush_group( GTLM_DB::CACHE_GROUP );
		}
	}

	private function extract_slug_from_request(): string {
		$slug = get_query_var( 'gtlm_slug', '' );
		if ( is_string( $slug ) && '' !== $slug ) {
			return sanitize_title( $slug );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return '';
		}

		$path      = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$prefix    = trim( $this->settings->prefix(), '/' );
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
	 * Extract the clean request path (without home path prefix).
	 * Used for direct and regex matching.
	 */
	private function extract_path_from_request(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return '';
		}

		$path      = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home_path ) {
			if ( $path === $home_path ) {
				return '';
			}
			if ( str_starts_with( $path, $home_path . '/' ) ) {
				$path = substr( $path, strlen( $home_path ) + 1 );
			}
		}

		return $path;
	}

	/**
	 * Try to match a request path against regex rules.
	 *
	 * @return array{link: array<string, mixed>, target_url: string}|null
	 */
	private function match_regex_rules( string $path ): ?array {
		$rules = $this->db->get_active_regex_rules();
		if ( empty( $rules ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			$pattern = (string) $rule['slug'];
			if ( '' === $pattern ) {
				continue;
			}

			// Use # as delimiter to avoid conflicts with / in patterns.
			$regex  = '#' . $pattern . '#';
			$result = @preg_match( $regex, $path, $matches );

			if ( 1 === $result ) {
				$target_url = (string) $rule['url'];
				$replacement = (string) ( $rule['regex_replacement'] ?? '' );

				// If regex_replacement is set, use it as the target with capture group substitution.
				if ( '' !== $replacement ) {
					$target_url = preg_replace( $regex, $replacement, $path );
					if ( null === $target_url ) {
						$target_url = (string) $rule['url'];
					}
				} else {
					// Apply simple $1, $2 substitution on the destination URL.
					foreach ( $matches as $i => $match ) {
						if ( $i > 0 ) {
							$target_url = str_replace( '$' . $i, $match, $target_url );
						}
					}
				}

				return array(
					'link'       => $rule,
					'target_url' => $target_url,
				);
			}
		}

		return null;
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
