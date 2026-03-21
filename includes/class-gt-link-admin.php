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
			foreach ( $this->db->get_categories() as $cat ) {
				$categories_data[] = array(
					'id'   => (int) $cat['id'],
					'name' => (string) $cat['name'],
				);
			}
		}

		wp_localize_script(
			'gt-link-manager-admin',
			'gtlmAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'quickEditNonce' => wp_create_nonce( 'gtlm_quick_edit' ),
				'prefix'         => $this->settings->prefix(),
				'categories'     => $categories_data,
				'highlight'      => isset( $_GET['highlight'] ) ? absint( $_GET['highlight'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'i18n'           => array(
					'saved'      => __( 'Saved', 'gt-link-manager' ),
					'saveFailed' => __( 'Save failed', 'gt-link-manager' ),
					'copied'     => __( 'Copied', 'gt-link-manager' ),
					'copyUrl'    => __( 'Copy URL', 'gt-link-manager' ),
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

		check_admin_referer( 'gt_link_' . $action . '_' . $link_id );

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

		$data = array(
			'name'          => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'          => sanitize_title( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
			'url'           => esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) ),
			'redirect_type' => absint( $_POST['redirect_type'] ?? 301 ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_rel_from_post().
			'rel'           => $this->sanitize_rel_from_post( wp_unslash( $_POST['rel'] ?? array() ) ),
			'noindex'       => ! empty( $_POST['noindex'] ) ? 1 : 0,
			'category_id'   => absint( $_POST['category_id'] ?? 0 ),
			'tags'          => sanitize_text_field( (string) wp_unslash( $_POST['tags'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( (string) wp_unslash( $_POST['notes'] ?? '' ) ),
		);

		if ( '' === $data['name'] || '' === $data['url'] ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gtlm-links-edit' ), 'invalid' );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
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
				'base_prefix'              => sanitize_text_field( (string) wp_unslash( $_POST['base_prefix'] ?? 'go' ) ),
				'default_redirect_type'    => absint( $_POST['default_redirect_type'] ?? 301 ),
				'default_rel'              => '' !== $rel ? explode( ',', $rel ) : array(),
				'default_noindex'          => ! empty( $_POST['default_noindex'] ) ? 1 : 0,
				'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0,
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

		return array(
			'checked_at'  => current_time( 'mysql' ),
			'prefix'      => $prefix,
			'tables_ok'   => $tables_ok,
			'rewrite_ok'  => $rule_match,
			'loopback_ok' => $loopback_ok,
			'message'     => $loopback_message,
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
			$hidden = array_merge( $hidden, array( 'id', 'rel', 'tags' ) );
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
