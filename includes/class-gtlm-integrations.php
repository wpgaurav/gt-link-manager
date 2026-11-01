<?php
/**
 * Opt-in analytics integrations: the tool registry, saved config and delivery.
 *
 * Nothing here runs until a site owner enables a tool. Delivery happens after
 * the redirect response has been finished, so a slow analytics service never
 * sits on the visitor's clock.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Integrations {
	public const OPTION = 'gtlm_integrations';

	private const HTTP_TIMEOUT = 2;

	/** Keys in the enabled list that need the click listener; GA4 is one only in browser mode. */
	private const BROWSER_TOOLS = array( 'gtm', 'fathom', 'clarity', 'ga4_browser' );

	/**
	 * Register the hooks enabled tools need on every request.
	 *
	 * @param array<int, string> $enabled Enabled tool keys.
	 */
	public static function init( array $enabled ): void {
		require_once __DIR__ . '/class-gtlm-integration-plugins.php';
		GTLM_Integration_Plugins::register_hooks( $enabled );

		// This runs before translations can load, so it must not build the registry.
		if ( ! is_admin() && array_intersect( $enabled, self::BROWSER_TOOLS ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_bridge' ) );
		}
	}

	/**
	 * Every supported tool.
	 *
	 * type plugin:  a WordPress plugin on this site, recorded in-process.
	 * type service: a hosted or self-hosted API, sent over HTTP from this server.
	 * type browser: only has a JavaScript API, so it needs the click-listener script.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		$url_help = __( 'Leave blank to use the hosted service. Fill this in if you host it yourself.', 'gt-link-manager' );
		$regions  = array(
			'us' => __( 'United States', 'gt-link-manager' ),
			'eu' => __( 'European Union', 'gt-link-manager' ),
		);

		return array(
			'independent_analytics' => array(
				'label'  => 'Independent Analytics',
				'type'   => 'plugin',
				'plugin' => 'independent-analytics',
				'help'   => __( 'Each short link appears on the Pages report as a GT Link, with its referrer and the visitor\'s location. Clicks count as views there, so they add to your total views.', 'gt-link-manager' ),
			),
			'koko_analytics'        => array(
				'label'  => 'Koko Analytics',
				'type'   => 'plugin',
				'plugin' => 'koko-analytics',
				'help'   => __( 'Each short link appears on the Pages report by its path. Clicks count as pageviews there and show up after Koko\'s next aggregation run.', 'gt-link-manager' ),
			),
			'wp_statistics'         => array(
				'label'  => 'WP Statistics',
				'type'   => 'plugin',
				'plugin' => 'wp-statistics',
				'help'   => __( 'Each click is stored as a gtlm_link_click custom event. WP Statistics shows custom events through its Data Plus add-on.', 'gt-link-manager' ),
			),
			'burst_statistics'      => array(
				'label'  => 'Burst Statistics',
				'type'   => 'plugin',
				'plugin' => 'burst-statistics',
				'help'   => __( 'Each click is stored as a custom event on the visitor\'s Burst session. Turn on custom events in Burst\'s settings. Clicks are only recorded for visitors with Burst\'s cookie and an earlier tracked pageview, so Burst\'s cookieless mode records none.', 'gt-link-manager' ),
			),
			'slimstat'              => array(
				'label'  => 'SlimStat Analytics',
				'type'   => 'plugin',
				'plugin' => 'wp-slimstat',
				'help'   => __( 'Each click is recorded as a pageview of the short link\'s path, using SlimStat\'s server-side tracker and exclusion rules.', 'gt-link-manager' ),
			),
			'statify'               => array(
				'label'  => 'Statify',
				'type'   => 'plugin',
				'plugin' => 'statify',
				'help'   => __( 'Each click is counted as a view of the short link\'s path, with the referring site.', 'gt-link-manager' ),
			),
			'matomo_plugin'         => array(
				'label'  => 'Matomo Analytics (WordPress plugin)',
				'type'   => 'plugin',
				'plugin' => 'matomo',
				'help'   => __( 'For Matomo running inside WordPress. Each click is recorded as an event under Behaviour, Events. For a separate Matomo install or Matomo Cloud, use the Matomo service below instead.', 'gt-link-manager' ),
			),
			'ga4'                   => array(
				'label'  => 'Google Analytics 4',
				'type'   => 'service',
				'docs'   => 'https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events',
				'help'   => __( 'Each click becomes a link_click event. MonsterInsights, ExactMetrics, Analytify, Site Kit and GA Google Analytics are read automatically when these fields are left blank.', 'gt-link-manager' ),
				'fields' => array(
					'mode'           => array(
						'label'   => __( 'Send events', 'gt-link-manager' ),
						'type'    => 'select',
						'options' => array(
							'server'  => __( 'From this server', 'gt-link-manager' ),
							'browser' => __( 'From the visitor\'s browser', 'gt-link-manager' ),
						),
						'help'    => __( 'From this server counts every click but needs an API secret. From the browser uses the GA4 tag already on your site with no secret, but only sees clicks on your own pages and not visitors who block the tag.', 'gt-link-manager' ),
					),
					'measurement_id' => array(
						'label'       => __( 'Measurement ID', 'gt-link-manager' ),
						'type'        => 'text',
						'placeholder' => 'G-XXXXXXXXXX',
						'pattern'     => '/^G-[A-Z0-9]{4,20}$/i',
						'upper'       => true,
						'required'    => array( 'mode' => 'server' ),
					),
					'api_secret'     => array(
						'label'    => __( 'Measurement Protocol API secret', 'gt-link-manager' ),
						'type'     => 'secret',
						'required' => array( 'mode' => 'server' ),
					),
					'region'         => array(
						'label'   => __( 'Data region', 'gt-link-manager' ),
						'type'    => 'select',
						'options' => array(
							'global' => __( 'Default', 'gt-link-manager' ),
							'eu'     => __( 'European Union', 'gt-link-manager' ),
						),
					),
				),
			),
			'plausible'             => array(
				'label'  => 'Plausible Analytics',
				'type'   => 'service',
				'beta'   => true,
				'docs'   => 'https://plausible.io/docs/events-api',
				'help'   => __( 'Each click becomes a Link Click event. Add Link Click as a custom event goal in Plausible to see it. Plausible can drop events from server and hosting addresses, which is the address it sees while IP address and country is off. Send a test event to check.', 'gt-link-manager' ),
				'fields' => array(
					'domain'   => array(
						'label'    => __( 'Site domain in Plausible', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'base_url' => array(
						'label' => __( 'Self-hosted URL', 'gt-link-manager' ),
						'type'  => 'url',
						'help'  => $url_help,
					),
				),
			),
			'matomo'                => array(
				'label'  => 'Matomo',
				'type'   => 'service',
				'docs'   => 'https://developer.matomo.org/api-reference/tracking-api',
				'help'   => __( 'For Matomo Cloud or your own Matomo install. Each click is recorded as an event under Behaviour, Events.', 'gt-link-manager' ),
				'fields' => array(
					'base_url' => array(
						'label'       => __( 'Matomo URL', 'gt-link-manager' ),
						'type'        => 'url',
						'placeholder' => 'https://analytics.example.com',
						'required'    => true,
					),
					'site_id'  => array(
						'label'    => __( 'Site ID', 'gt-link-manager' ),
						'type'     => 'text',
						'pattern'  => '/^\d{1,10}$/',
						'required' => true,
					),
					'token'    => array(
						'label' => __( 'Auth token', 'gt-link-manager' ),
						'type'  => 'secret',
						'help'  => __( 'Optional. Matomo only accepts a visitor\'s IP address on an authenticated request.', 'gt-link-manager' ),
					),
				),
			),
			'umami'                 => array(
				'label'  => 'Umami',
				'type'   => 'service',
				'docs'   => 'https://docs.umami.is/docs/api/sending-stats',
				'help'   => __( 'Each click becomes a link_click event with the link details as event data.', 'gt-link-manager' ),
				'fields' => array(
					'website_id' => array(
						'label'    => __( 'Website ID', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'base_url'   => array(
						'label' => __( 'Self-hosted URL', 'gt-link-manager' ),
						'type'  => 'url',
						'help'  => $url_help,
					),
				),
			),
			'simple_analytics'      => array(
				'label'  => 'Simple Analytics',
				'type'   => 'service',
				'docs'   => 'https://docs.simpleanalytics.com/events/server-side',
				'help'   => __( 'Each click becomes a link_click event.', 'gt-link-manager' ),
				'fields' => array(
					'hostname' => array(
						'label'    => __( 'Site domain in Simple Analytics', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
				),
			),
			'posthog'               => array(
				'label'  => 'PostHog',
				'type'   => 'service',
				'docs'   => 'https://posthog.com/docs/api/capture',
				'help'   => __( 'Each click becomes a link_click event that does not create a person profile.', 'gt-link-manager' ),
				'fields' => array(
					'api_key'  => array(
						'label'    => __( 'Project API key', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'region'   => array(
						'label'   => __( 'Cloud region', 'gt-link-manager' ),
						'type'    => 'select',
						'options' => $regions,
					),
					'base_url' => array(
						'label' => __( 'Self-hosted URL', 'gt-link-manager' ),
						'type'  => 'url',
						'help'  => __( 'Leave blank to use PostHog Cloud. Fill this in if you host it yourself.', 'gt-link-manager' ),
					),
				),
			),
			'mixpanel'              => array(
				'label'  => 'Mixpanel',
				'type'   => 'service',
				'docs'   => 'https://docs.mixpanel.com/reference/track-event',
				'help'   => __( 'Each click becomes a link_click event.', 'gt-link-manager' ),
				'fields' => array(
					'token'  => array(
						'label'    => __( 'Project token', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'region' => array(
						'label'   => __( 'Data residency', 'gt-link-manager' ),
						'type'    => 'select',
						'options' => array_merge( $regions, array( 'in' => __( 'India', 'gt-link-manager' ) ) ),
					),
				),
			),
			'amplitude'             => array(
				'label'  => 'Amplitude',
				'type'   => 'service',
				'docs'   => 'https://amplitude.com/docs/apis/analytics/http-v2',
				'help'   => __( 'Each click becomes a link_click event.', 'gt-link-manager' ),
				'fields' => array(
					'api_key' => array(
						'label'    => __( 'API key', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'region'  => array(
						'label'   => __( 'Data center', 'gt-link-manager' ),
						'type'    => 'select',
						'options' => $regions,
					),
				),
			),
			'rybbit'                => array(
				'label'  => 'Rybbit',
				'type'   => 'service',
				'docs'   => 'https://rybbit.com/docs/api/sending-events',
				'help'   => __( 'Each click becomes a link_click custom event. Use an API key with ingest access, because Rybbit treats a server request without one as a bot.', 'gt-link-manager' ),
				'fields' => array(
					'site_id'  => array(
						'label'    => __( 'Site ID', 'gt-link-manager' ),
						'type'     => 'text',
						'required' => true,
					),
					'api_key'  => array(
						'label'    => __( 'API key', 'gt-link-manager' ),
						'type'     => 'secret',
						'required' => true,
					),
					'base_url' => array(
						'label' => __( 'Self-hosted URL', 'gt-link-manager' ),
						'type'  => 'url',
						'help'  => $url_help,
					),
				),
			),
			'clicky'                => array(
				'label'  => 'Clicky',
				'type'   => 'service',
				'docs'   => 'https://clicky.com/help/custom/manual',
				'help'   => __( 'Each click is logged as a click action. Clicky limits server-side logging to its Pro plans.', 'gt-link-manager' ),
				'fields' => array(
					'site_id'       => array(
						'label'    => __( 'Site ID', 'gt-link-manager' ),
						'type'     => 'text',
						'pattern'  => '/^\d{1,12}$/',
						'required' => true,
					),
					'sitekey_admin' => array(
						'label'    => __( 'Admin site key', 'gt-link-manager' ),
						'type'     => 'secret',
						'required' => true,
					),
				),
			),
			'gtm'                   => array(
				'label'  => 'Google Tag Manager',
				'type'   => 'browser',
				'help'   => __( 'Pushes a gtlm_link_click event to the data layer when a visitor clicks a short link on your site. GTM4WP\'s data layer name is read automatically.', 'gt-link-manager' ),
				'fields' => array(
					'data_layer' => array(
						'label'       => __( 'Data layer name', 'gt-link-manager' ),
						'type'        => 'text',
						'placeholder' => 'dataLayer',
						'pattern'     => '/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/',
					),
				),
			),
			'fathom'                => array(
				'label' => 'Fathom Analytics',
				'type'  => 'browser',
				'docs'  => 'https://usefathom.com/docs/features/events',
				'help'  => __( 'Fathom has no server-side event API, so this sends a Link Click: {slug} event from the visitor\'s browser through your Fathom script.', 'gt-link-manager' ),
			),
			'clarity'               => array(
				'label' => 'Microsoft Clarity',
				'type'  => 'browser',
				'docs'  => 'https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-api',
				'help'  => __( 'Clarity has no server-side API, so this sends a link_click event from the visitor\'s browser through your Clarity script.', 'gt-link-manager' ),
			),
		);
	}

	/** Whether a service currently receives visitor IP addresses and countries. */
	public static function shares_visitor_data(): bool {
		$config = self::config();
		if ( empty( $config['share_visitor_data'] ) ) {
			return false;
		}

		foreach ( self::tools() as $key => $tool ) {
			if ( 'service' === $tool['type'] && ! empty( $config['tools'][ $key ]['enabled'] ) && ( 'ga4' !== $key || 'browser' !== ( $config['tools'][ $key ]['mode'] ?? 'server' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Saved config, normalized.
	 *
	 * @return array{share_visitor_data: int, tools: array<string, array<string, mixed>>}
	 */
	public static function config(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'share_visitor_data' => (int) ! empty( $stored['share_visitor_data'] ),
			'tools'              => isset( $stored['tools'] ) && is_array( $stored['tools'] ) ? $stored['tools'] : array(),
		);
	}

	/**
	 * Persist config and the small enabled list the bootstrap reads on every request.
	 *
	 * @param array{share_visitor_data: int, tools: array<string, array<string, mixed>>} $config Config.
	 */
	public static function save( array $config ): void {
		update_option( self::OPTION, $config, false );
		GTLM_Settings::get_instance()->set_enabled_integrations( self::enabled_keys( $config ) );
	}

	/**
	 * @param array{tools: array<string, array<string, mixed>>} $config Config.
	 * @return array<int, string>
	 */
	public static function enabled_keys( array $config ): array {
		$keys = array();
		foreach ( self::tools() as $key => $tool ) {
			if ( empty( $config['tools'][ $key ]['enabled'] ) ) {
				continue;
			}
			$keys[] = $key;
			if ( 'ga4' === $key && 'browser' === ( $config['tools'][ $key ]['mode'] ?? 'server' ) ) {
				$keys[] = 'ga4_browser';
			}
		}

		return $keys;
	}

	/**
	 * Clean a submitted form against the registry.
	 *
	 * An enabled tool that is missing a required value is saved switched off,
	 * so it can never send half-configured requests.
	 *
	 * @param array<string, mixed>                                                     $raw      Unslashed form data.
	 * @param array{share_visitor_data: int, tools: array<string, array<string, mixed>>} $existing Saved config.
	 * @return array{config: array{share_visitor_data: int, tools: array<string, array<string, mixed>>}, errors: array<int, string>}
	 */
	public static function sanitize_config( array $raw, array $existing ): array {
		$errors = array();
		$tools  = array();
		$posted = isset( $raw['tools'] ) && is_array( $raw['tools'] ) ? $raw['tools'] : array();

		require_once __DIR__ . '/class-gtlm-integration-plugins.php';

		foreach ( self::tools() as $key => $tool ) {
			$input = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
			$saved = isset( $existing['tools'][ $key ] ) && is_array( $existing['tools'][ $key ] ) ? $existing['tools'][ $key ] : array();

			if ( ! empty( $input['forget'] ) ) {
				continue;
			}

			$clean   = array( 'enabled' => (int) ! empty( $input['enabled'] ) );
			$detect  = $clean['enabled'] ? self::detected_values( $key ) : array();
			$missing = array();

			foreach ( (array) ( $tool['fields'] ?? array() ) as $name => $field ) {
				$value = self::sanitize_field( $field, $input[ $name ] ?? '', $saved[ $name ] ?? '' );

				if ( null === $value ) {
					/* translators: 1: tool name, 2: field label. */
					$errors[]       = sprintf( __( '%1$s: the value for %2$s was not valid, so it was not saved.', 'gt-link-manager' ), $tool['label'], $field['label'] );
					$value          = (string) ( $saved[ $name ] ?? '' );
					$clean[ $name ] = $value;
					continue;
				}

				if ( '' === $value && isset( $detect['values'][ $name ] ) ) {
					$value = (string) $detect['values'][ $name ];
				}

				$clean[ $name ] = $value;
			}

			foreach ( (array) ( $tool['fields'] ?? array() ) as $name => $field ) {
				if ( '' === (string) $clean[ $name ] && self::field_required( $field, $clean ) ) {
					$missing[] = $field['label'];
				}
			}

			if ( $clean['enabled'] && $missing ) {
				$clean['enabled'] = 0;
				/* translators: 1: tool name, 2: comma-separated field labels. */
				$errors[] = sprintf( __( '%1$s was left off because it still needs: %2$s.', 'gt-link-manager' ), $tool['label'], implode( ', ', $missing ) );
			}

			if ( $clean['enabled'] && 'plugin' === $tool['type'] && ! GTLM_Integration_Plugins::available( $key ) ) {
				$clean['enabled'] = 0;
				/* translators: %s: tool name. */
				$errors[] = sprintf( __( '%s was left off because the plugin is not active.', 'gt-link-manager' ), $tool['label'] );
			}

			$tools[ $key ] = $clean;
		}

		return array(
			'config' => array(
				'share_visitor_data' => (int) ! empty( $raw['share_visitor_data'] ),
				'tools'              => $tools,
			),
			'errors' => $errors,
		);
	}

	/**
	 * @param array<string, mixed> $field Field definition.
	 * @param array<string, mixed> $clean Tool values so far.
	 */
	private static function field_required( array $field, array $clean ): bool {
		$required = $field['required'] ?? false;
		if ( is_array( $required ) ) {
			foreach ( $required as $other => $value ) {
				if ( (string) ( $clean[ $other ] ?? '' ) !== (string) $value ) {
					return false;
				}
			}
			return true;
		}

		return (bool) $required;
	}

	/**
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Submitted value.
	 * @param mixed                $saved Saved value.
	 * @return string|null Null when the submitted value is invalid.
	 */
	private static function sanitize_field( array $field, $value, $saved ): ?string {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';

		switch ( $field['type'] ) {
			case 'secret':
				// A blank secret keeps what is saved, because secrets are never echoed back.
				return '' !== $value ? $value : (string) $saved;

			case 'select':
				$options = (array) $field['options'];
				return isset( $options[ $value ] ) ? $value : (string) array_key_first( $options );

			case 'url':
				if ( '' === $value ) {
					return '';
				}
				// esc_url_raw() would turn "not a url" into https://not%20a%20url.
				if ( ! preg_match( '#^https?://#i', $value ) ) {
					return null;
				}
				$url   = esc_url_raw( $value, array( 'https', 'http' ) );
				$parts = wp_parse_url( $url );
				if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! preg_match( '/^[a-z0-9.-]+$/i', $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
					return null;
				}
				return untrailingslashit( $url );
		}

		if ( ! empty( $field['upper'] ) ) {
			$value = strtoupper( $value );
		}
		if ( '' !== $value && isset( $field['pattern'] ) && ! preg_match( (string) $field['pattern'], $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Values another plugin on this site already holds for a tool.
	 *
	 * @return array{source: string, values: array<string, string>}
	 */
	public static function detected_values( string $tool ): array {
		$found = array(
			'source' => '',
			'values' => array(),
		);

		if ( 'ga4' === $tool ) {
			return self::detect_ga4();
		}

		if ( 'plausible' === $tool ) {
			$options = get_option( 'plausible_analytics_settings', array() );
			$domain  = is_array( $options ) ? ( $options['domain_name'] ?? '' ) : '';
			$domain  = is_array( $domain ) ? ( $domain['default'] ?? '' ) : $domain;
			if ( is_string( $domain ) && '' !== $domain ) {
				$found['source']           = 'Plausible Analytics';
				$found['values']['domain'] = $domain;
				$hosted                    = is_array( $options ) ? ( $options['self_hosted_domain'] ?? '' ) : '';
				if ( is_string( $hosted ) && '' !== $hosted ) {
					$found['values']['base_url'] = 'https://' . $hosted;
				}
			}
			return $found;
		}

		if ( 'matomo' === $tool ) {
			$url = get_option( 'wp-piwik_global-piwik_url', '' );
			$id  = get_option( 'wp-piwik-site_id', '' );
			if ( is_string( $url ) && '' !== $url ) {
				$found['source']             = 'Connect Matomo';
				$found['values']['base_url'] = untrailingslashit( $url );
				if ( is_scalar( $id ) && '' !== (string) $id ) {
					$found['values']['site_id'] = (string) $id;
				}
			}
			return $found;
		}

		if ( 'simple_analytics' === $tool ) {
			$found['values']['hostname'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			return $found;
		}

		if ( 'clicky' === $tool ) {
			$options = get_option( 'clicky', array() );
			if ( is_array( $options ) && ! empty( $options['site_id'] ) ) {
				$found['source']            = 'Clicky';
				$found['values']['site_id'] = (string) $options['site_id'];
				if ( ! empty( $options['admin_site_key'] ) ) {
					$found['values']['sitekey_admin'] = (string) $options['admin_site_key'];
				}
			}
			return $found;
		}

		if ( 'gtm' === $tool ) {
			$options = get_option( 'gtm4wp-options', array() );
			$name    = is_array( $options ) ? ( $options['gtm-datalayer-variable-name'] ?? '' ) : '';
			if ( is_string( $name ) && '' !== $name ) {
				$found['source']               = 'GTM4WP';
				$found['values']['data_layer'] = $name;
			}
			return $found;
		}

		return $found;
	}

	/**
	 * Read GA4 details from whichever Google Analytics plugin is connected.
	 *
	 * @return array{source: string, values: array<string, string>}
	 */
	private static function detect_ga4(): array {
		$readers = array(
			'MonsterInsights'     => static function (): array {
				if ( ! function_exists( 'monsterinsights_get_v4_id' ) ) {
					return array();
				}
				$auth = function_exists( 'MonsterInsights' ) ? MonsterInsights()->auth : null;
				return array( monsterinsights_get_v4_id(), is_object( $auth ) && method_exists( $auth, 'get_measurement_protocol_secret' ) ? $auth->get_measurement_protocol_secret() : '' );
			},
			'ExactMetrics'        => static function (): array {
				if ( ! function_exists( 'exactmetrics_get_v4_id' ) ) {
					return array();
				}
				$auth = function_exists( 'ExactMetrics' ) ? ExactMetrics()->auth : null;
				return array( exactmetrics_get_v4_id(), is_object( $auth ) && method_exists( $auth, 'get_measurement_protocol_secret' ) ? $auth->get_measurement_protocol_secret() : '' );
			},
			'Analytify'           => static function (): array {
				$secret = class_exists( 'WPANALYTIFY_Utils' ) && method_exists( 'WPANALYTIFY_Utils', 'get_option' ) ? WPANALYTIFY_Utils::get_option( 'measurement_protocol_secret', 'wp-analytify-advanced' ) : '';
				return array( get_option( 'analytify_ua_code', '' ), $secret );
			},
			'Site Kit'            => static function (): array {
				$options = get_option( 'googlesitekit_analytics-4_settings', array() );
				return array( is_array( $options ) ? ( $options['measurementID'] ?? '' ) : '', '' );
			},
			'GA Google Analytics' => static function (): array {
				$options = get_option( 'gap_options', array() );
				return array( is_array( $options ) ? ( $options['gap_id'] ?? '' ) : '', '' );
			},
		);

		foreach ( $readers as $source => $reader ) {
			$values = $reader();
			$id     = is_string( $values[0] ?? null ) ? strtoupper( $values[0] ) : '';
			if ( ! preg_match( '/^G-[A-Z0-9]{4,20}$/', $id ) ) {
				continue;
			}

			$found = array(
				'source' => $source,
				'values' => array( 'measurement_id' => $id ),
			);
			if ( is_string( $values[1] ?? null ) && '' !== $values[1] ) {
				$found['values']['api_secret'] = $values[1];
			}
			return $found;
		}

		return array(
			'source' => '',
			'values' => array(),
		);
	}

	/**
	 * Send one finished redirect to every enabled tool. Runs after the response.
	 *
	 * @param array<string, mixed>      $link       Link row.
	 * @param string                    $target_url Final destination.
	 * @param int                       $status     HTTP status sent.
	 * @param array<string, mixed>|null $geo        Geo resolution, or null.
	 */
	public static function dispatch( array $link, string $target_url, int $status, ?array $geo ): void {
		require_once __DIR__ . '/class-gtlm-analytics-collector.php';

		if ( ! GTLM_Analytics_Collector::is_eligible_visit() ) {
			return;
		}

		/**
		 * Filter whether this click reaches third-party analytics at all.
		 *
		 * Use this to require visitor consent, or to exclude links or visitors.
		 *
		 * @param bool                 $send Whether to send the click.
		 * @param array<string, mixed> $link Link row.
		 */
		if ( ! (bool) apply_filters( 'gtlm_integrations_should_send', true, $link ) ) {
			return;
		}

		$config = self::config();
		$event  = self::build_event( $link, $target_url, $status, $geo, (bool) $config['share_visitor_data'] );

		/**
		 * Filter the click event before any tool receives it.
		 *
		 * @param array<string, mixed> $event Click event.
		 * @param array<string, mixed> $link  Link row.
		 */
		$event = (array) apply_filters( 'gtlm_integration_event', $event, $link );

		// Plugin tools write locally, so they go first and a slow service cannot delay them.
		foreach ( array( 'plugin', 'service' ) as $type ) {
			foreach ( self::tools() as $key => $tool ) {
				$settings = $config['tools'][ $key ] ?? array();
				if ( $type !== $tool['type'] || empty( $settings['enabled'] ) ) {
					continue;
				}
				try {
					self::deliver( $key, $tool, $settings, $event );
				} catch ( Throwable $error ) {
					// One tool failing must not stop the others. Never log click data.
					continue;
				}
			}
		}
	}

	/**
	 * @param string               $key      Tool key.
	 * @param array<string, mixed> $tool     Tool definition.
	 * @param array<string, mixed> $settings Saved tool config.
	 * @param array<string, mixed> $event    Click event.
	 */
	private static function deliver( string $key, array $tool, array $settings, array $event ): void {
		if ( 'plugin' === $tool['type'] ) {
			require_once __DIR__ . '/class-gtlm-integration-plugins.php';
			GTLM_Integration_Plugins::record( $key, $event );
			return;
		}

		if ( 'ga4' === $key && 'browser' === ( $settings['mode'] ?? 'server' ) ) {
			return;
		}

		$response = self::send_request( $key, $settings, $event, self::HTTP_TIMEOUT );

		/**
		 * Fires after a service has been sent a click.
		 *
		 * @param string               $key      Tool key.
		 * @param array|WP_Error|null  $response HTTP response, or null if a filter cancelled the request.
		 * @param array<string, mixed> $event    Click event.
		 */
		do_action( 'gtlm_integration_sent', $key, $response, $event );
	}

	/**
	 * @param string               $key      Tool key.
	 * @param array<string, mixed> $settings Saved tool config.
	 * @param array<string, mixed> $event    Click event.
	 * @param int                  $timeout  Seconds.
	 * @return array<string, mixed>|WP_Error|null
	 */
	private static function send_request( string $key, array $settings, array $event, int $timeout ) {
		require_once __DIR__ . '/class-gtlm-integration-services.php';

		$request = GTLM_Integration_Services::request( $key, $settings, $event );

		/**
		 * Filter the HTTP request for a service. Return null to skip sending it.
		 *
		 * @param array{url: string, args: array<string, mixed>}|null $request Request.
		 * @param string                                              $key     Tool key.
		 * @param array<string, mixed>                                $event   Click event.
		 */
		$request = apply_filters( 'gtlm_integration_request', $request, $key, $event );
		if ( ! is_array( $request ) ) {
			return null;
		}

		$args = array_merge(
			(array) $request['args'],
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		// The response has been sent, so waiting here costs a worker, not the visitor.
		return wp_safe_remote_request( (string) $request['url'], $args );
	}

	/**
	 * Build the normalized click event. The visitor's IP address and country
	 * only go in when the owner has opted in to sharing them.
	 *
	 * @param array<string, mixed>      $link       Link row.
	 * @param string                    $target_url Final destination.
	 * @param int                       $status     HTTP status sent.
	 * @param array<string, mixed>|null $geo        Geo resolution, or null.
	 * @param bool                      $share      Whether visitor data may be shared.
	 * @return array<string, mixed>
	 */
	public static function build_event( array $link, string $target_url, int $status, ?array $geo, bool $share ): array {
		require_once __DIR__ . '/class-gtlm-analytics-referrer.php';

		$home = (array) wp_parse_url( home_url() );
		$host = (string) ( $home['host'] ?? '' );
		$uri  = self::server_value( 'REQUEST_URI', 2048 );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '' !== $path ? $path : '/';
		$base = ( $home['scheme'] ?? 'https' ) . '://' . $host . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$mode = (string) ( $link['link_mode'] ?? 'standard' );

		$referrer = GTLM_Analytics_Referrer::parse( self::server_value( 'HTTP_REFERER', 2048 ) );
		$category = '';
		if ( ! empty( $link['category_id'] ) ) {
			$row      = ( new GTLM_DB() )->get_category( (int) $link['category_id'] );
			$category = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
		}

		$user_agent = self::server_value( 'HTTP_USER_AGENT', 512 );
		$ip         = $share ? self::client_ip() : '';
		$visitor_id = $share && '' !== $ip
			// Rotates daily and is never stored, so it groups a visit without following anyone.
			? substr( hash_hmac( 'sha256', gmdate( 'Y-m-d' ) . '|' . $ip . '|' . $user_agent, wp_salt( 'nonce' ) ), 0, 32 )
			: md5( wp_generate_uuid4() );

		return array(
			'link_id'            => (int) ( $link['id'] ?? 0 ),
			'link_name'          => (string) ( $link['name'] ?? '' ),
			// A regex rule's slug is its pattern, which means nothing in a report.
			'link_slug'          => 'regex' === $mode ? trim( $path, '/' ) : (string) ( $link['slug'] ?? '' ),
			'link_mode'          => $mode,
			'link_category'      => $category,
			'target_url'         => $target_url,
			'target_domain'      => (string) wp_parse_url( $target_url, PHP_URL_HOST ),
			'status'             => $status,
			'short_url'          => esc_url_raw( $base . $path ),
			'host'               => $host,
			'path'               => $path,
			'referrer'           => '' !== $referrer['page'] ? $referrer['page'] : ( '' !== $referrer['source'] ? 'https://' . $referrer['source'] : '' ),
			'user_agent'         => $user_agent,
			'ip'                 => $ip,
			'country'            => $share && is_array( $geo ) ? (string) ( $geo['country'] ?? '' ) : '',
			'visitor_id'         => $visitor_id,
			'numeric_visitor_id' => hexdec( substr( $visitor_id, 0, 7 ) ) . '.' . hexdec( substr( $visitor_id, 7, 7 ) ),
			'event_id'           => wp_generate_uuid4(),
			'time'               => time(),
			'cookies'            => self::analytics_cookies(),
		);
	}

	/**
	 * Only GA4's own cookies, and only so a server event joins the same GA4 user.
	 *
	 * @return array<string, string>
	 */
	private static function analytics_cookies(): array {
		$cookies = array();
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( ! is_string( $name ) || ( '_ga' !== $name && ! str_starts_with( $name, '_ga_' ) ) ) {
				continue;
			}
			$value = $_COOKIE[ $name ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched against a strict pattern before use and never stored.
			if ( is_string( $value ) && strlen( $value ) <= 128 ) {
				$cookies[ $name ] = $value;
			}
		}

		return $cookies;
	}

	/**
	 * The visitor's public IP address. A spoofed proxy header can only skew this
	 * one analytics hit, so the first valid public candidate is used.
	 */
	private static function client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			$candidate = trim( (string) strtok( self::server_value( $key, 512 ), ',' ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$ip = $candidate;
				break;
			}
		}

		/**
		 * Filter the visitor IP shared with analytics tools.
		 *
		 * @param string $ip Visitor IP, or an empty string to share none.
		 */
		return (string) apply_filters( 'gtlm_integration_visitor_ip', $ip ?? '' );
	}

	private static function server_value( string $key, int $limit ): string {
		$value = $_SERVER[ $key ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by each caller; oversized values are rejected whole.
		return is_string( $value ) && strlen( $value ) <= $limit ? wp_unslash( $value ) : '';
	}

	/**
	 * Send one test event for the settings screen, and say what came back.
	 *
	 * GA4 goes to Google's validation server, which checks the payload without
	 * recording it. Every other service has no such endpoint, so its test event
	 * is real and named after this plugin so it can be recognised.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function send_test( string $key ): array {
		$tools    = self::tools();
		$config   = self::config();
		$settings = $config['tools'][ $key ] ?? array();

		if ( ! isset( $tools[ $key ] ) || 'service' !== $tools[ $key ]['type'] || ( 'ga4' === $key && 'browser' === ( $settings['mode'] ?? 'server' ) ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This tool has no server test.', 'gt-link-manager' ),
			);
		}
		if ( empty( $settings['enabled'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Turn this tool on and fill in its required fields first.', 'gt-link-manager' ),
			);
		}

		$slug  = 'gtlm-test';
		$event = self::build_event(
			array(
				'id'        => 0,
				'name'      => 'GT Link Manager test',
				'slug'      => $slug,
				'link_mode' => 'standard',
			),
			home_url( '/' ),
			302,
			null,
			(bool) $config['share_visitor_data']
		);

		$event['short_url'] = GTLM_Settings::get_instance()->link_url( $slug );
		$event['path']      = (string) wp_parse_url( $event['short_url'], PHP_URL_PATH );
		$event['referrer']  = '';

		require_once __DIR__ . '/class-gtlm-integration-services.php';

		$request = GTLM_Integration_Services::request( $key, $settings, $event );
		$request = apply_filters( 'gtlm_integration_request', $request, $key, $event );
		if ( ! is_array( $request ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'A filter cancelled the request.', 'gt-link-manager' ),
			);
		}

		$url      = 'ga4' === $key ? GTLM_Integration_Services::ga4_validation_url( (string) $request['url'] ) : (string) $request['url'];
		$response = wp_safe_remote_request( $url, array_merge( (array) $request['args'], array( 'timeout' => 8 ) ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: error message. */
				'message' => sprintf( __( 'Could not reach the service: %s', 'gt-link-manager' ), $response->get_error_message() ),
			);
		}

		return self::read_test_response(
			$key,
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			! empty( wp_remote_retrieve_header( $response, 'x-plausible-dropped' ) ),
			(bool) $config['share_visitor_data']
		);
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	private static function read_test_response( string $key, int $code, string $body, bool $dropped, bool $sharing ): array {
		$json = json_decode( $body, true );

		if ( 'ga4' === $key && 200 === $code && is_array( $json ) ) {
			$messages = (array) ( $json['validationMessages'] ?? array() );
			if ( ! $messages ) {
				return array(
					'ok'      => true,
					'message' => __( 'Google validated the event with no errors. Google\'s validator does not check the API secret, so confirm clicks arrive in Realtime.', 'gt-link-manager' ),
				);
			}
			return array(
				'ok'      => false,
				/* translators: %s: validation message from Google. */
				'message' => sprintf( __( 'Google rejected the event: %s', 'gt-link-manager' ), sanitize_text_field( (string) ( $messages[0]['description'] ?? '' ) ) ),
			);
		}

		$ok = $code >= 200 && $code < 300;
		if ( 'mixpanel' === $key && is_array( $json ) && isset( $json['status'] ) && 1 !== (int) $json['status'] ) {
			$ok = false;
		}

		// Plausible answers 202 even when it drops an event, and says so only in a header.
		if ( $ok && $dropped && 'plausible' === $key ) {
			return array(
				'ok'      => false,
				'message' => $sharing
					? __( 'Plausible accepted the request but dropped the event, so it will not appear in your reports. Check that the site domain is registered in Plausible, that your IP address is not blocked in its site settings, and that any proxy in front of this site passes on the visitor\'s real address.', 'gt-link-manager' )
					: __( 'Plausible accepted the request but dropped the event, so it will not appear in your reports. With IP address and country off, Plausible sees this server\'s address, and it can drop events from server and hosting addresses. Check that the site domain is registered in Plausible, then turn on IP address and country after checking your privacy policy.', 'gt-link-manager' ),
			);
		}

		if ( $ok ) {
			return array(
				'ok'      => true,
				/* translators: %d: HTTP status code. */
				'message' => sprintf( __( 'The service accepted the test event (HTTP %d). Look for link_click or Link Click on its dashboard.', 'gt-link-manager' ), $code ),
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: 1: HTTP status code, 2: start of the response body. */
				__( 'The service answered HTTP %1$d: %2$s', 'gt-link-manager' ),
				$code,
				mb_substr( sanitize_text_field( $body ), 0, 200 )
			),
		);
	}

	/**
	 * Load the click listener for tools with no server-side API.
	 */
	public static function enqueue_bridge(): void {
		if ( is_user_logged_in() && current_user_can( (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'analytics_exclude' ) ) ) {
			return;
		}

		$settings = GTLM_Settings::get_instance();
		$enabled  = $settings->enabled_integrations();
		$config   = self::config();
		$layer    = (string) ( $config['tools']['gtm']['data_layer'] ?? '' );
		$tools    = array_values( array_intersect( array( 'gtm', 'fathom', 'clarity' ), $enabled ) );
		if ( in_array( 'ga4_browser', $enabled, true ) ) {
			$tools[] = 'ga4';
		}

		/**
		 * Filter whether the click listener loads on this page.
		 *
		 * @param bool $load Whether to load it.
		 */
		if ( ! $tools || ! (bool) apply_filters( 'gtlm_integrations_load_bridge', true ) ) {
			return;
		}

		wp_enqueue_script(
			'gtlm-integrations',
			GTLM_URL . 'assets/js/integrations.js',
			array(),
			GTLM_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			'gtlm-integrations',
			'window.gtlmIntegrations=' . wp_json_encode(
				array(
					'tools'     => $tools,
					'base'      => (string) wp_parse_url( home_url( '/' . $settings->prefix() . '/' ), PHP_URL_PATH ),
					'dataLayer' => '' !== $layer ? $layer : 'dataLayer',
				)
			) . ';',
			'before'
		);
	}
}
