<?php
/**
 * Geolocation detection and rule resolution.
 *
 * Detection is header-based only. Every source below is a value that a CDN,
 * reverse proxy, or web server module already placed in $_SERVER before PHP
 * ran, so a lookup costs an array read. No database files, no remote API
 * calls, no third-party libraries, nothing to keep updated.
 *
 * Nothing here runs unless a matched link actually has geo targeting enabled.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Geo {
	/**
	 * Values CDNs send to mean "country unknown".
	 *
	 * XX / ZZ are unknown, T1 is Tor, A1 / A2 / O1 are anonymous proxy,
	 * satellite, and other in legacy MaxMind-derived data.
	 */
	private const RESERVED_CODES = array( 'XX', 'ZZ', 'T1', 'A1', 'A2', 'O1' );

	/**
	 * Detected country, memoized for the request. Empty string means unknown.
	 */
	private static ?string $country = null;

	private static string $source = '';

	/**
	 * @var array<string, string>|null
	 */
	private static ?array $countries = null;

	/**
	 * Settings-derived config, memoized for the request.
	 *
	 * GTLM_Settings::all() re-reads the option, runs its sanitizers, and fires
	 * a filter on every call. Detection would otherwise pay that repeatedly on
	 * the redirect path, so the few values geo needs are resolved once.
	 *
	 * @var array{enabled: bool, method: string, custom_key: string, debug: bool}|null
	 */
	private static ?array $config = null;

	/**
	 * @return array{enabled: bool, method: string, custom_key: string, debug: bool}
	 */
	private static function config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$settings = GTLM_Settings::get_instance()->all();

		self::$config = array(
			'enabled'    => ! empty( $settings['enable_geo_targeting'] ),
			'method'     => (string) ( $settings['geo_detection_method'] ?? 'auto' ),
			'custom_key' => self::server_key_for_header( (string) ( $settings['geo_custom_header'] ?? '' ) ),
			'debug'      => ! empty( $settings['geo_debug_header'] ),
		);

		return self::$config;
	}

	/**
	 * Whether geolocation targeting is enabled site-wide.
	 */
	public static function is_enabled(): bool {
		return self::config()['enabled'];
	}

	/**
	 * Whether the X-GTLM-Country debug header should be sent.
	 */
	public static function debug_enabled(): bool {
		return self::config()['debug'];
	}

	/**
	 * Known detection sources in probe order: $_SERVER key => source label.
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		$sources = array(
			// CDNs and platforms that inject a country header.
			'HTTP_CF_IPCOUNTRY'              => 'cloudflare',
			'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'cloudfront',
			'HTTP_X_VERCEL_IP_COUNTRY'       => 'vercel',
			'HTTP_X_APPENGINE_COUNTRY'       => 'appengine',
			'HTTP_X_GEO_COUNTRY'             => 'proxy',
			// Web server modules that expose a country as a request variable.
			'GEOIP2_DATA_COUNTRY_CODE'       => 'nginx-geoip2',
			'MM_COUNTRY_CODE'                => 'mod-maxminddb',
			'GEOIP_COUNTRY_CODE'             => 'mod-geoip',
		);

		/**
		 * Filter the geolocation detection sources.
		 *
		 * Keys are $_SERVER keys, values are short source labels shown in
		 * diagnostics. Earlier entries win.
		 *
		 * @param array<string, string> $sources Sources.
		 */
		return (array) apply_filters( 'gtlm_geo_sources', $sources );
	}

	/**
	 * Country groups. Rule country lists may contain any key from here.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function groups(): array {
		$groups = array(
			// European Union member states.
			'EU' => array(
				'AT',
				'BE',
				'BG',
				'CY',
				'CZ',
				'DE',
				'DK',
				'EE',
				'ES',
				'FI',
				'FR',
				'GR',
				'HR',
				'HU',
				'IE',
				'IT',
				'LT',
				'LU',
				'LV',
				'MT',
				'NL',
				'PL',
				'PT',
				'RO',
				'SE',
				'SI',
				'SK',
			),
		);

		/**
		 * Filter country groups usable in geo rules.
		 *
		 * @param array<string, array<int, string>> $groups Group code => country codes.
		 */
		return (array) apply_filters( 'gtlm_geo_country_groups', $groups );
	}

	/**
	 * Detected two-letter country code, or '' when unknown.
	 */
	public static function country(): string {
		if ( null !== self::$country ) {
			return self::$country;
		}

		self::$country = '';
		self::$source  = '';

		foreach ( self::active_sources() as $key => $label ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';
			if ( '' === $raw ) {
				continue;
			}

			$code = self::normalize_code( $raw );
			if ( '' === $code ) {
				continue;
			}

			self::$country = $code;
			self::$source  = $label;
			break;
		}

		/**
		 * Filter the detected country code.
		 *
		 * Return a two-letter uppercase ISO 3166-1 alpha-2 code, or '' for
		 * unknown. This is the extension point for any detection method not
		 * covered by a request header.
		 *
		 * @param string $country Detected country code, '' if unknown.
		 * @param string $source  Source label that produced it.
		 */
		$filtered = (string) apply_filters( 'gtlm_geo_country', self::$country, self::$source );

		if ( $filtered !== self::$country ) {
			$normalized = self::normalize_code( $filtered );
			if ( $normalized !== self::$country ) {
				self::$country = $normalized;
				self::$source  = '' === $normalized ? '' : 'filter';
			}
		}

		return self::$country;
	}

	/**
	 * Label of the source that produced the detected country.
	 */
	public static function source(): string {
		if ( null === self::$country ) {
			self::country();
		}

		return self::$source;
	}

	/**
	 * Where the loopback self-test token lives.
	 *
	 * An option rather than a transient on purpose: with an external object
	 * cache, transients are cache-only, so a flaky Redis makes them vanish
	 * between the request that issues the token and the loopback that spends
	 * it. Options fall back to the database on a cache miss.
	 */
	private const PROBE_OPTION = 'gtlm_geo_probe_token';

	/**
	 * Mint a single-use token for the loopback probe.
	 *
	 * Only the hash is stored, so the database never holds a usable token.
	 */
	public static function issue_probe_token(): string {
		$token = wp_generate_password( 24, false );

		update_option(
			self::PROBE_OPTION,
			array(
				'hash'    => wp_hash( $token ),
				'expires' => time() + 60,
			),
			false
		);

		return $token;
	}

	/**
	 * Validate and consume a probe token. Always consumes, valid or not.
	 */
	public static function consume_probe_token( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$stored = get_option( self::PROBE_OPTION );
		delete_option( self::PROBE_OPTION );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return false;
		}

		if ( (int) ( $stored['expires'] ?? 0 ) < time() ) {
			return false;
		}

		return hash_equals( (string) $stored['hash'], wp_hash( $token ) );
	}

	/**
	 * Request variables that indicate a proxy or CDN is in front of PHP.
	 *
	 * Used to tell "a CDN is there but is not sending a country" apart from
	 * "nothing is in front of this site, so no country is expected" — the two
	 * look identical otherwise, and only the first is a misconfiguration.
	 *
	 * @return array<int, string> Labels of proxies detected on this request.
	 */
	public static function proxy_signals(): array {
		$signals = array(
			'HTTP_CF_RAY'                     => 'Cloudflare',
			'HTTP_CF_CONNECTING_IP'           => 'Cloudflare',
			'HTTP_CLOUDFRONT_FORWARDED_PROTO' => 'CloudFront',
			'HTTP_X_VERCEL_ID'                => 'Vercel',
			'HTTP_X_FORWARDED_FOR'            => 'a reverse proxy',
			'HTTP_X_REAL_IP'                  => 'a reverse proxy',
		);

		$found = array();
		foreach ( $signals as $key => $label ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( ! empty( $_SERVER[ $key ] ) && ! in_array( $label, $found, true ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}

	/**
	 * Reset memoized detection. Used by tests and the settings screen.
	 */
	public static function reset(): void {
		self::$country = null;
		self::$source  = '';
		self::$config  = null;
	}

	/**
	 * Sources to probe, honouring the configured detection method.
	 *
	 * @return array<string, string>
	 */
	private static function active_sources(): array {
		$config = self::config();
		$all    = self::sources();

		if ( 'cloudflare' === $config['method'] ) {
			return array_intersect_key( $all, array( 'HTTP_CF_IPCOUNTRY' => '' ) );
		}

		if ( 'custom' === $config['method'] ) {
			return '' === $config['custom_key'] ? array() : array( $config['custom_key'] => 'custom' );
		}

		if ( '' !== $config['custom_key'] && ! isset( $all[ $config['custom_key'] ] ) ) {
			// A configured custom header wins over the built-in probes.
			$all = array( $config['custom_key'] => 'custom' ) + $all;
		}

		return $all;
	}

	/**
	 * Convert an HTTP header name to its $_SERVER key.
	 */
	public static function server_key_for_header( string $header ): string {
		$header = strtoupper( trim( $header ) );
		$header = preg_replace( '/[^A-Z0-9_-]/', '', $header ) ?? '';

		if ( '' === $header ) {
			return '';
		}

		$header = str_replace( '-', '_', $header );

		// Web server variables are passed through as-is; real headers get the prefix.
		if ( str_starts_with( $header, 'HTTP_' ) || isset( self::sources()[ $header ] ) ) {
			return $header;
		}

		return 'HTTP_' . $header;
	}

	/**
	 * Normalize a raw country value into an ISO 3166-1 alpha-2 code.
	 *
	 * Returns '' for empty, malformed, and reserved "unknown" values.
	 */
	public static function normalize_code( string $value ): string {
		$value = strtoupper( trim( $value ) );

		if ( 2 !== strlen( $value ) || 1 !== preg_match( '/^[A-Z]{2}$/', $value ) ) {
			return '';
		}

		if ( in_array( $value, self::RESERVED_CODES, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Whether a country code or group token is usable in a rule.
	 */
	public static function is_valid_target( string $code ): bool {
		$code = strtoupper( trim( $code ) );

		if ( isset( self::groups()[ $code ] ) ) {
			return true;
		}

		return '' !== self::normalize_code( $code ) && isset( self::countries()[ $code ] );
	}

	/**
	 * Whether a detected country satisfies a rule's country list.
	 *
	 * @param array<int, string> $targets Country codes and/or group tokens.
	 */
	public static function matches( array $targets, string $country ): bool {
		if ( '' === $country || empty( $targets ) ) {
			return false;
		}

		$groups = self::groups();

		foreach ( $targets as $target ) {
			$target = strtoupper( trim( (string) $target ) );

			if ( $target === $country ) {
				return true;
			}

			if ( isset( $groups[ $target ] ) && in_array( $country, $groups[ $target ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a link's geo rules against a detected country.
	 *
	 * @param array<string, mixed> $link Link row.
	 * @return array{url: string, redirect_type: int, country: string, matched: bool}|null
	 *         Null means the request should be blocked.
	 */
	public static function resolve( array $link ): ?array {
		$country = self::country();
		$decoded = self::decode_rules( (string) ( $link['geo_rules'] ?? '' ) );

		$default = array(
			'url'           => (string) ( $link['url'] ?? '' ),
			'redirect_type' => (int) ( $link['redirect_type'] ?? 302 ),
			'country'       => $country,
			'matched'       => false,
		);

		foreach ( $decoded['rules'] as $rule ) {
			if ( ! self::matches( $rule['countries'], $country ) ) {
				continue;
			}

			$resolved = array(
				'url'           => $rule['url'],
				'redirect_type' => $rule['redirect_type'] > 0 ? $rule['redirect_type'] : $default['redirect_type'],
				'country'       => $country,
				'matched'       => true,
			);

			/**
			 * Filter the geo rule resolution for a link.
			 *
			 * @param array{url: string, redirect_type: int, country: string, matched: bool}|null $resolved Resolution.
			 * @param array<string, mixed>                                                       $rule     Matched rule.
			 * @param array<string, mixed>                                                       $link     Link row.
			 * @param string                                                                     $country  Detected country.
			 */
			return apply_filters( 'gtlm_geo_matched_rule', $resolved, $rule, $link, $country );
		}

		// No rule matched.
		if ( 'block' === $decoded['fallback'] ) {
			/** This filter is documented above. */
			return apply_filters( 'gtlm_geo_matched_rule', null, array(), $link, $country );
		}

		/** This filter is documented above. */
		return apply_filters( 'gtlm_geo_matched_rule', $default, array(), $link, $country );
	}

	/**
	 * Decode a stored geo_rules JSON string into a validated structure.
	 *
	 * Always returns a usable shape, even for malformed input.
	 *
	 * @return array{rules: array<int, array{countries: array<int, string>, url: string, redirect_type: int}>, fallback: string}
	 */
	public static function decode_rules( string $json ): array {
		$empty = array(
			'rules'    => array(),
			'fallback' => 'default',
		);

		$json = trim( $json );
		if ( '' === $json ) {
			return $empty;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $empty;
		}

		return self::normalize_rules( $decoded );
	}

	/**
	 * Validate and normalize a rules structure from any source.
	 *
	 * Accepts the canonical object form, a bare list of rules, or a JSON
	 * string, so REST, admin, and CSV all share one validator.
	 *
	 * @param mixed $raw Raw rules.
	 * @return array{rules: array<int, array{countries: array<int, string>, url: string, redirect_type: int}>, fallback: string}
	 */
	public static function normalize_rules( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( trim( $raw ), true );
		}

		if ( ! is_array( $raw ) ) {
			return array(
				'rules'    => array(),
				'fallback' => 'default',
			);
		}

		// Accept a bare list of rules as well as the wrapped form.
		$rules    = isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? $raw['rules'] : $raw;
		$fallback = isset( $raw['fallback'] ) && 'block' === $raw['fallback'] ? 'block' : 'default';

		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$countries = $rule['countries'] ?? ( $rule['country'] ?? array() );
			if ( is_string( $countries ) ) {
				$countries = explode( ',', $countries );
			}
			if ( ! is_array( $countries ) ) {
				continue;
			}

			$targets = array();
			foreach ( $countries as $code ) {
				$code = strtoupper( trim( (string) $code ) );
				if ( '' !== $code && self::is_valid_target( $code ) ) {
					$targets[] = $code;
				}
			}

			$targets = array_values( array_unique( $targets ) );
			if ( empty( $targets ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) ( $rule['url'] ?? '' ) ) );
			if ( '' === $url ) {
				continue;
			}

			$type = (int) ( $rule['redirect_type'] ?? 0 );
			if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
				$type = 0;
			}

			$clean[] = array(
				'countries'     => $targets,
				'url'           => $url,
				'redirect_type' => $type,
			);
		}

		return array(
			'rules'    => $clean,
			'fallback' => $fallback,
		);
	}

	/**
	 * Encode a rules structure for storage. Returns '' when there is nothing to store.
	 *
	 * @param mixed $raw Raw rules.
	 */
	public static function encode_rules( mixed $raw ): string {
		$normalized = self::normalize_rules( $raw );

		if ( empty( $normalized['rules'] ) && 'default' === $normalized['fallback'] ) {
			return '';
		}

		$payload = array(
			'version'  => 1,
			'rules'    => $normalized['rules'],
			'fallback' => $normalized['fallback'],
		);

		$json = wp_json_encode( $payload );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Number of rules stored on a link, without fully decoding when empty.
	 *
	 * @param array<string, mixed> $link Link row.
	 */
	public static function rule_count( array $link ): int {
		$json = (string) ( $link['geo_rules'] ?? '' );
		if ( '' === $json ) {
			return 0;
		}

		return count( self::decode_rules( $json )['rules'] );
	}

	/**
	 * ISO 3166-1 alpha-2 country list, loaded on demand.
	 *
	 * Only the admin screens and rule validation need this, so the data file
	 * is never loaded on a redirect.
	 *
	 * @return array<string, string> Code => name.
	 */
	public static function countries(): array {
		if ( null !== self::$countries ) {
			return self::$countries;
		}

		$file = GTLM_PATH . 'includes/data/countries.php';
		$list = is_readable( $file ) ? require $file : array();

		self::$countries = is_array( $list ) ? $list : array();

		return self::$countries;
	}

	/**
	 * Human-readable label for a country or group token.
	 */
	public static function label( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( 'EU' === $code ) {
			return __( 'European Union', 'gt-link-manager' );
		}

		if ( isset( self::groups()[ $code ] ) ) {
			return $code;
		}

		return self::countries()[ $code ] ?? $code;
	}
}
