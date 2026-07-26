<?php
/**
 * Admin actions and routing.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Admin {
	private GTLM_DB $db;

	private GTLM_Settings $settings;

	private GTLM_Import $importer;

	private GTLM_Admin_Pages $pages;

	public static function init( GTLM_DB $db, GTLM_Settings $settings ): void {
		$instance = new self( $db, $settings );
		$instance->hooks();
	}

	private function __construct( GTLM_DB $db, GTLM_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->importer = new GTLM_Import( $db, $settings );
		$this->pages    = new GTLM_Admin_Pages( $db, $settings, $this->importer );
	}

	private function hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gtlm_quick_edit', array( $this, 'ajax_quick_edit' ) );
		add_action( 'wp_ajax_gtlm_geo_check', array( $this, 'ajax_geo_check' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_content' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_new_link' ), 80 );
		add_filter( 'dashboard_glance_items', array( $this, 'dashboard_glance_items' ) );
		add_filter( 'default_hidden_columns', array( $this, 'default_hidden_columns' ), 10, 2 );
	}

	/**
	 * @param mixed $status
	 * @param mixed $option
	 * @param mixed $value
	 * @return mixed
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( 'gtlm_links_per_page' === $option ) {
			return max( 1, min( 200, absint( $value ) ) );
		}

		return $status;
	}

	public function register_menus(): void {
		$capability = $this->links_capability( 'menu' );

		$hook = add_menu_page(
			esc_html__( 'GT Links', 'gt-link-manager' ),
			esc_html__( 'GT Links', 'gt-link-manager' ),
			$capability,
			'gtlm-links',
			array( $this->pages, 'render_links_page' ),
			'dashicons-admin-links',
			26
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'add_links_screen_options' ) );
		}

		add_submenu_page( 'gtlm-links', esc_html__( 'All Links', 'gt-link-manager' ), esc_html__( 'All Links', 'gt-link-manager' ), $capability, 'gtlm-links', array( $this->pages, 'render_links_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Add New', 'gt-link-manager' ), esc_html__( 'Add New', 'gt-link-manager' ), $capability, 'gtlm-links-edit', array( $this->pages, 'render_edit_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Categories', 'gt-link-manager' ), esc_html__( 'Categories', 'gt-link-manager' ), $capability, 'gtlm-links-categories', array( $this->pages, 'render_categories_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Settings', 'gt-link-manager' ), esc_html__( 'Settings', 'gt-link-manager' ), 'manage_options', 'gtlm-links-settings', array( $this->pages, 'render_settings_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Import / Export', 'gt-link-manager' ), esc_html__( 'Import / Export', 'gt-link-manager' ), $capability, 'gtlm-links-import-export', array( $this->pages, 'render_import_export_page' ) );
	}

	public function add_links_screen_options(): void {
		require_once GTLM_PATH . 'includes/class-gt-link-list-table.php';

		$screen = get_current_screen();
		if ( $screen ) {
			add_filter( 'manage_' . $screen->id . '_columns', array( 'GTLM_List_Table', 'define_columns' ) );
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => esc_html__( 'Links per page', 'gt-link-manager' ),
				'default' => 20,
				'option'  => 'gtlm_links_per_page',
			)
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( 'gtlm-links', 'gtlm-links-edit', 'gtlm-links-categories', 'gtlm-links-settings', 'gtlm-links-import-export' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'gt-link-manager-admin',
			GTLM_URL . 'assets/css/admin.css',
			array(),
			GTLM_VERSION
		);

		wp_enqueue_script(
			'gt-link-manager-admin',
			GTLM_URL . 'assets/js/admin.js',
			array(),
			GTLM_VERSION,
			true
		);

		$categories_data = array();
		if ( 'gtlm-links' === $page ) {
			$categories_data = wp_cache_get( 'gtlm_admin_categories', 'gtlm_links' );
			if ( false === $categories_data ) {
				$categories_data = array();
				foreach ( $this->db->get_categories() as $cat ) {
					$categories_data[] = array(
						'id'   => (int) $cat['id'],
						'name' => (string) $cat['name'],
					);
				}
				wp_cache_set( 'gtlm_admin_categories', $categories_data, 'gtlm_links', 3600 );
			}
		}

		wp_localize_script(
			'gt-link-manager-admin',
			'gtlmAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'quickEditNonce'  => wp_create_nonce( 'gtlm_quick_edit' ),
				'prefix'          => $this->settings->prefix(),
				'advancedEnabled' => ! empty( $this->settings->all()['enable_advanced_redirects'] ),
				'categories'      => $categories_data,
				'highlight'       => isset( $_GET['highlight'] ) ? absint( $_GET['highlight'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// The rule preview expands the EU group client-side, so it needs
				// the same membership list the server matches against.
				'euCountries'     => array_values( (array) ( GTLM_Geo::groups()['EU'] ?? array() ) ),
				'i18n'            => array(
					'saved'                 => __( 'Saved', 'gt-link-manager' ),
					'saveFailed'            => __( 'Save failed', 'gt-link-manager' ),
					'copied'                => __( 'Copied', 'gt-link-manager' ),
					'copyUrl'               => __( 'Copy URL', 'gt-link-manager' ),
					'geoNoCountries'        => __( 'No countries selected yet — this rule will be ignored.', 'gt-link-manager' ),
					'geoRemoveCountry'      => __( 'Remove this country', 'gt-link-manager' ),
					/* translators: %s: country name */
					'geoRemoveNamed'        => __( 'Remove %s', 'gt-link-manager' ),
					'geoShadowed'           => __( 'Listed more than once, so only the highest rule applies:', 'gt-link-manager' ),
					'geoMissingUrl'         => __( 'A rule has countries but no destination URL, so it will be discarded on save.', 'gt-link-manager' ),
					'geoRule'               => __( 'rule', 'gt-link-manager' ),
					'geo404'                => __( '404 — blocked', 'gt-link-manager' ),
					'geoMainUrl'            => __( 'the main Destination URL', 'gt-link-manager' ),
					'geoChecking'           => __( 'Checking…', 'gt-link-manager' ),
					'geoVia'                => __( 'via', 'gt-link-manager' ),
					'geoNone'               => __( 'No country on this request', 'gt-link-manager' ),
					'geoDisabled'           => __( 'Geolocation is currently disabled, so links ignore their rules. Tick "Enable Geolocation" and save.', 'gt-link-manager' ),
					'geoSource'             => __( 'Source', 'gt-link-manager' ),
					'geoVariable'           => __( 'Request variable', 'gt-link-manager' ),
					'geoValue'              => __( 'Value', 'gt-link-manager' ),
					'geoUnusable'           => __( 'not a usable country code', 'gt-link-manager' ),
					'geoNoProxy'            => __( 'nothing is proxying this site, so none is expected here', 'gt-link-manager' ),
					/* translators: %s: comma-separated proxy names */
					'geoProxyNoCountry'     => __( 'in front: %s — but it sent no country header', 'gt-link-manager' ),
					'geoSelfTestPass'       => __( 'Self-test passed', 'gt-link-manager' ),
					'geoSelfTestFail'       => __( 'Self-test inconclusive', 'gt-link-manager' ),
					/* translators: 1: country code, 2: header name */
					'geoSelfTestOk'         => __( 'sent %1$s as %2$s to this site and the plugin detected %1$s. Detection works; it is only waiting on a CDN to supply real visitor countries.', 'gt-link-manager' ),
					/* translators: 1: country code sent, 2: country code detected */
					'geoSelfTestMismatch'   => __( 'sent %1$s but the plugin read %2$s.', 'gt-link-manager' ),
					/* translators: 1: country code sent, 2: header name, 3: country code the CDN substituted */
					'geoSelfTestOverridden' => __( 'sent %1$s as %2$s, and your CDN replaced it with %3$s. Detection works, and the country header cannot be forged by a visitor.', 'gt-link-manager' ),
					'geoUsable'             => __( 'valid in a rule', 'gt-link-manager' ),
					'geoNotUsable'          => __( 'is not a country code you can target', 'gt-link-manager' ),
				),
			)
		);
	}

	public function ajax_quick_edit(): void {
		if ( ! current_user_can( $this->links_capability( 'quick_edit' ) ) ) {
			wp_send_json_error();
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['nonce'] ) ), 'gtlm_quick_edit' ) ) {
			wp_send_json_error();
		}

		$link_id       = absint( $_POST['link_id'] ?? 0 );
		$url           = esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) );
		$redirect_type = absint( $_POST['redirect_type'] ?? 301 );

		if ( $link_id <= 0 || '' === $url || ! in_array( $redirect_type, array( 301, 302, 307 ), true ) ) {
			wp_send_json_error();
		}

		$link = $this->db->get_link_by_id( $link_id );
		if ( null === $link ) {
			wp_send_json_error();
		}

		$updates = array(
			'url'           => $url,
			'redirect_type' => $redirect_type,
		);

		if ( isset( $_POST['slug'] ) ) {
			$updates['slug'] = sanitize_title( (string) wp_unslash( $_POST['slug'] ) );
			if ( '' === $updates['slug'] ) {
				wp_send_json_error();
			}
		}

		if ( isset( $_POST['rel'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_rel_from_post().
			$updates['rel'] = $this->sanitize_rel_from_post( wp_unslash( $_POST['rel'] ) );
		}

		if ( isset( $_POST['category_id'] ) ) {
			$updates['category_id'] = absint( $_POST['category_id'] );
		}

		if ( isset( $_POST['is_active'] ) ) {
			$updates['is_active'] = absint( $_POST['is_active'] ) ? 1 : 0;
		}

		$ok = $this->db->update_link(
			$link_id,
			array_merge( $link, $updates )
		);

		if ( ! $ok ) {
			wp_send_json_error();
		}

		$updated_link = $this->db->get_link_by_id( $link_id );

		wp_send_json_success(
			array(
				'url'           => (string) ( $updated_link['url'] ?? $url ),
				'redirect_type' => (int) ( $updated_link['redirect_type'] ?? $redirect_type ),
				'slug'          => (string) ( $updated_link['slug'] ?? '' ),
				'rel'           => (string) ( $updated_link['rel'] ?? '' ),
				'category_id'   => (int) ( $updated_link['category_id'] ?? 0 ),
				'is_active'     => (int) ( $updated_link['is_active'] ?? 1 ),
			)
		);
	}

	/**
	 * Add a suggested privacy policy section to the WordPress Privacy Guide.
	 *
	 * Geolocation is the only part of the plugin that touches visitor data, and
	 * it does so without storing anything — worth stating explicitly, because
	 * "geolocation" reads as invasive when it usually isn't here.
	 */
	public function register_privacy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content =
			'<p>' . esc_html__( 'GT Link Manager stores the short links you create. It does not create user accounts, set cookies, or add tracking scripts.', 'gt-link-manager' ) . '</p>' .
			'<h3>' . esc_html__( 'Redirects', 'gt-link-manager' ) . '</h3>' .
			'<p>' . esc_html__( 'When a visitor follows a short link, the plugin looks up the destination and issues an HTTP redirect. It does not log the request, the visitor\'s IP address, or the referring page.', 'gt-link-manager' ) . '</p>' .
			'<h3>' . esc_html__( 'Geolocation targeting', 'gt-link-manager' ) . '</h3>' .
			'<p>' . esc_html__( 'If geolocation targeting is enabled, the plugin reads a two-letter country code from a request header that your CDN or web server has already added to the request — for example Cloudflare\'s CF-IPCountry header. The plugin never reads or processes the visitor\'s IP address, never contacts an external geolocation service, and never stores or transmits the country. The value exists only for the duration of that single request and is used solely to choose which URL to redirect to.', 'gt-link-manager' ) . '</p>' .
			'<p>' . esc_html__( 'Because the country is derived from data your CDN already collects, the relevant disclosure usually belongs with your CDN provider rather than with this plugin. Check your CDN\'s own privacy documentation.', 'gt-link-manager' ) . '</p>' .
			'<p>' . esc_html__( 'Note: if you have added click tracking or analytics through the plugin\'s gtlm_before_redirect hook, that code receives the detected country and may store or transmit it. Any such storage is the responsibility of the integration you added, not of this plugin.', 'gt-link-manager' ) . '</p>';

		wp_add_privacy_policy_content( __( 'GT Link Manager', 'gt-link-manager' ), $content );
	}

	/**
	 * Report what geolocation detection sees, for the settings screen.
	 *
	 * Two separate questions, answered separately: what the current request
	 * actually carries (does the CDN forward a country at all?), and what a
	 * given country code would normalize to (does a rule target work?).
	 */
	public function ajax_geo_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'gt-link-manager' ) ), 403 );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['nonce'] ) ), 'gtlm_geo_check' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'gt-link-manager' ) ), 400 );
		}

		GTLM_Geo::reset();

		$rows = array();
		foreach ( GTLM_Geo::sources() as $key => $label ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$raw = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : '';

			$rows[] = array(
				'source'     => $label,
				'key'        => $key,
				'present'    => '' !== $raw,
				'raw'        => '' !== $raw ? sanitize_text_field( mb_substr( $raw, 0, 40 ) ) : '',
				'normalized' => GTLM_Geo::normalize_code( $raw ),
			);
		}

		$country = GTLM_Geo::country();
		$source  = GTLM_Geo::source();

		$simulate = isset( $_POST['simulate'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['simulate'] ) ) : '';
		$sim      = null;
		if ( '' !== $simulate ) {
			$code = GTLM_Geo::normalize_code( $simulate );
			$sim  = array(
				'input' => strtoupper( $simulate ),
				'code'  => $code,
				'valid' => '' !== $code && GTLM_Geo::is_valid_target( $code ),
				'label' => '' !== $code ? GTLM_Geo::label( $code ) : '',
			);
		}

		// Detection is memoized per request, and the loopback below resets it
		// on its own request — but this one must be restored for the response.
		$proxies = GTLM_Geo::proxy_signals();
		$loop    = $this->run_geo_loopback( '' !== $simulate ? strtoupper( $simulate ) : 'SE' );

		wp_send_json_success(
			array(
				'enabled'  => GTLM_Geo::is_enabled(),
				'country'  => $country,
				'label'    => '' !== $country ? GTLM_Geo::label( $country ) : '',
				'source'   => $source,
				'method'   => (string) $this->settings->all()['geo_detection_method'],
				'sources'  => $rows,
				'proxies'  => $proxies,
				'simulate' => $sim,
				'loopback' => $loop,
			)
		);
	}

	/**
	 * Send the site a request carrying a country header and report what the
	 * plugin detected at the other end.
	 *
	 * This is the check that works without a CDN: it proves the detection
	 * pipeline itself functions on this server, which "no country on this
	 * request" alone can never tell you.
	 *
	 * @param string $country Country code to send.
	 * @return array<string, mixed>
	 */
	private function run_geo_loopback( string $country ): array {
		$country = GTLM_Geo::normalize_code( $country );
		if ( '' === $country ) {
			$country = 'SE';
		}

		$token  = GTLM_Geo::issue_probe_token();
		$method = (string) $this->settings->all()['geo_detection_method'];
		$header = 'CF-IPCountry';
		if ( 'custom' === $method ) {
			$custom = (string) $this->settings->all()['geo_custom_header'];
			if ( '' !== $custom ) {
				$header = str_replace( '_', '-', $custom );
			}
		}

		$response = wp_remote_get(
			add_query_arg( 'token', $token, rest_url( 'gt-link-manager/v1/geo-probe' ) ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( $header => $country ),
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			// The token is never spent when the request itself fails, so retire it.
			GTLM_Geo::consume_probe_token( $token );

			return array(
				'ok'      => false,
				'sent'    => $country,
				'header'  => $header,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return array(
				'ok'      => false,
				'sent'    => $country,
				'header'  => $header,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'The loopback request returned HTTP %d. If your site blocks server-to-self requests, this check cannot run — it does not mean geolocation is broken.', 'gt-link-manager' ),
					$code
				),
			);
		}

		$detected = isset( $body['country'] ) ? (string) $body['country'] : '';

		// A mismatch is not a failure when the site sits behind a CDN: the
		// loopback leaves the server, reaches the edge, and the edge rewrites
		// the country header to the server's own location. Detection resolving
		// a real country that is not the one we sent is the strongest possible
		// evidence the pipeline works *and* that the header cannot be forged.
		$echoed     = ( $detected === $country );
		$overridden = ( '' !== $detected && ! $echoed );

		return array(
			'ok'         => $echoed || $overridden,
			'overridden' => $overridden,
			'sent'       => $country,
			'header'     => $header,
			'detected'   => $detected,
			'source'     => isset( $body['source'] ) ? (string) $body['source'] : '',
		);
	}

	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( 'gtlm-links', 'gtlm-links-edit', 'gtlm-links-categories', 'gtlm-links-settings', 'gtlm-links-import-export' ), true ) ) {
			return;
		}

		if ( 'gtlm-links-import-export' === $page ) {
			$this->importer->handle_actions();
			return;
		}

		if ( ! current_user_can( $this->links_capability( 'actions' ) ) ) {
			return;
		}

		if ( 'gtlm-links' === $page ) {
			$this->handle_link_actions();
		}

		if ( 'gtlm-links-edit' === $page ) {
			$this->handle_link_save_action();
		}

		if ( 'gtlm-links-categories' === $page ) {
			$this->handle_category_actions();
		}

		if ( 'gtlm-links-settings' === $page && current_user_can( 'manage_options' ) ) {
			$this->handle_settings_action();
		}
	}

	private function handle_link_actions(): void {
		if ( ! isset( $_GET['action'], $_GET['link'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action  = sanitize_key( (string) wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link_id = absint( $_GET['link'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $link_id <= 0 ) {
			return;
		}

		$allowed = array( 'trash', 'restore', 'permanent_delete', 'activate', 'deactivate' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return;
		}

		check_admin_referer( 'gtlm_' . $action . '_' . $link_id );

		$redirect_url = admin_url( 'admin.php?page=gtlm-links' );

		switch ( $action ) {
			case 'trash':
				$ok = $this->db->trash_link( $link_id );
				$this->redirect_with_notice( $redirect_url, $ok ? 'trashed' : 'trash_failed' );
				break;

			case 'restore':
				$ok = $this->db->restore_link( $link_id );
				$this->redirect_with_notice( add_query_arg( 'link_status', 'trash', $redirect_url ), $ok ? 'restored' : 'restore_failed' );
				break;

			case 'permanent_delete':
				$ok = $this->db->delete_link( $link_id );
				$this->redirect_with_notice( add_query_arg( 'link_status', 'trash', $redirect_url ), $ok ? 'deleted' : 'delete_failed' );
				break;

			case 'activate':
				$ok = $this->db->toggle_active( $link_id, true );
				$this->redirect_with_notice( $redirect_url, $ok ? 'activated' : 'activate_failed' );
				break;

			case 'deactivate':
				$ok = $this->db->toggle_active( $link_id, false );
				$this->redirect_with_notice( $redirect_url, $ok ? 'deactivated' : 'deactivate_failed' );
				break;
		}
	}

	private function handle_link_save_action(): void {
		if ( ! isset( $_POST['gtlm_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gtlm_action'] ) );
		if ( 'save_link' !== $action ) {
			return;
		}

		check_admin_referer( 'gtlm_link_save' );

		$link_mode = sanitize_key( (string) wp_unslash( $_POST['link_mode'] ?? 'standard' ) );
		if ( ! in_array( $link_mode, array( 'standard', 'direct', 'regex' ), true ) ) {
			$link_mode = 'standard';
		}

		// Standard slugs use sanitize_title; direct/regex slugs preserve special characters.
		if ( 'standard' === $link_mode ) {
			$slug = sanitize_title( (string) wp_unslash( $_POST['slug'] ?? '' ) );
		} else {
			$slug = sanitize_text_field( (string) wp_unslash( $_POST['slug'] ?? '' ) );
		}

		$data = array(
			'name'              => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'              => $slug,
			'url'               => esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) ),
			'redirect_type'     => absint( $_POST['redirect_type'] ?? 301 ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_rel_from_post().
			'rel'               => $this->sanitize_rel_from_post( wp_unslash( $_POST['rel'] ?? array() ) ),
			'noindex'           => ! empty( $_POST['noindex'] ) ? 1 : 0,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) wp_unslash( $_POST['regex_replacement'] ?? '' ) ),
			'priority'          => max( 0, absint( $_POST['priority'] ?? 10 ) ),
			'geo_mode'          => 'targeted' === sanitize_key( (string) wp_unslash( $_POST['geo_mode'] ?? 'off' ) ) ? 'targeted' : 'off',
			'geo_rules'         => $this->geo_rules_from_post(),
			'category_id'       => absint( $_POST['category_id'] ?? 0 ),
			'tags'              => sanitize_text_field( (string) wp_unslash( $_POST['tags'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( (string) wp_unslash( $_POST['notes'] ?? '' ) ),
		);

		if ( '' === $data['name'] || '' === $data['url'] ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-edit' ), 'invalid' );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		// Validate regex pattern.
		if ( 'regex' === $link_mode && '' !== $data['slug'] ) {
			// Reject overly complex patterns that could cause ReDoS.
			if ( mb_strlen( $data['slug'] ) > 500 || false === preg_match( '#' . $data['slug'] . '#', 'test' ) ) {
				$this->redirect_with_notice(
					admin_url( 'admin.php?page=gtlm-links-edit' . ( absint( $_POST['link_id'] ?? 0 ) > 0 ? '&link_id=' . absint( $_POST['link_id'] ) : '' ) ),
					'invalid_regex'
				);
			}
		}

		// Block reserved WordPress paths for direct links.
		if ( 'direct' === $link_mode && '' !== $data['slug'] ) {
			$reserved      = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-cron.php', 'wp-json', 'xmlrpc.php', 'feed', 'comments' );
			$first_segment = explode( '/', trim( $data['slug'], '/' ) )[0];
			if ( in_array( strtolower( $first_segment ), $reserved, true ) ) {
				$this->redirect_with_notice(
					admin_url( 'admin.php?page=gtlm-links-edit' . ( absint( $_POST['link_id'] ?? 0 ) > 0 ? '&link_id=' . absint( $_POST['link_id'] ) : '' ) ),
					'reserved_path'
				);
			}
		}

		$link_id = absint( $_POST['link_id'] ?? 0 );

		if ( $link_id > 0 ) {
			$existing = $this->db->get_link_by_id( $link_id );
			if ( is_array( $existing ) ) {
				$data['is_active'] = (int) ( $existing['is_active'] ?? 1 );
			}
		}

		$ok = $link_id > 0 ? $this->db->update_link( $link_id, $data ) : ( $this->db->insert_link( $data ) > 0 );
		if ( $link_id <= 0 && $ok ) {
			$created = $this->db->get_link_by_slug( $data['slug'] );
			$link_id = is_array( $created ) ? (int) $created['id'] : 0;
		}

		$save_and_add  = ! empty( $_POST['save_add_another'] );
		$save_view_all = ! empty( $_POST['save_view_all'] );

		if ( $ok && $save_view_all ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links&highlight=' . $link_id ), 'saved' );
		}

		if ( $ok && $save_and_add ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-edit' ), 'saved' );
		}

		if ( $ok ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-edit&link_id=' . $link_id ), 'saved' );
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-edit' . ( $link_id > 0 ? '&link_id=' . $link_id : '' ) ), 'save_failed' );
	}

	private function handle_category_actions(): void {
		if ( isset( $_GET['action'], $_GET['category_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( (string) wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id     = absint( $_GET['category_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'delete' === $action && $id > 0 ) {
				check_admin_referer( 'gtlm_category_delete_' . $id );
				$ok = $this->db->delete_category( $id );
				$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-categories' ), $ok ? 'category_deleted' : 'category_delete_failed' );
			}
		}

		if ( ! isset( $_POST['gtlm_category_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gtlm_category_action'] ) );
		if ( 'save_category' !== $action ) {
			return;
		}

		check_admin_referer( 'gtlm_category_save' );

		$data = array(
			'name'        => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ?? '' ) ),
			'parent_id'   => absint( $_POST['parent_id'] ?? 0 ),
		);

		if ( '' === $data['name'] ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-categories' ), 'invalid_category' );
		}

		$category_id = absint( $_POST['category_id'] ?? 0 );
		$ok          = $category_id > 0 ? $this->db->update_category( $category_id, $data ) : ( $this->db->insert_category( $data ) > 0 );

		$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-categories' ), $ok ? 'category_saved' : 'category_save_failed' );
	}

	private function handle_settings_action(): void {
		if ( ! isset( $_POST['gtlm_settings_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gtlm_settings_action'] ) );
		if ( ! in_array( $action, array( 'save_settings', 'flush_permalinks', 'run_diagnostics' ), true ) ) {
			return;
		}

		check_admin_referer( 'gtlm_settings_save' );

		if ( 'flush_permalinks' === $action ) {
			flush_rewrite_rules();
			$this->db->flush_cache_group();
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-settings' ), 'permalinks_flushed' );
		}

		if ( 'run_diagnostics' === $action ) {
			update_option( 'gtlm_diagnostics', $this->run_diagnostics(), false );
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-settings' ), 'diagnostics_done' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_rel_from_post().
		$rel   = $this->sanitize_rel_from_post( wp_unslash( $_POST['default_rel'] ?? array() ) );
		$saved = $this->settings->update(
			array(
				'base_prefix'               => sanitize_text_field( (string) wp_unslash( $_POST['base_prefix'] ?? 'go' ) ),
				'default_redirect_type'     => absint( $_POST['default_redirect_type'] ?? 301 ),
				'default_rel'               => '' !== $rel ? explode( ',', $rel ) : array(),
				'default_noindex'           => ! empty( $_POST['default_noindex'] ) ? 1 : 0,
				'delete_data_on_uninstall'  => ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0,
				'enable_advanced_redirects' => ! empty( $_POST['enable_advanced_redirects'] ) ? 1 : 0,
				'enable_geo_targeting'      => ! empty( $_POST['enable_geo_targeting'] ) ? 1 : 0,
				'geo_detection_method'      => sanitize_key( (string) wp_unslash( $_POST['geo_detection_method'] ?? 'auto' ) ),
				'geo_custom_header'         => sanitize_text_field( (string) wp_unslash( $_POST['geo_custom_header'] ?? '' ) ),
				'geo_debug_header'          => ! empty( $_POST['geo_debug_header'] ) ? 1 : 0,
			)
		);

		if ( $saved ) {
			$this->db->flush_cache_group();
			flush_rewrite_rules();
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-settings' ), $saved ? 'settings_saved' : 'settings_unchanged' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_diagnostics(): array {
		global $wpdb, $wp_rewrite;

		$settings   = $this->settings->all();
		$prefix     = trim( (string) $settings['base_prefix'], '/' );
		$rules      = is_object( $wp_rewrite ) ? $wp_rewrite->wp_rewrite_rules() : array();
		$rule_match = false;
		if ( is_array( $rules ) ) {
			$needle     = '^' . preg_quote( $prefix, '/' ) . '/([^/]+)/?$';
			$rule_match = isset( $rules[ $needle ] );
		}

		$table_links = GTLM_DB::links_table();
		$table_cats  = GTLM_DB::categories_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_links ) ) === $table_links;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cats_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_cats ) ) === $table_cats;
		$tables_ok  = $links_exist && $cats_exist;

		$sample           = $this->db->list_links( array(), 1, 1, 'id', 'DESC' );
		$loopback_ok      = null;
		$loopback_message = __( 'No link available for runtime redirect test.', 'gt-link-manager' );

		if ( ! empty( $sample ) ) {
			$link = $sample[0];
			$test = wp_remote_get(
				home_url( '/' . $prefix . '/' . (string) $link['slug'] ),
				array(
					'timeout'     => 8,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $test ) ) {
				$loopback_ok      = false;
				$loopback_message = $test->get_error_message();
			} else {
				$status           = (int) wp_remote_retrieve_response_code( $test );
				$location         = (string) wp_remote_retrieve_header( $test, 'location' );
				$expected         = (string) $link['url'];
				$loopback_ok      = in_array( $status, array( 301, 302, 307 ), true ) && ( '' !== $location );
				$loopback_message = sprintf(
					/* translators: 1: HTTP code, 2: location */
					__( 'Response: %1$d, Location: %2$s', 'gt-link-manager' ),
					$status,
					'' !== $location ? $location : $expected
				);
			}
		}

		GTLM_Geo::reset();
		$geo_sources = array();
		foreach ( GTLM_Geo::sources() as $key => $label ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$geo_sources[] = $label;
			}
		}

		return array(
			'checked_at'  => current_time( 'mysql' ),
			'prefix'      => $prefix,
			'tables_ok'   => $tables_ok,
			'rewrite_ok'  => $rule_match,
			'loopback_ok' => $loopback_ok,
			'message'     => $loopback_message,
			'geo_enabled' => GTLM_Geo::is_enabled(),
			'geo_country' => GTLM_Geo::country(),
			'geo_source'  => GTLM_Geo::source(),
			'geo_sources' => $geo_sources,
		);
	}

	/**
	 * Assemble geo rules from the editor's parallel POST arrays.
	 *
	 * GTLM_Geo::encode_rules() does the validation, so anything malformed is
	 * dropped rather than stored.
	 */
	private function geo_rules_from_post(): string {
		// Nonce verified by the caller via check_admin_referer( 'gtlm_link_save' ),
		// and every value below is validated by GTLM_Geo::encode_rules(), which
		// drops anything that is not a known country code or a valid URL.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// The screen posts a pre-encoded value when the geo UI is not rendered.
		if ( isset( $_POST['geo_rules_json'] ) && ! isset( $_POST['geo_urls'] ) ) {
			return GTLM_Geo::encode_rules( wp_unslash( $_POST['geo_rules_json'] ) );
		}

		$urls = isset( $_POST['geo_urls'] ) ? (array) wp_unslash( $_POST['geo_urls'] ) : array();
		if ( empty( $urls ) ) {
			return '';
		}

		$countries = isset( $_POST['geo_countries'] ) ? (array) wp_unslash( $_POST['geo_countries'] ) : array();
		$types     = isset( $_POST['geo_types'] ) ? (array) wp_unslash( $_POST['geo_types'] ) : array();
		$fallback  = 'block' === sanitize_key( (string) wp_unslash( $_POST['geo_fallback'] ?? 'default' ) ) ? 'block' : 'default';

		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$rules = array();

		foreach ( $urls as $i => $url ) {
			$rules[] = array(
				'countries'     => $countries[ $i ] ?? array(),
				'url'           => (string) $url,
				'redirect_type' => (int) ( $types[ $i ] ?? 0 ),
			);
		}

		return GTLM_Geo::encode_rules(
			array(
				'rules'    => $rules,
				'fallback' => $fallback,
			)
		);
	}

	private function sanitize_rel_from_post( mixed $rel ): string {
		if ( is_string( $rel ) ) {
			$rel = array_filter( array_map( 'trim', explode( ',', $rel ) ) );
		}

		if ( ! is_array( $rel ) ) {
			return '';
		}

		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$clean   = array();
		foreach ( $rel as $value ) {
			$token = sanitize_key( (string) $value );
			if ( in_array( $token, $allowed, true ) ) {
				$clean[] = $token;
			}
		}

		return implode( ',', array_unique( $clean ) );
	}

	/**
	 * Add "GT Link" under the admin bar's "+ New" menu.
	 */
	public function admin_bar_new_link( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( $this->links_capability( 'admin_bar' ) ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'new-content',
				'id'     => 'gtlm-new-link',
				'title'  => __( 'GT Link', 'gt-link-manager' ),
				'href'   => admin_url( 'admin.php?page=gtlm-links-edit' ),
			)
		);
	}

	/**
	 * Add link count to the "At a Glance" dashboard widget.
	 *
	 * @param array<int, string> $items
	 * @return array<int, string>
	 */
	public function dashboard_glance_items( array $items ): array {
		if ( ! current_user_can( $this->links_capability( 'dashboard' ) ) ) {
			return $items;
		}

		$count = $this->db->count_links( array( 'status' => 'active' ) );
		$text  = sprintf(
			/* translators: %s: number of links */
			_n( '%s GT Link', '%s GT Links', $count, 'gt-link-manager' ),
			number_format_i18n( $count )
		);

		$items[] = '<a href="' . esc_url( admin_url( 'admin.php?page=gtlm-links' ) ) . '" class="gtlm-glance-links">' . esc_html( $text ) . '</a>';

		return $items;
	}

	/**
	 * Set default hidden columns for the links list table.
	 *
	 * @param array<int, string> $hidden
	 * @param \WP_Screen         $screen
	 * @return array<int, string>
	 */
	public function default_hidden_columns( array $hidden, \WP_Screen $screen ): array {
		if ( 'toplevel_page_gtlm-links' === $screen->id ) {
			$hidden = array_merge( $hidden, array( 'id', 'rel', 'tags', 'link_mode' ) );
		}

		return $hidden;
	}

	private function links_capability( string $context ): string {
		return (string) apply_filters( 'gtlm_capabilities', 'edit_posts', $context );
	}

	private function redirect_with_notice( string $url, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'gtlm_notice' => sanitize_key( $notice ) ), $url ) );
		exit;
	}
}
