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
			'links.list'          => array( 'GET', '/links', 'links' ),
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

	const SITE_AGENT_URL = 'https://gauravtiwari.org/product/site-agent/';
	const DISMISS_META   = 'gtlm_site_agent_card_dismissed';

	/**
	 * Site Agent's state on this site: missing, too old for plugin skills, or ready.
	 *
	 * @return array{state: string, url: string}
	 */
	public static function site_agent(): array {
		if ( ! defined( 'SITE_AGENT_VERSION' ) ) {
			return array(
				'state' => 'missing',
				'url'   => self::SITE_AGENT_URL,
			);
		}
		if ( version_compare( (string) SITE_AGENT_VERSION, '0.4.0', '<' ) ) {
			return array(
				'state' => 'old',
				'url'   => self::SITE_AGENT_URL,
			);
		}
		return array(
			'state' => 'ready',
			'url'   => admin_url( 'tools.php?page=site-agent' ),
		);
	}

	const PAGE = 'gtlm-links-ai-agents';

	/** The "AI agents" page in the GT Links menu. */
	public static function menu(): void {
		$capability = (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'menu' );
		add_submenu_page( 'gtlm-links', __( 'AI agents', 'gt-link-manager' ), __( 'AI agents', 'gt-link-manager' ), $capability, self::PAGE, array( self::class, 'page' ) );
	}

	/** Site Agent's switches, when it is installed and recent enough to report them. */
	private static function site_agent_settings(): array {
		if ( ! class_exists( 'SiteAgent\\Config' ) ) {
			return array();
		}
		$config = \SiteAgent\Config::get();
		return array(
			'enabled' => ! empty( $config['enabled'] ),
			'php'     => ! empty( $config['php_execute'] ),
			'oauth'   => ! empty( $config['oauth'] ),
		);
	}

	/** Show what an agent can do with links and how to connect one, before sending anyone elsewhere. */
	public static function page(): void {
		$agent    = self::site_agent();
		$settings = self::site_agent_settings();
		$ready    = 'ready' === $agent['state'] && ! empty( $settings['enabled'] ) && ! empty( $settings['php'] );
		echo '<div class="wrap gtlm-ai-agents"><h1>' . esc_html__( 'AI agents', 'gt-link-manager' ) . '</h1>';
		echo '<p class="gtlm-ai-lead">' . esc_html__( 'Manage your links by asking an AI agent such as Claude, ChatGPT or Cursor. The agent works on this site through Site Agent, a free plugin, with the same checks as the dashboard.', 'gt-link-manager' ) . '</p>';

		if ( $ready ) {
			$status = array( 'success', __( 'Ready. Site Agent is active with PHP execution on, so connected agents already have the GT Link Manager skill.', 'gt-link-manager' ) );
		} elseif ( 'ready' === $agent['state'] ) {
			$status = array( 'warning', __( 'Site Agent is installed. Turn on Site Agent and PHP execution in its settings, then connect your agent.', 'gt-link-manager' ) );
		} elseif ( 'old' === $agent['state'] ) {
			$status = array( 'warning', __( 'Site Agent is installed but older than 0.4. Update it, free, so agents get the GT Link Manager skill.', 'gt-link-manager' ) );
		} else {
			$status = array( 'info', __( 'Site Agent is not installed yet. It is free.', 'gt-link-manager' ) );
		}
		echo '<div class="notice notice-' . esc_attr( $status[0] ) . ' inline"><p>' . esc_html( $status[1] ) . '</p></div>';

		echo '<div class="gtlm-ai-grid"><section class="gtlm-ai-card"><h2>' . esc_html__( 'What you can ask', 'gt-link-manager' ) . '</h2><ul>';
		foreach ( array(
			__( '“Create a /go/ link for this affiliate URL, nofollow and sponsored.”', 'gt-link-manager' ),
			__( '“Do we already have a link for Hostinger? Show its clicks.”', 'gt-link-manager' ),
			__( '“Send India visitors to Amazon.in and everyone else to Amazon.com.”', 'gt-link-manager' ),
			__( '“Move all hosting links into a Hosting category.”', 'gt-link-manager' ),
			__( '“Which links got the most clicks last month?”', 'gt-link-manager' ),
		) as $example ) {
			echo '<li>' . esc_html( $example ) . '</li>';
		}
		echo '</ul></section>';

		echo '<section class="gtlm-ai-card"><h2>' . esc_html__( 'How it connects', 'gt-link-manager' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Install Site Agent on this site. It is free.', 'gt-link-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'In Tools → Site Agent, turn on Site Agent and PHP execution. OAuth connections are on by default.', 'gt-link-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Add the site’s MCP endpoint to your agent. It opens a WordPress sign-in where you approve it, with no password to copy.', 'gt-link-manager' ) . '</li>';
		echo '</ol>';
		if ( 'missing' !== $agent['state'] ) {
			echo '<p><strong>' . esc_html__( 'Endpoint', 'gt-link-manager' ) . ':</strong> <code>' . esc_html( rest_url( 'site-agent/v1/mcp' ) ) . '</code></p>';
		}
		echo '</section>';

		echo '<section class="gtlm-ai-card"><h2>' . esc_html__( 'What stays in your control', 'gt-link-manager' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'The agent asks before changing where a live link goes, or deactivating or trashing one.', 'gt-link-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Permanent deletion and analytics settings stay in this dashboard.', 'gt-link-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'It acts as the WordPress user who approved it, with that user’s permissions.', 'gt-link-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Revoke an agent any time in Tools → Site Agent.', 'gt-link-manager' ) . '</li>';
		echo '</ul></section></div>';

		echo '<p class="gtlm-ai-actions">';
		if ( 'ready' === $agent['state'] ) {
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'tools.php?page=site-agent' ) ) . '">' . esc_html__( 'Open Site Agent settings', 'gt-link-manager' ) . '</a> ';
		} else {
			echo '<a class="button button-primary" href="' . esc_url( self::SITE_AGENT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( 'old' === $agent['state'] ? __( 'Get the latest Site Agent (free)', 'gt-link-manager' ) : __( 'Get Site Agent (free)', 'gt-link-manager' ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span></a> ';
		}
		if ( current_user_can( 'manage_options' ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=gtlm-links-settings' ) ) . '">' . esc_html__( 'Other AI tools: REST API', 'gt-link-manager' ) . '</a>';
		}
		echo '</p></div>';
	}

	/** A dismissible Site Agent card on the All Links screen, pointing to the AI agents page. */
	public static function card(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'toplevel_page_gtlm-links' !== $screen->id || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$text    = 'ready' === self::site_agent()['state']
			? __( 'AI agents connected through Site Agent can manage your links.', 'gt-link-manager' )
			: __( 'Manage your links by asking an AI agent such as Claude or ChatGPT.', 'gt-link-manager' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=gtlm_dismiss_site_agent' ), 'gtlm_dismiss_site_agent' );
		echo '<div class="notice notice-info gtlm-site-agent-card"><p>' . esc_html( $text ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'See how it works', 'gt-link-manager' ) . '</a> <a href="' . esc_url( $dismiss ) . '" class="gtlm-site-agent-dismiss">' . esc_html__( 'Dismiss', 'gt-link-manager' ) . '</a></p></div>';
	}

	/** Remember the dismissal for this user. */
	public static function dismiss(): void {
		check_admin_referer( 'gtlm_dismiss_site_agent' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gtlm-links' ) );
		exit;
	}
}
