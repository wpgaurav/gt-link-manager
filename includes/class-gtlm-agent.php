<?php
/**
 * Commands for AI agents, such as those connected through Site Agent's MCP server.
 *
 * Every link and category command runs through the plugin's own REST routes, so validation,
 * reserved paths, duplicate slugs and permissions behave exactly as they do for the REST API.
 * Permanent deletion and analytics changes are not available here; they stay in wp-admin.
 *
 * @package GTLinkManager
 */

defined( 'ABSPATH' ) || exit;

/** Agent API: gtlm_agent( 'command', $input ) returns array( 'ok' => bool, ... ) and never throws. */
final class GTLM_Agent {
	const VERSION = 1;

	/**
	 * Run one command.
	 *
	 * @param string               $command Command name.
	 * @param array<string, mixed> $input   Command input.
	 * @return array<string, mixed>
	 */
	public static function call( string $command, array $input = array() ): array {
		$routes = array(
			'context'             => null,
			'links.list'          => array( 'GET', '/links', 'list' ),
			'links.get'           => null,
			'links.create'        => array( 'POST', '/links', 'link' ),
			'links.update'        => null,
			'links.trash'         => null,
			'links.restore'       => null,
			'links.set_active'    => null,
			'links.bulk_category' => array( 'POST', '/links/bulk-category', 'result' ),
			'categories.list'     => array( 'GET', '/categories', 'categories' ),
			'categories.create'   => array( 'POST', '/categories', 'category' ),
			'analytics.status'    => array( 'GET', '/analytics/status', 'status' ),
			'analytics.summary'   => array( 'GET', '/analytics/summary', 'summary' ),
			'analytics.breakdown' => array( 'GET', '/analytics/breakdown', 'breakdown' ),
		);
		if ( ! array_key_exists( $command, $routes ) ) {
			return array(
				'ok'    => false,
				'error' => 'Unknown command: ' . $command,
			);
		}
		try {
			switch ( $command ) {
				case 'context':
					return array( 'ok' => true ) + self::context();
				case 'links.get':
					return array(
						'ok'   => true,
						'link' => self::link( self::rest( 'GET', '/links/' . self::link_id( $input ) )['data'] ),
					);
				case 'links.update':
					$patch = is_array( $input['patch'] ?? null ) ? $input['patch'] : array();
					if ( ! $patch ) {
						throw new InvalidArgumentException( 'Pass the fields to change in patch.' );
					}
					return array(
						'ok'   => true,
						'link' => self::link( self::rest( 'PATCH', '/links/' . self::link_id( $input ), $patch )['data'] ),
					);
				case 'links.trash':
					return array(
						'ok'     => true,
						'result' => self::rest( 'DELETE', '/links/' . self::link_id( $input ) )['data'],
					);
				case 'links.restore':
					return array(
						'ok'   => true,
						'link' => self::link( self::rest( 'POST', '/links/' . self::link_id( $input ) . '/restore' )['data'] ),
					);
				case 'links.set_active':
					return array(
						'ok'   => true,
						'link' => self::link( self::rest( 'POST', '/links/' . self::link_id( $input ) . '/toggle-active', array( 'is_active' => ! empty( $input['is_active'] ) ) )['data'] ),
					);
			}
			list( $method, $route, $key ) = $routes[ $command ];
			$params                       = 'links.create' === $command || 'categories.create' === $command
				? ( is_array( $input[ 'links.create' === $command ? 'link' : 'category' ] ?? null ) ? $input[ 'links.create' === $command ? 'link' : 'category' ] : array() )
				: $input;
			$result                       = self::rest( $method, $route, $params );
			$out                          = array(
				'ok' => true,
				$key => 'link' === $key ? self::link( $result['data'] ) : $result['data'],
			);
			if ( isset( $result['total'] ) ) {
				$out['total'] = $result['total'];
			}
			return $out;
		} catch ( \Throwable $error ) {
			return array(
				'ok'    => false,
				'error' => $error->getMessage(),
			);
		}
	}

	/**
	 * Dispatch to the plugin's own REST route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	private static function rest( string $method, string $route, array $params = array() ): array {
		$request = new WP_REST_Request( $method, '/gt-link-manager/v1' . $route );
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		if ( $response->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned to the agent as JSON data, never printed as HTML.
			throw new RuntimeException( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Request failed.' );
		}
		$out     = array( 'data' => $data );
		$headers = $response->get_headers();
		if ( isset( $headers['X-WP-Total'] ) ) {
			$out['total'] = (int) $headers['X-WP-Total'];
		}
		return $out;
	}

	/**
	 * One link in the same shape as list results: url is the branded short link and
	 * target_url the destination. The single-link REST response uses url for the destination.
	 *
	 * @param mixed $link REST link data.
	 * @return mixed
	 */
	private static function link( $link ) {
		if ( ! is_array( $link ) || array_key_exists( 'target_url', $link ) || ! isset( $link['slug'] ) ) {
			return $link;
		}
		$mode               = (string) ( $link['link_mode'] ?? 'standard' );
		$prefix             = trim( GTLM_Settings::get_instance()->prefix(), '/' );
		$link['target_url'] = (string) ( $link['url'] ?? '' );
		$link['url']        = 'direct' === $mode ? home_url( '/' . $link['slug'] ) : ( 'regex' === $mode ? '' : home_url( '/' . $prefix . '/' . $link['slug'] ) );
		return $link;
	}

	/** A link ID from id, or from slug (the path after the link prefix). */
	private static function link_id( array $input ): int {
		$id = absint( $input['id'] ?? 0 );
		if ( $id ) {
			return $id;
		}
		$slug = is_string( $input['slug'] ?? null ) ? trim( $input['slug'], '/ ' ) : '';
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Pass a link id or slug.' );
		}
		$prefix = trim( GTLM_Settings::get_instance()->prefix(), '/' );
		if ( '' !== $prefix && 0 === strpos( $slug, $prefix . '/' ) ) {
			$slug = substr( $slug, strlen( $prefix ) + 1 );
		}
		$link = ( new GTLM_DB() )->get_link_by_exact_slug( $slug );
		if ( ! $link ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned to the agent as JSON data, never printed as HTML.
			throw new RuntimeException( 'No link with slug ' . $slug . '.' );
		}
		return (int) $link['id'];
	}

	/** @return array<string, mixed> */
	private static function context(): array {
		if ( ! current_user_can( (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'rest_api' ) ) ) {
			throw new RuntimeException( 'This WordPress user cannot manage links.' );
		}
		$settings = GTLM_Settings::get_instance()->all();
		$db       = new GTLM_DB();
		return array(
			'api_version'    => self::VERSION,
			'plugin_version' => GTLM_VERSION,
			'site_url'       => home_url( '/' ),
			'link_base'      => home_url( '/' . trim( (string) $settings['base_prefix'], '/' ) . '/' ),
			'defaults'       => array(
				'redirect_type' => (int) $settings['default_redirect_type'],
				'rel'           => array_values( (array) $settings['default_rel'] ),
				'noindex'       => ! empty( $settings['default_noindex'] ),
			),
			'click_tracking' => ! empty( $settings['enable_click_tracking'] ),
			'geo_targeting'  => ! empty( $settings['enable_geo_targeting'] ),
			'counts'         => array(
				'active'   => $db->count_links( array( 'status' => 'active' ) ),
				'inactive' => $db->count_links( array( 'status' => 'inactive' ) ),
				'trash'    => $db->count_links( array( 'trashed' => true ) ),
			),
			'categories'     => count( $db->get_categories() ),
		);
	}
}
