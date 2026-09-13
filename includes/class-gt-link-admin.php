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
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_gtlm_quick_edit', array( $this, 'ajax_quick_edit' ) );
		add_action( 'wp_ajax_gtlm_geo_check', array( $this, 'ajax_geo_check' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_content' ) );
		add_action(
			'admin_post_gtlm_analytics_export',
			static function (): void {
				require_once GTLM_PATH . 'includes/class-gtlm-analytics-controller.php';
				GTLM_Analytics_Controller::export();
			}
		);
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
			'none',
			26
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'add_links_screen_options' ) );
		}

		add_submenu_page( 'gtlm-links', esc_html__( 'All Links', 'gt-link-manager' ), esc_html__( 'All Links', 'gt-link-manager' ), $capability, 'gtlm-links', array( $this->pages, 'render_links_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Add New', 'gt-link-manager' ), esc_html__( 'Add New', 'gt-link-manager' ), $capability, 'gtlm-links-edit', array( $this->pages, 'render_edit_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Categories', 'gt-link-manager' ), esc_html__( 'Categories', 'gt-link-manager' ), $capability, 'gtlm-links-categories', array( $this->pages, 'render_categories_page' ) );
		add_submenu_page( 'gtlm-links', __( 'Analytics', 'gt-link-manager' ), __( 'Analytics', 'gt-link-manager' ), (string) apply_filters( 'gtlm_analytics_capability', 'manage_options' ), 'gtlm-links-analytics', array( $this->pages, 'render_analytics_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Settings', 'gt-link-manager' ), esc_html__( 'Settings', 'gt-link-manager' ), 'manage_options', 'gtlm-links-settings', array( $this->pages, 'render_settings_page' ) );
		add_submenu_page( 'gtlm-links', esc_html__( 'Import / Export', 'gt-link-manager' ), esc_html__( 'Import / Export', 'gt-link-manager' ), $capability, 'gtlm-links-import-export', array( $this->pages, 'render_import_export_page' ) );
	}

	/**
	 * Admin menu icon.
	 *
	 * A single-colour SVG data URI rather than the full-colour brand PNG:
	 * WordPress recolours menu icons per admin colour scheme and for the
	 * hover/current states, which a raster icon cannot follow. The glyph is
	 * the chain element of the brand mark, which is the part that stays
	 * legible once it is reduced to 20px.
	 */
	private static function menu_icon(): string {
		$svg = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZD0iTTcuNiA5LjFoNC44djEuOEg3LjZ6Ii8+PHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBkPSJNNC4xIDYuNGgxLjRhMy42IDMuNiAwIDEgMSAwIDcuMkg0LjFhMy42IDMuNiAwIDEgMSAwLTcuMlptMCAyLjFhMS41IDEuNSAwIDAgMCAwIDNoMS40YTEuNSAxLjUgMCAwIDAgMC0zWiIvPjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTE0LjUgNi40aDEuNGEzLjYgMy42IDAgMSAxIDAgNy4yaC0xLjRhMy42IDMuNiAwIDEgMSAwLTcuMlptMCAyLjFhMS41IDEuNSAwIDAgMCAwIDNoMS40YTEuNSAxLjUgMCAwIDAgMC0zWiIvPjwvc3ZnPgo=';

		return 'data:image/svg+xml;base64,' . $svg;
	}

	/**
	 * Paint the admin menu icon.
	 *
	 * The menu is registered with an icon of 'none' on purpose. When core is
	 * handed an SVG data URI it prints it as an inline `background-image` with
	 * `!important`, which no stylesheet can override and which it never
	 * recolours, so a single-colour glyph stays black on the dark sidebar.
	 *
	 * With 'none' there is no inline style to fight. The glyph is painted as a
	 * mask filled with currentColor, so colour comes from core: the icon
	 * matches the dashicons beside it in every state, follows every admin
	 * colour scheme, and goes dark on the light schemes without extra rules.
	 *
	 * Printed on every admin screen, because the plugin stylesheet only loads
	 * on this plugin's pages while the sidebar is on all of them.
	 */
	public function print_menu_icon_style(): void {
		$icon = self::menu_icon();

		$css = '#adminmenu #toplevel_page_gtlm-links .wp-menu-image{'
			. 'background-color:currentColor;'
			. '-webkit-mask-image:url("' . $icon . '");'
			. 'mask-image:url("' . $icon . '");'
			. '-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;'
			. '-webkit-mask-position:center;mask-position:center;'
			. '-webkit-mask-size:20px auto;mask-size:20px auto;'
			. '}'
			// The empty dashicon pseudo-element would otherwise reserve width
			// next to the mask and push the label across.
			. '#adminmenu #toplevel_page_gtlm-links .wp-menu-image:before{content:"";display:none;}';

		printf( '<style id="gtlm-menu-icon">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-generated static CSS with a plugin-controlled data URI.
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
		if ( ! in_array( $page, array( 'gtlm-links', 'gtlm-links-edit', 'gtlm-links-categories', 'gtlm-links-settings', 'gtlm-links-import-export', 'gtlm-links-analytics' ), true ) ) {
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

		// The redirect paragraph has to describe what the site actually does.
		// Claiming nothing is logged while the click counter is switched on
		// would be a false statement in the site's own privacy policy.
		if ( $this->settings->click_tracking_enabled() ) {
			$redirect_paragraph =
				'<p>' . esc_html__( 'When a visitor follows a short link, the plugin looks up the destination and issues an HTTP redirect. It does not log the visitor\'s IP address, user agent, or the referring page.', 'gt-link-manager' ) . '</p>' .
				'<p>' . esc_html__( 'Click tracking is enabled on this site. The plugin keeps one running total per link, counting how many times that link has been followed. The count is not tied to a visitor, a session, a time, or a location: it is a single number per link and cannot be used to identify anyone or to reconstruct an individual visit.', 'gt-link-manager' ) . '</p>';
		} else {
			$redirect_paragraph =
				'<p>' . esc_html__( 'When a visitor follows a short link, the plugin looks up the destination and issues an HTTP redirect. It does not log the request, the visitor\'s IP address, or the referring page.', 'gt-link-manager' ) . '</p>';
		}

		$content =
			'<p>' . esc_html__( 'GT Link Manager stores the short links you create. It does not create user accounts, set cookies, or add tracking scripts.', 'gt-link-manager' ) . '</p>' .
			'<h3>' . esc_html__( 'Redirects', 'gt-link-manager' ) . '</h3>' .
			$redirect_paragraph .
			'<h3>' . esc_html__( 'Geolocation targeting', 'gt-link-manager' ) . '</h3>' .
			'<p>' . esc_html__( 'If geolocation targeting is enabled, the plugin reads a two-letter country code from a request header that your CDN or web server has already added to the request — for example Cloudflare\'s CF-IPCountry header. The plugin never reads or processes the visitor\'s IP address, never contacts an external geolocation service, and never stores or transmits the country. The value exists only for the duration of that single request and is used solely to choose which URL to redirect to.', 'gt-link-manager' ) . '</p>' .
			'<p>' . esc_html__( 'Because the country is derived from data your CDN already collects, the relevant disclosure usually belongs with your CDN provider rather than with this plugin. Check your CDN\'s own privacy documentation.', 'gt-link-manager' ) . '</p>' .
			'<p>' . esc_html__( 'Note: if you have added click tracking or analytics through the plugin\'s gtlm_before_redirect hook, that code receives the detected country and may store or transmit it. Any such storage is the responsibility of the integration you added, not of this plugin.', 'gt-link-manager' ) . '</p>';

		if ( $this->settings->analytics_initialized() ) {
			$content  = '<p>' . esc_html__( 'GT Link Manager stores configured links and redirects visitors. Basic lifetime click counts, if enabled, are independent of advanced analytics.', 'gt-link-manager' ) . '</p>';
			$content .= '<p>' . esc_html__( 'This site has opted in to advanced link analytics. Eligible redirect requests may store a short-lived timestamp, link ID, referring hostname and page URL without credentials, query strings or fragments, coarse device/browser/OS families, an allowlisted campaign ID, redirect type, and optionally a country code supplied by a trusted proxy. Dated aggregate summaries are retained under the configured retention policy. Pausing collection retains previously collected reports until their retention period ends or they are deleted.', 'gt-link-manager' ) . '</p>';
			$content .= '<p>' . esc_html__( 'The plugin does not set analytics cookies, inject visitor tracking scripts, store IP addresses or IP hashes, retain raw user agents or referring URL credentials, query strings or fragments, or identify unique visitors. It does not contact an external analytics or geolocation service. Browser referrer restrictions may leave the source unknown. Site integrations can further suppress collection, including when visitor consent is required.', 'gt-link-manager' ) . '</p>';
			$config   = get_option( 'gtlm_analytics', array() );
			if ( is_array( $config ) && isset( $config['event_days'], $config['summary_days'] ) ) {
				/* translators: 1: event days, 2: summary days. */
				$content .= '<p>' . esc_html( sprintf( __( 'Configured retention: click records for %1$d days and aggregate summaries for %2$d days. Maintenance must run for scheduled deletion to take place.', 'gt-link-manager' ), $config['event_days'], $config['summary_days'] ) ) . '</p>';
			}
		}
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
		if ( ! in_array( $page, array( 'gtlm-links', 'gtlm-links-edit', 'gtlm-links-categories', 'gtlm-links-settings', 'gtlm-links-import-export', 'gtlm-links-analytics' ), true ) ) {
			return;
		}

		if ( 'gtlm-links-analytics' === $page ) {
			require_once GTLM_PATH . 'includes/class-gtlm-analytics-controller.php';
			GTLM_Analytics_Controller::handle_admin();
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
			$this->handle_undo_action();
			$this->handle_bulk_actions();
			$this->handle_empty_trash_action();
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

	/**
	 * Run bulk actions on admin_init.
	 *
	 * Previously these ran from the list table's prepare_items(), which meant
	 * no success notice, no undo, and a browser refresh silently re-running
	 * the action. Handling them here follows the core pattern: act, then
	 * redirect to a clean URL carrying the result.
	 */
	private function handle_bulk_actions(): void {
		$action = '';
		foreach ( array( 'action', 'action2' ) as $field ) {
			if ( isset( $_REQUEST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$candidate = sanitize_key( (string) wp_unslash( $_REQUEST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' !== $candidate && '-1' !== $candidate ) {
					$action = $candidate;
					break;
				}
			}
		}

		if ( '' === $action || ! str_starts_with( $action, 'bulk_' ) ) {
			return;
		}

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-gtlm_links' ) ) {
			return;
		}

		$link_ids = isset( $_REQUEST['link_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['link_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link_ids = array_values( array_filter( $link_ids ) );

		$view         = isset( $_REQUEST['link_status'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['link_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_url = admin_url( 'admin.php?page=gtlm-links' );
		if ( '' !== $view ) {
			$redirect_url = add_query_arg( 'link_status', $view, $redirect_url );
		}

		if ( empty( $link_ids ) ) {
			$this->redirect_with_notice( $redirect_url, 'bulk_none' );
		}

		$result = $this->run_bulk_action( $action, $link_ids );

		if ( null === $result ) {
			return;
		}

		$this->redirect_with_notice(
			$redirect_url,
			$result['notice'],
			$result['undo_ids'],
			$result['undo_action'],
			$result['count']
		);
	}

	/**
	 * Apply one bulk action.
	 *
	 * @param array<int, int> $link_ids Target links.
	 * @return array{notice: string, count: int, undo_ids: array<int, int>, undo_action: string}|null
	 */
	private function run_bulk_action( string $action, array $link_ids ): ?array {
		$count    = 0;
		$affected = array();

		$simple = array(
			'bulk_trash'            => array(
				'notice' => 'bulk_trashed',
				'undo'   => 'restore',
			),
			'bulk_restore'          => array(
				'notice' => 'bulk_restored',
				'undo'   => 'trash',
			),
			'bulk_permanent_delete' => array(
				'notice' => 'bulk_deleted',
				'undo'   => '',
			),
			'bulk_activate'         => array(
				'notice' => 'bulk_activated',
				'undo'   => 'deactivate',
			),
			'bulk_deactivate'       => array(
				'notice' => 'bulk_deactivated',
				'undo'   => 'activate',
			),
		);

		if ( isset( $simple[ $action ] ) ) {
			foreach ( $link_ids as $id ) {
				$ok = false;
				switch ( $action ) {
					case 'bulk_trash':
						$ok = $this->db->trash_link( $id );
						break;
					case 'bulk_restore':
						$ok = $this->db->restore_link( $id );
						break;
					case 'bulk_permanent_delete':
						$ok = $this->db->delete_link( $id );
						break;
					case 'bulk_activate':
						$ok = $this->db->toggle_active( $id, true );
						break;
					case 'bulk_deactivate':
						$ok = $this->db->toggle_active( $id, false );
						break;
				}
				if ( $ok ) {
					++$count;
					$affected[] = $id;
				}
			}

			return array(
				'notice'      => $simple[ $action ]['notice'],
				'count'       => $count,
				'undo_ids'    => $affected,
				'undo_action' => (string) $simple[ $action ]['undo'],
			);
		}

		$rel_map = array(
			'bulk_rel_none'      => '',
			'bulk_rel_nofollow'  => 'nofollow',
			'bulk_rel_sponsored' => 'sponsored',
			'bulk_rel_ugc'       => 'ugc',
		);

		foreach ( $link_ids as $id ) {
			$link = $this->db->get_link_by_id( $id );
			if ( null === $link ) {
				continue;
			}

			if ( in_array( $action, array( 'bulk_301', 'bulk_302', 'bulk_307' ), true ) ) {
				if ( $this->db->update_link( $id, array_merge( $link, array( 'redirect_type' => (int) str_replace( 'bulk_', '', $action ) ) ) ) ) {
					++$count;
				}
				continue;
			}

			if ( array_key_exists( $action, $rel_map ) ) {
				if ( $this->db->update_link( $id, array_merge( $link, array( 'rel' => $rel_map[ $action ] ) ) ) ) {
					++$count;
				}
				continue;
			}

			if ( 'bulk_set_category' === $action ) {
				$category_id = isset( $_REQUEST['bulk_category_id'] ) ? absint( $_REQUEST['bulk_category_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $this->db->update_link( $id, array_merge( $link, array( 'category_id' => $category_id ) ) ) ) {
					++$count;
				}
				continue;
			}

			return null;
		}

		return array(
			'notice'      => 'bulk_updated',
			'count'       => $count,
			'undo_ids'    => array(),
			'undo_action' => '',
		);
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

		$allowed = array( 'trash', 'restore', 'permanent_delete', 'activate', 'deactivate', 'reset_clicks' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return;
		}

		check_admin_referer( 'gtlm_' . $action . '_' . $link_id );

		$redirect_url = admin_url( 'admin.php?page=gtlm-links' );

		switch ( $action ) {
			case 'trash':
				$ok = $this->db->trash_link( $link_id );
				$this->redirect_with_notice(
					$redirect_url,
					$ok ? 'trashed' : 'trash_failed',
					$ok ? array( $link_id ) : array(),
					'restore'
				);
				break;

			case 'restore':
				$ok = $this->db->restore_link( $link_id );
				$this->redirect_with_notice(
					add_query_arg( 'link_status', 'trash', $redirect_url ),
					$ok ? 'restored' : 'restore_failed',
					$ok ? array( $link_id ) : array(),
					'trash'
				);
				break;

			case 'permanent_delete':
				// Not undoable: the row is gone.
				$ok = $this->db->delete_link( $link_id );
				$this->redirect_with_notice( add_query_arg( 'link_status', 'trash', $redirect_url ), $ok ? 'deleted' : 'delete_failed' );
				break;

			case 'reset_clicks':
				// Not undoable: the previous count is gone once it is zeroed.
				$ok = $this->db->reset_clicks( $link_id ) > 0;
				$this->redirect_with_notice( $redirect_url, $ok ? 'clicks_reset' : 'clicks_reset_failed' );
				break;

			case 'activate':
				$ok = $this->db->toggle_active( $link_id, true );
				$this->redirect_with_notice(
					$redirect_url,
					$ok ? 'activated' : 'activate_failed',
					$ok ? array( $link_id ) : array(),
					'deactivate'
				);
				break;

			case 'deactivate':
				$ok = $this->db->toggle_active( $link_id, false );
				$this->redirect_with_notice(
					$redirect_url,
					$ok ? 'deactivated' : 'deactivate_failed',
					$ok ? array( $link_id ) : array(),
					'activate'
				);
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
			$created = $this->db->get_link_by_exact_slug( $data['slug'] );
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
				'trash_retention_days'      => isset( $_POST['trash_retention_days'] ) ? absint( wp_unslash( $_POST['trash_retention_days'] ) ) : 30,
				'enable_click_tracking'     => ! empty( $_POST['enable_click_tracking'] ) ? 1 : 0,
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
			$rel = preg_split( '/[\s,]+/', $rel, -1, PREG_SPLIT_NO_EMPTY );
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

			// Hide the Clicks column until tracking is switched on, so an
			// unused feature does not take up a column by default.
			if ( ! $this->settings->click_tracking_enabled() ) {
				$hidden[] = 'total_clicks';
			}
		}

		return $hidden;
	}

	private function links_capability( string $context ): string {
		return (string) apply_filters( 'gtlm_capabilities', 'edit_posts', $context );
	}

	/**
	 * Redirect back to a list view with a notice, optionally offering an undo.
	 *
	 * @param string             $url    Destination.
	 * @param string             $notice Notice key.
	 * @param array<int, int>    $undo_ids  Links the undo would act on.
	 * @param string             $undo_action Inverse action to run.
	 * @param int                $count  Number of affected links, for plural notices.
	 */
	private function redirect_with_notice( string $url, string $notice, array $undo_ids = array(), string $undo_action = '', int $count = 0 ): void {
		$args = array( 'gtlm_notice' => sanitize_key( $notice ) );

		if ( $count > 0 ) {
			$args['gtlm_count'] = $count;
		}

		$undo_ids = array_values( array_filter( array_map( 'absint', $undo_ids ) ) );

		if ( '' !== $undo_action && ! empty( $undo_ids ) ) {
			$ids_arg                 = implode( ',', $undo_ids );
			$args['gtlm_undo']       = sanitize_key( $undo_action );
			$args['gtlm_undo_ids']   = $ids_arg;
			$args['gtlm_undo_nonce'] = wp_create_nonce( 'gtlm_undo_' . $undo_action . '_' . $ids_arg );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Reverse the previous action.
	 *
	 * Undo is offered for reversible state changes only. A permanent delete
	 * removes the row, so it is never undoable -- matching core's behaviour
	 * for posts.
	 */
	private function handle_undo_action(): void {
		if ( ! isset( $_GET['gtlm_do_undo'], $_GET['gtlm_undo_ids'], $_GET['gtlm_undo_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action  = sanitize_key( (string) wp_unslash( $_GET['gtlm_do_undo'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids_arg = sanitize_text_field( (string) wp_unslash( $_GET['gtlm_undo_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce   = sanitize_text_field( (string) wp_unslash( $_GET['gtlm_undo_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'gtlm_undo_' . $action . '_' . $ids_arg ) ) {
			return;
		}

		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $ids_arg ) ) ) );
		if ( empty( $ids ) || ! in_array( $action, array( 'trash', 'restore', 'activate', 'deactivate' ), true ) ) {
			return;
		}

		$done = 0;
		foreach ( $ids as $id ) {
			$ok = false;
			switch ( $action ) {
				case 'trash':
					$ok = $this->db->trash_link( $id );
					break;
				case 'restore':
					$ok = $this->db->restore_link( $id );
					break;
				case 'activate':
					$ok = $this->db->toggle_active( $id, true );
					break;
				case 'deactivate':
					$ok = $this->db->toggle_active( $id, false );
					break;
			}
			if ( $ok ) {
				++$done;
			}
		}

		$view = ( 'trash' === $action ) ? 'trash' : '';
		$url  = admin_url( 'admin.php?page=gtlm-links' );
		if ( '' !== $view ) {
			$url = add_query_arg( 'link_status', $view, $url );
		}

		$this->redirect_with_notice( $url, $done > 0 ? 'undone' : 'undo_failed', array(), '', $done );
	}

	/**
	 * Empty the trash in one action.
	 */
	private function handle_empty_trash_action(): void {
		if ( ! isset( $_GET['gtlm_action'] ) || 'empty_trash' !== sanitize_key( (string) wp_unslash( $_GET['gtlm_action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'gtlm_empty_trash' );

		$deleted = $this->db->empty_trash();

		$this->redirect_with_notice(
			add_query_arg( 'link_status', 'trash', admin_url( 'admin.php?page=gtlm-links' ) ),
			'trash_emptied',
			array(),
			'',
			$deleted
		);
	}
}
