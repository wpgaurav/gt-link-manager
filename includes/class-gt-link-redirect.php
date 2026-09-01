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

	/**
	 * Set when the request matched the link prefix but no link could be served.
	 * Consumed on 'wp' to turn the request into a real 404 instead of letting
	 * WordPress resolve the leftover gtlm_slug query to the front page.
	 */
	private bool $is_missing_link = false;

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

		// A non-empty slug means the request path matched the configured
		// prefix, so this URL is ours to answer for -- including answering 404.
		$prefix_matched = ( '' !== $slug );

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
			$this->mark_missing_link( $prefix_matched );
			return;
		}

		// Skip trashed or inactive links. A trashed or deactivated link must
		// read as gone, not silently fall through to the front page.
		if ( ! empty( $link['trashed_at'] ) || empty( $link['is_active'] ) ) {
			$this->mark_missing_link( $prefix_matched );
			return;
		}

		$target_url = (string) $link['url'];
		$status     = (int) $link['redirect_type'];
		$geo        = null;

		// For regex links, apply capture group substitution.
		if ( 'regex' === ( $link['link_mode'] ?? 'standard' ) && isset( $regex_result ) ) {
			$target_url = $regex_result['target_url'];
		}

		// Geolocation targeting. Detection is only reached when the matched
		// link opts in, so links with geo_mode 'off' cost a single array read.
		if ( 'off' !== ( $link['geo_mode'] ?? 'off' ) && GTLM_Geo::is_enabled() ) {
			$geo = GTLM_Geo::resolve( $link );

			if ( null === $geo ) {
				$this->send_geo_blocked( $link );
				return;
			}

			$target_url = $geo['url'];
			$status     = $geo['redirect_type'];
		}

		$target_url = (string) apply_filters( 'gtlm_redirect_url', $target_url, $link, $match_slug );
		$status     = (int) apply_filters( 'gtlm_redirect_code', $status, $link, $match_slug );
		$status     = in_array( $status, array( 301, 302, 307 ), true ) ? $status : 301;

		$target_url = trim( $target_url );
		if ( '' === $target_url ) {
			$this->mark_missing_link( $prefix_matched );
			return;
		}

		// Support site-relative targets while keeping external URL redirects functional.
		if ( str_starts_with( $target_url, '/' ) ) {
			$target_url = home_url( $target_url );
		}

		$target_url = wp_sanitize_redirect( $target_url );
		if ( '' === $target_url || ! wp_http_validate_url( $target_url ) ) {
			$this->mark_missing_link( $prefix_matched );
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

		if ( null !== $geo && GTLM_Geo::debug_enabled() ) {
			$headers['X-GTLM-Country'] = sprintf(
				'%s; source=%s; rule=%s',
				'' !== $geo['country'] ? $geo['country'] : 'unknown',
				'' !== GTLM_Geo::source() ? GTLM_Geo::source() : 'none',
				$geo['matched'] ? 'matched' : 'fallback'
			);
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

		do_action( 'gtlm_before_redirect', $link, $target_url, $status, $headers, $geo );

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

		$this->record_click( $link );

		exit;
	}

	/**
	 * Count the click, after the visitor already has their redirect.
	 *
	 * The redirect is the product; a counter must not slow it down. The
	 * response is flushed and the connection closed first where the SAPI
	 * supports it (PHP-FPM), so the write happens on time the visitor is no
	 * longer waiting for. Hosts without that support fall back to a plain
	 * synchronous write, which is still only reached after the Location
	 * header has been sent.
	 *
	 * Only a per-link total is stored: no IP address, no user agent, no
	 * referrer, nothing that identifies a visitor.
	 *
	 * @param array<string, mixed> $link Link row.
	 */
	private function record_click( array $link ): void {
		if ( ! $this->settings->click_tracking_enabled() ) {
			return;
		}

		$link_id = (int) ( $link['id'] ?? 0 );
		if ( $link_id <= 0 ) {
			return;
		}

		/**
		 * Filter whether this particular click should be counted.
		 *
		 * Return false to skip it -- for example to exclude logged-in
		 * editors, or to plug in bot filtering.
		 *
		 * @param bool                 $count Whether to count the click.
		 * @param array<string, mixed> $link  Link row.
		 */
		if ( ! (bool) apply_filters( 'gtlm_count_click', true, $link ) ) {
			return;
		}

		// Close the connection first so the write is off the visitor's clock.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$this->db->increment_clicks( $link_id );

		/**
		 * Fires after a click has been counted.
		 *
		 * @param int                  $link_id Link ID.
		 * @param array<string, mixed> $link    Link row.
		 */
		do_action( 'gtlm_click_recorded', $link_id, $link );
	}

	/**
	 * Flag a request that matched the link prefix but resolved to nothing.
	 *
	 * The rewrite rule maps the whole prefix namespace to a gtlm_slug query
	 * var, so an unmatched slug would otherwise resolve to the front page and
	 * return 200. That turns every dead, trashed, or deactivated short link
	 * into a soft 404 that search engines index as duplicate home-page content.
	 *
	 * Only prefix-based requests are flagged. Direct and regex mode inspect
	 * arbitrary paths that may legitimately belong to a real page, so those
	 * must still fall through to WordPress untouched.
	 *
	 * @param bool $prefix_matched Whether the request path matched the prefix.
	 */
	private function mark_missing_link( bool $prefix_matched ): void {
		if ( ! $prefix_matched ) {
			return;
		}

		/**
		 * Filter whether an unresolved prefixed link should return 404.
		 *
		 * Return false to restore the previous behaviour of falling through
		 * to WordPress.
		 *
		 * @param bool $send_404 Whether to send a 404.
		 */
		if ( ! (bool) apply_filters( 'gtlm_404_on_missing_link', true ) ) {
			return;
		}

		$this->is_missing_link = true;

		// Stop core from guessing a permalink and 301-ing the dead link to an
		// unrelated post.
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );

		add_action( 'wp', array( $this, 'force_missing_link_404' ), 1 );
	}

	/**
	 * Turn a flagged request into a real 404 once the query exists.
	 *
	 * Deferred to 'wp' rather than terminating on 'init' so the active theme
	 * renders its own 404 template instead of a blank body.
	 */
	public function force_missing_link_404(): void {
		global $wp_query;

		if ( ! $this->is_missing_link || ! $wp_query instanceof WP_Query ) {
			return;
		}

		/**
		 * Fires when a prefixed link could not be resolved.
		 *
		 * @param string $slug Requested slug.
		 */
		do_action( 'gtlm_link_not_found', $this->extract_slug_from_request() );

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * No geo rule matched and the link's fallback is "block".
	 *
	 * This has to terminate explicitly rather than return: for direct-mode
	 * links the request path may resolve to a real page, and returning would
	 * render it.
	 *
	 * @param array<string, mixed> $link Link row.
	 */
	private function send_geo_blocked( array $link ): void {
		/**
		 * Fires when a request is blocked because no geo rule matched.
		 *
		 * @param array<string, mixed> $link    Link row.
		 * @param string               $country Detected country, '' if unknown.
		 */
		do_action( 'gtlm_geo_blocked', $link, GTLM_Geo::country() );

		nocache_headers();

		if ( GTLM_Geo::debug_enabled() ) {
			header( 'X-GTLM-Country: ' . ( '' !== GTLM_Geo::country() ? GTLM_Geo::country() : 'unknown' ) . '; rule=blocked', true );
		}

		status_header( 404 );
		header( 'X-Redirect-By: GT Link Manager', true );
		exit;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function on_settings_saved( array $settings ): void {
		GTLM_Geo::reset();
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
			$result = preg_match( $regex, $path, $matches );
			if ( false === $result ) {
				continue;
			}

			if ( 1 === $result ) {
				$target_url  = (string) $rule['url'];
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
		$parts = preg_split( '/[\s,]+/', strtolower( $rel ), -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_map( 'sanitize_key', $parts );

		return array_values( array_unique( $parts ) );
	}
}
