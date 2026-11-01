<?php
/**
 * Request builders for analytics services with a server-side event API.
 *
 * Each builder is a pure function of its config and one click event, so what
 * goes over the wire can be tested without a network.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Integration_Services {
	private const EVENT       = 'link_click';
	private const EVENT_LABEL = 'Link Click';

	/**
	 * Build the HTTP request for one service.
	 *
	 * @param string               $tool   Tool key.
	 * @param array<string, mixed> $config Saved tool config.
	 * @param array<string, mixed> $event  Click event from GTLM_Integrations::build_event().
	 * @return array{url: string, args: array<string, mixed>}|null
	 */
	public static function request( string $tool, array $config, array $event ): ?array {
		switch ( $tool ) {
			case 'ga4':
				return self::ga4( $config, $event );
			case 'plausible':
				return self::plausible( $config, $event );
			case 'matomo':
				return self::matomo( $config, $event );
			case 'umami':
				return self::umami( $config, $event );
			case 'simple_analytics':
				return self::simple_analytics( $config, $event );
			case 'posthog':
				return self::posthog( $config, $event );
			case 'mixpanel':
				return self::mixpanel( $config, $event );
			case 'amplitude':
				return self::amplitude( $config, $event );
			case 'rybbit':
				return self::rybbit( $config, $event );
			case 'clicky':
				return self::clicky( $config, $event );
		}

		return null;
	}

	/**
	 * Google's validation server checks a payload without recording it, so the
	 * test button can catch a malformed event before any report is affected.
	 */
	public static function ga4_validation_url( string $url ): string {
		return str_replace( '/mp/collect', '/debug/mp/collect', $url );
	}

	/**
	 * Properties shared by every service that takes a free-form map.
	 *
	 * @param array<string, mixed> $event Click event.
	 * @return array<string, string|int>
	 */
	private static function properties( array $event ): array {
		return array(
			'link_id'       => (int) $event['link_id'],
			'link_name'     => (string) $event['link_name'],
			'link_slug'     => (string) $event['link_slug'],
			'link_mode'     => (string) $event['link_mode'],
			'link_category' => (string) $event['link_category'],
			'target_url'    => (string) $event['target_url'],
			'target_domain' => (string) $event['target_domain'],
			'redirect_type' => (string) $event['status'],
		);
	}

	/**
	 * @param array<string, mixed> $headers Extra headers.
	 * @param array<string, mixed> $body    JSON body.
	 * @return array<string, mixed>
	 */
	private static function json_args( array $headers, array $body ): array {
		return array(
			'method'  => 'POST',
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * @see https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function ga4( array $config, array $event ): array {
		$host = 'eu' === ( $config['region'] ?? '' ) ? 'region1.google-analytics.com' : 'www.google-analytics.com';
		$url  = add_query_arg(
			array(
				'measurement_id' => rawurlencode( (string) $config['measurement_id'] ),
				'api_secret'     => rawurlencode( (string) $config['api_secret'] ),
			),
			'https://' . $host . '/mp/collect'
		);

		$cookies = self::ga4_cookie_ids( (string) $config['measurement_id'], (array) ( $event['cookies'] ?? array() ) );

		// link_url, link_domain and outbound are the names GA4's own click event
		// uses, so they fill the built-in Link URL and Link domain dimensions.
		// GA4 rejects a parameter value over 100 characters.
		$params = array(
			'link_name'            => self::clip( (string) $event['link_name'], 100 ),
			'link_slug'            => self::clip( (string) $event['link_slug'], 100 ),
			'link_url'             => self::clip( (string) $event['target_url'], 100 ),
			'link_domain'          => self::clip( (string) $event['target_domain'], 100 ),
			'link_category'        => self::clip( (string) $event['link_category'], 100 ),
			'redirect_type'        => (int) $event['status'],
			'outbound'             => true,
			'page_location'        => self::clip( (string) $event['short_url'], 100 ),
			'session_id'           => '' !== $cookies['session_id'] ? $cookies['session_id'] : (string) $event['time'],
			'engagement_time_msec' => 1,
		);
		if ( '' !== (string) $event['referrer'] ) {
			$params['page_referrer'] = self::clip( (string) $event['referrer'], 100 );
		}

		$body = array(
			'client_id' => '' !== $cookies['client_id'] ? $cookies['client_id'] : (string) $event['numeric_visitor_id'],
			'events'    => array(
				array(
					'name'   => self::EVENT,
					'params' => $params,
				),
			),
		);

		if ( '' !== (string) $event['user_agent'] ) {
			$body['user_agent'] = (string) $event['user_agent'];
		}
		if ( '' !== (string) $event['ip'] ) {
			$body['ip_override'] = (string) $event['ip'];
		}
		if ( '' !== (string) $event['country'] ) {
			$body['user_location'] = array( 'country_id' => (string) $event['country'] );
		}

		return array(
			'url'  => $url,
			'args' => self::json_args( array(), $body ),
		);
	}

	/**
	 * Read the visitor's client and session IDs from the cookies gtag.js set.
	 *
	 * Handles both _ga_<stream> formats: GS1.1.<session>.<n>... and
	 * GS2.1.s<session>$o<n>...
	 *
	 * @param string               $measurement_id G-XXXX.
	 * @param array<string, mixed> $cookies        Request cookies.
	 * @return array{client_id: string, session_id: string}
	 */
	public static function ga4_cookie_ids( string $measurement_id, array $cookies ): array {
		$ids = array(
			'client_id'  => '',
			'session_id' => '',
		);

		$ga = $cookies['_ga'] ?? '';
		if ( is_string( $ga ) && preg_match( '/^GA\d+\.\d+\.(\d{1,20}\.\d{1,20})$/', $ga, $match ) ) {
			$ids['client_id'] = $match[1];
		}

		$session = $cookies[ '_ga_' . substr( $measurement_id, 2 ) ] ?? '';
		if ( is_string( $session ) && preg_match( '/^GS\d+\.\d+\.s?(\d{1,20})(?:[.$]|$)/', $session, $match ) ) {
			$ids['session_id'] = $match[1];
		}

		return $ids;
	}

	/**
	 * @see https://plausible.io/docs/events-api
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function plausible( array $config, array $event ): array {
		$headers = array( 'User-Agent' => (string) $event['user_agent'] );
		if ( '' !== (string) $event['ip'] ) {
			$headers['X-Forwarded-For'] = (string) $event['ip'];
		}

		$body = array(
			'name'   => self::EVENT_LABEL,
			'url'    => (string) $event['short_url'],
			'domain' => (string) $config['domain'],
			'props'  => self::properties( $event ),
		);
		if ( '' !== (string) $event['referrer'] ) {
			$body['referrer'] = (string) $event['referrer'];
		}

		return array(
			'url'  => self::base( (string) ( $config['base_url'] ?? '' ), 'https://plausible.io' ) . '/api/event',
			'args' => self::json_args( $headers, $body ),
		);
	}

	/**
	 * @see https://developer.matomo.org/api-reference/tracking-api
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function matomo( array $config, array $event ): array {
		$params = array(
			'idsite'     => (int) $config['site_id'],
			'rec'        => 1,
			'apiv'       => 1,
			'send_image' => 0,
			'rand'       => wp_rand(),
			'_id'        => substr( (string) $event['visitor_id'], 0, 16 ),
			'url'        => (string) $event['short_url'],
			'e_c'        => 'Redirect',
			'e_a'        => self::EVENT_LABEL,
			'e_n'        => '' !== (string) $event['link_name'] ? (string) $event['link_name'] : (string) $event['link_slug'],
			'ua'         => (string) $event['user_agent'],
			'urlref'     => (string) $event['referrer'],
		);

		// Matomo only honours a visitor IP or country on an authenticated request.
		$token = (string) ( $config['token'] ?? '' );
		if ( '' !== $token && '' !== (string) $event['ip'] ) {
			$params['token_auth'] = $token;
			$params['cip']        = (string) $event['ip'];
			if ( '' !== (string) $event['country'] ) {
				$params['country'] = strtolower( (string) $event['country'] );
			}
		}

		return array(
			'url'  => self::base( (string) $config['base_url'], '' ) . '/matomo.php',
			'args' => array(
				'method' => 'POST',
				'body'   => $params,
			),
		);
	}

	/**
	 * @see https://docs.umami.is/docs/api/sending-stats
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function umami( array $config, array $event ): array {
		return array(
			'url'  => self::base( (string) ( $config['base_url'] ?? '' ), 'https://gateway.umami.is' ) . '/api/send',
			'args' => self::json_args(
				array( 'User-Agent' => (string) $event['user_agent'] ),
				array(
					'type'    => 'event',
					'payload' => array(
						'website'  => (string) $config['website_id'],
						'hostname' => (string) $event['host'],
						'url'      => (string) $event['path'],
						'referrer' => (string) $event['referrer'],
						'title'    => (string) $event['link_name'],
						'name'     => self::EVENT,
						'data'     => self::properties( $event ),
					),
				)
			),
		);
	}

	/**
	 * @see https://docs.simpleanalytics.com/events/server-side
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function simple_analytics( array $config, array $event ): array {
		$body = array(
			'type'     => 'event',
			'hostname' => (string) $config['hostname'],
			'event'    => self::EVENT,
			'path'     => (string) $event['path'],
			'ua'       => (string) $event['user_agent'],
			'metadata' => self::properties( $event ),
		);
		if ( '' !== (string) $event['referrer'] ) {
			$body['referrer'] = (string) $event['referrer'];
		}

		return array(
			'url'  => 'https://queue.simpleanalyticscdn.com/events',
			'args' => self::json_args( array( 'User-Agent' => (string) $event['user_agent'] ), $body ),
		);
	}

	/**
	 * @see https://posthog.com/docs/api/capture
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function posthog( array $config, array $event ): array {
		$properties = array_merge(
			self::properties( $event ),
			array(
				'$current_url'            => (string) $event['short_url'],
				'$referrer'               => (string) $event['referrer'],
				'$raw_user_agent'         => (string) $event['user_agent'],
				'$process_person_profile' => false,
			)
		);

		// Without the visitor's IP, PostHog would geolocate this server instead.
		if ( '' !== (string) $event['ip'] ) {
			$properties['$ip'] = (string) $event['ip'];
		} else {
			$properties['$geoip_disable'] = true;
		}

		$hosts = array(
			'us' => 'https://us.i.posthog.com',
			'eu' => 'https://eu.i.posthog.com',
		);
		$base  = $hosts[ (string) ( $config['region'] ?? 'us' ) ] ?? $hosts['us'];

		return array(
			'url'  => self::base( (string) ( $config['base_url'] ?? '' ), $base ) . '/i/v0/e/',
			'args' => self::json_args(
				array(),
				array(
					'api_key'     => (string) $config['api_key'],
					'event'       => self::EVENT,
					'distinct_id' => (string) $event['visitor_id'],
					'timestamp'   => gmdate( 'c', (int) $event['time'] ),
					'properties'  => $properties,
				)
			),
		);
	}

	/**
	 * @see https://docs.mixpanel.com/reference/track-event
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function mixpanel( array $config, array $event ): array {
		$hosts = array(
			'us' => 'https://api.mixpanel.com',
			'eu' => 'https://api-eu.mixpanel.com',
			'in' => 'https://api-in.mixpanel.com',
		);
		$base  = $hosts[ (string) ( $config['region'] ?? 'us' ) ] ?? $hosts['us'];

		$properties = array_merge(
			self::properties( $event ),
			array(
				'token'        => (string) $config['token'],
				'distinct_id'  => (string) $event['visitor_id'],
				'time'         => (int) $event['time'],
				'$insert_id'   => (string) $event['event_id'],
				'$current_url' => (string) $event['short_url'],
				'$referrer'    => (string) $event['referrer'],
			)
		);
		if ( '' !== (string) $event['ip'] ) {
			$properties['ip'] = (string) $event['ip'];
		}

		// ip=0 stops Mixpanel using this server's address as the visitor's location.
		return array(
			'url'  => $base . '/track?ip=0&verbose=1',
			'args' => self::json_args(
				array(),
				array(
					array(
						'event'      => self::EVENT,
						'properties' => $properties,
					),
				)
			),
		);
	}

	/**
	 * @see https://amplitude.com/docs/apis/analytics/http-v2
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function amplitude( array $config, array $event ): array {
		$row = array(
			'event_type'       => self::EVENT,
			'device_id'        => (string) $event['visitor_id'],
			'time'             => (int) $event['time'] * 1000,
			'insert_id'        => (string) $event['event_id'],
			'user_agent'       => (string) $event['user_agent'],
			'event_properties' => self::properties( $event ),
		);
		if ( '' !== (string) $event['ip'] ) {
			$row['ip'] = (string) $event['ip'];
		}
		if ( '' !== (string) $event['country'] ) {
			$row['country'] = (string) $event['country'];
		}

		$host = 'eu' === ( $config['region'] ?? '' ) ? 'https://api.eu.amplitude.com' : 'https://api2.amplitude.com';

		return array(
			'url'  => $host . '/2/httpapi',
			'args' => self::json_args(
				array(),
				array(
					'api_key' => (string) $config['api_key'],
					'events'  => array( $row ),
				)
			),
		);
	}

	/**
	 * Rybbit only honours a visitor IP and user agent on a request with an API key.
	 *
	 * @see https://rybbit.com/docs/api/sending-events
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function rybbit( array $config, array $event ): array {
		$body = array(
			'site_id'      => (string) $config['site_id'],
			'type'         => 'custom_event',
			'event_name'   => self::EVENT,
			'hostname'     => (string) $event['host'],
			'pathname'     => (string) $event['path'],
			'page_title'   => self::clip( (string) $event['link_name'], 512 ),
			'referrer'     => (string) $event['referrer'],
			'anonymous_id' => (string) $event['visitor_id'],
			'user_agent'   => self::clip( (string) $event['user_agent'], 512 ),
			'properties'   => (string) wp_json_encode( self::properties( $event ) ),
		);
		if ( '' !== (string) $event['ip'] ) {
			$body['ip_address'] = (string) $event['ip'];
		}

		return array(
			'url'  => self::base( (string) ( $config['base_url'] ?? '' ), 'https://app.rybbit.io' ) . '/api/track',
			'args' => self::json_args( array( 'Authorization' => 'Bearer ' . (string) $config['api_key'] ), $body ),
		);
	}

	/**
	 * @see https://clicky.com/help/custom/manual
	 *
	 * @param array<string, mixed> $config Tool config.
	 * @param array<string, mixed> $event  Click event.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private static function clicky( array $config, array $event ): array {
		$params = array(
			'site_id'       => (string) $config['site_id'],
			'sitekey_admin' => (string) $config['sitekey_admin'],
			'type'          => 'click',
			'href'          => '#short-link/' . (string) $event['link_slug'],
			'title'         => (string) $event['link_name'],
			'ua'            => (string) $event['user_agent'],
			'ref'           => (string) $event['referrer'],
		);
		if ( '' !== (string) $event['ip'] ) {
			$params['ip_address'] = (string) $event['ip'];
		}

		return array(
			'url'  => add_query_arg( array_map( 'rawurlencode', $params ), 'https://in.getclicky.com/in.php' ),
			'args' => array( 'method' => 'GET' ),
		);
	}

	private static function base( string $configured, string $fallback ): string {
		$base = '' !== trim( $configured ) ? trim( $configured ) : $fallback;

		return untrailingslashit( $base );
	}

	private static function clip( string $value, int $limit ): string {
		return mb_substr( $value, 0, $limit );
	}
}
