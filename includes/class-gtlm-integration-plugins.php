<?php
/**
 * Adapters for analytics plugins running on this WordPress site.
 *
 * No request leaves the site here: each adapter calls the other plugin's own
 * tracker in-process. Only WP Statistics, Burst and SlimStat document the entry
 * point used, so every adapter checks that what it needs still exists and the
 * dispatcher isolates failures.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Integration_Plugins {
	private const VIRTUAL_PREFIX = 'gtlm_link_';
	private const EVENT          = 'gtlm_link_click';

	/**
	 * Hooks that have to be in place on every request, not only on a redirect.
	 *
	 * @param array<int, string> $enabled Enabled tool keys.
	 */
	public static function register_hooks( array $enabled ): void {
		if ( in_array( 'independent_analytics', $enabled, true ) ) {
			add_filter( 'iawp_override_virtual_page_details', array( self::class, 'iawp_page_details' ), 10, 2 );
		}
		if ( in_array( 'wp_statistics', $enabled, true ) ) {
			add_filter( 'wp_statistics_custom_events', array( self::class, 'wp_statistics_events' ) );
		}
	}

	/** Whether the plugin behind a tool is active and exposes what its adapter needs. */
	public static function available( string $tool ): bool {
		switch ( $tool ) {
			case 'independent_analytics':
				return class_exists( '\IAWP\Views\View' ) && class_exists( '\IAWP\Models\Visitor' );
			case 'koko_analytics':
				return function_exists( 'koko_analytics_track_pageview' );
			case 'wp_statistics':
				return function_exists( 'wp_statistics_event' );
			case 'burst_statistics':
				return function_exists( '\Burst\burst_loader' );
			case 'slimstat':
				return class_exists( 'wp_slimstat' ) && method_exists( 'wp_slimstat', 'slimtrack_server' );
			case 'statify':
				return class_exists( 'Statify' );
			case 'matomo_plugin':
				return class_exists( '\WpMatomo\AjaxTracker' ) && class_exists( '\WpMatomo\Settings' );
		}

		return false;
	}

	/**
	 * Record one click in a plugin's own reports.
	 *
	 * @param string               $tool  Tool key.
	 * @param array<string, mixed> $event Click event.
	 */
	public static function record( string $tool, array $event ): void {
		if ( ! self::available( $tool ) ) {
			return;
		}

		switch ( $tool ) {
			case 'independent_analytics':
				self::independent_analytics( $event );
				break;
			case 'koko_analytics':
				self::koko_analytics( $event );
				break;
			case 'wp_statistics':
				self::wp_statistics( $event );
				break;
			case 'burst_statistics':
				self::burst_statistics( $event );
				break;
			case 'slimstat':
				self::slimstat();
				break;
			case 'statify':
				self::statify( $event );
				break;
			case 'matomo_plugin':
				self::matomo_plugin( $event );
				break;
		}
	}

	/**
	 * Independent Analytics only records views; its click tracking is Pro and
	 * browser-side. A virtual page is the route it offers site owners for
	 * anything that is not a post, and it keeps the visitor's journey intact.
	 *
	 * @param array<string, mixed> $event Click event.
	 */
	private static function independent_analytics( array $event ): void {
		if ( class_exists( '\IAWP\Migrations\Migrations' ) && \IAWP\Migrations\Migrations::is_migrating() ) {
			return;
		}
		if ( class_exists( '\IAWP\Utils\Request' ) && \IAWP\Utils\Request::is_ip_address_blocked() ) {
			return;
		}

		new \IAWP\Views\View(
			array(
				'resource'        => 'virtual_page',
				'virtual_page_id' => self::VIRTUAL_PREFIX . (int) $event['link_id'],
				'page'            => 1,
			),
			(string) $event['referrer'],
			\IAWP\Models\Visitor::fetch_current_visitor(),
			null
		);
	}

	/**
	 * Name a link's virtual page. Independent Analytics rebuilds this cache on its
	 * own schedule, so the answer has to come from the link, not from the click.
	 *
	 * @param mixed $details         Cached page details.
	 * @param mixed $virtual_page_id Virtual page ID.
	 * @return mixed
	 */
	public static function iawp_page_details( $details, $virtual_page_id ) {
		if ( ! is_array( $details ) || ! is_string( $virtual_page_id ) || ! str_starts_with( $virtual_page_id, self::VIRTUAL_PREFIX ) ) {
			return $details;
		}

		$link = ( new GTLM_DB() )->get_link_by_id( (int) substr( $virtual_page_id, strlen( self::VIRTUAL_PREFIX ) ) );
		if ( ! is_array( $link ) ) {
			return $details;
		}

		$mode = (string) ( $link['link_mode'] ?? 'standard' );

		$details['cached_title']      = (string) $link['name'];
		$details['cached_url']        = GTLM_Settings::get_instance()->link_url( (string) $link['slug'], $mode );
		$details['cached_type']       = 'gtlm_link';
		$details['cached_type_label'] = 'GT Link';

		return $details;
	}

	/**
	 * Koko's custom events are a Pro feature, but its pageview function is public.
	 *
	 * @param array<string, mixed> $event Click event.
	 */
	private static function koko_analytics( array $event ): void {
		// Koko's cookie mode records what a visitor has seen. Nothing can be written
		// back after the response, so a returning visitor reads as seen before.
		$seen     = isset( $_COOKIE['_koko_analytics_pages_viewed'] );
		$external = (string) wp_parse_url( (string) $event['referrer'], PHP_URL_HOST ) !== (string) $event['host'];

		koko_analytics_track_pageview(
			mb_substr( (string) $event['path'], 0, 255 ),
			0,
			! $seen,
			true,
			$external ? mb_substr( (string) $event['referrer'], 0, 255 ) : ''
		);
	}

	/**
	 * Register the custom event WP Statistics will accept.
	 *
	 * @param mixed $events Registered events.
	 * @return mixed
	 */
	public static function wp_statistics_events( $events ) {
		if ( ! is_array( $events ) ) {
			return $events;
		}

		$events[] = array(
			'name'         => __( 'GT Link click', 'gt-link-manager' ),
			'machine_name' => self::EVENT,
			'status'       => true,
		);

		return $events;
	}

	/**
	 * @param array<string, mixed> $event Click event.
	 */
	private static function wp_statistics( array $event ): void {
		wp_statistics_event(
			self::EVENT,
			array(
				'link_name'     => (string) $event['link_name'],
				'link_slug'     => (string) $event['link_slug'],
				'link_category' => (string) $event['link_category'],
				'target_domain' => (string) $event['target_domain'],
				'referrer'      => (string) $event['referrer'],
			)
		);
	}

	/**
	 * Burst Free drops event properties, so the event name has to carry the link
	 * for a report to tell links apart.
	 *
	 * @param array<string, mixed> $event Click event.
	 */
	private static function burst_statistics( array $event ): void {
		// Burst's cookieless mode needs a PHP session, which cannot start once the
		// redirect headers have gone. Without this cookie the click has nowhere to go.
		if ( empty( $_COOKIE['burst_uid'] ) ) {
			return;
		}

		do_action(
			'burst_event',
			'link_click_' . (string) $event['link_slug'],
			array(
				'link_name'     => (string) $event['link_name'],
				'target_domain' => (string) $event['target_domain'],
			)
		);
	}

	/**
	 * SlimStat documents this entry point for redirect handlers. It reads the
	 * current request itself, so the short link's path and referrer come through.
	 */
	private static function slimstat(): void {
		\wp_slimstat::slimtrack_server();
	}

	/**
	 * Statify only tracks from template_redirect, or from a script when its
	 * JavaScript mode is on. Neither runs on a redirect, and track() is protected.
	 *
	 * @param array<string, mixed> $event Click event.
	 */
	private static function statify( array $event ): void {
		$recorder = new class() extends Statify {
			/** @param array<string, mixed> $event Click event. */
			public static function record( array $event ): void {
				self::track( (string) $event['referrer'], (string) $event['path'] );
			}
		};

		$recorder::record( $event );
	}

	/**
	 * @param array<string, mixed> $event Click event.
	 */
	private static function matomo_plugin( array $event ): void {
		$settings = new \WpMatomo\Settings();
		if ( ! $settings->is_tracking_enabled() ) {
			return;
		}

		$tracker = new \WpMatomo\AjaxTracker( $settings );
		$tracker->setUrl( (string) $event['short_url'] );
		$tracker->setUrlReferrer( (string) $event['referrer'] );
		$tracker->setUserAgent( (string) $event['user_agent'] );
		$tracker->doTrackEvent( 'Redirect', 'Link Click', '' !== (string) $event['link_name'] ? (string) $event['link_name'] : (string) $event['link_slug'] );
	}
}
