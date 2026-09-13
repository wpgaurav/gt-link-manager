<?php
/**
 * A bounded, server-side collector loaded only after a matched redirect opts in.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Analytics_Collector {
	/** Return true only when one eligible event was written successfully. */
	public static function collect( array $link, int $status, ?array $geo ): bool {
		if ( ! GTLM_Settings::get_instance()->advanced_analytics_enabled() || 'GET' !== self::server_value( 'REQUEST_METHOD', 8 ) || (int) ( $link['id'] ?? 0 ) <= 0 ) {
			return false;
		}
		if ( ! (bool) apply_filters( 'gtlm_analytics_should_record', true, $link ) ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'analytics_exclude' ) ) ) {
			return false;
		}
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE' ) as $header ) {
			$value = self::server_value( $header, 128 );
			if ( false !== stripos( $value, 'prefetch' ) || false !== stripos( $value, 'prerender' ) || false !== stripos( $value, 'preview' ) ) {
				return false;
			}
		}
		$config = get_option( 'gtlm_analytics', array() );
		if ( ! is_array( $config ) || ! in_array( $config['schema'] ?? '', array( '1', '2' ), true ) || 'active' !== ( $config['state'] ?? '' ) || (int) ( $config['lease_until'] ?? 0 ) < time() || empty( $config['generation'] ) || in_array( (int) $link['id'], (array) ( $config['exclude_links'] ?? array() ), true ) ) {
			return false;
		}
		$agent = self::server_value( 'HTTP_USER_AGENT', 512 );
		if ( preg_match( '/bot|crawler|spider|slurp|headless|facebookexternalhit|preview|prerender/i', $agent ) ) {
			return false;
		}
		$device = 'unknown';
		if ( '' !== $agent ) {
			$device = preg_match( '/ipad|tablet|kindle|silk/i', $agent ) ? 'tablet' : ( preg_match( '/mobi|iphone|ipod|android/i', $agent ) ? 'mobile' : 'desktop' );
		}
		$browser = self::family(
			$agent,
			array(
				'edge'    => 'Edg',
				'opera'   => 'OPR',
				'samsung' => 'SamsungBrowser',
				'firefox' => 'Firefox|FxiOS',
				'chrome'  => 'Chrome|CriOS',
				'safari'  => 'Safari',
			)
		);
		$os      = self::family(
			$agent,
			array(
				'android'  => 'Android',
				'ios'      => 'iPhone|iPad|iPod',
				'windows'  => 'Windows',
				'macos'    => 'Macintosh|Mac OS',
				'linux'    => 'Linux',
				'chromeos' => 'CrOS',
			)
		);
		$country = '';
		$source  = (string) ( $config['country_source'] ?? 'none' );
		$key     = 'cloudflare' === $source ? 'HTTP_CF_IPCOUNTRY' : ( 'custom' === $source ? GTLM_Geo::server_key_for_header( (string) ( $config['country_header'] ?? '' ) ) : '' );
		if ( 'auto' === $source ) {
			$country = GTLM_Geo::country();
		} elseif ( '' !== $key ) {
			$country = GTLM_Geo::normalize_code( self::server_value( $key, 8 ) );
		}
		require_once __DIR__ . '/class-gtlm-analytics-referrer.php';
		$referrer = GTLM_Analytics_Referrer::parse( self::server_value( 'HTTP_REFERER', 2048 ) );
		$event    = array(
			'link_id'     => (int) $link['id'],
			'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			'generation'  => (string) $config['generation'],
			'source'      => $referrer['source'],
			'country'     => $country,
			'device'      => $device,
			'browser'     => $browser,
			'os'          => $os,
			'campaign'    => self::campaign( (array) ( $config['campaigns'] ?? array() ) ),
			'status'      => $status,
			'mode'        => in_array( $link['link_mode'] ?? '', array( 'standard', 'direct', 'regex' ), true ) ? $link['link_mode'] : 'standard',
			'geo'         => null === $geo ? 'off' : ( ! empty( $geo['matched'] ) ? 'matched' : 'fallback' ),
		);
		if ( '2' === $config['schema'] ) {
			$event['page'] = $referrer['page'];
		}
		return ( new GTLM_DB() )->append_analytics_event( $event );
	}

	private static function server_value( string $key, int $limit ): string {
		// Reject oversized values rather than cutting a token into a different valid token.
		$value = $_SERVER[ $key ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated and parsed into fixed fields; never stored raw.
		return is_string( $value ) && strlen( $value ) <= $limit ? $value : '';
	}

	private static function family( string $agent, array $families ): string {
		foreach ( $families as $name => $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $agent ) ) {
				return $name;
			}
		}
		return 'unknown';
	}

	private static function campaign( array $campaigns ): int {
		if ( ! $campaigns ) {
			return 0;
		}
		$values = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
			$value = $_GET[ $key ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compare only with opted-in allowlist; raw values never stored.
			if ( ! is_string( $value ) || strlen( $value ) > 64 ) {
				return 0;
			}
			$values[ $key ] = wp_unslash( $value );
		}
		foreach ( $campaigns as $campaign ) {
			if ( ! empty( $campaign['enabled'] ) && array_intersect_key( $campaign, $values ) === $values ) {
				return (int) $campaign['id'];
			}
		}
		return 0;
	}
}
