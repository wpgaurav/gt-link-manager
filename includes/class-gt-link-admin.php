<?php
/**
 * Admin pages and actions.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Admin {
	private GT_Link_DB $db;

	private GT_Link_Settings $settings;

	private GT_Link_Import $importer;

	public static function init( GT_Link_DB $db, GT_Link_Settings $settings ): void {
		$instance = new self( $db, $settings );
		$instance->hooks();
	}

	private function __construct( GT_Link_DB $db, GT_Link_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->importer = new GT_Link_Import( $db, $settings );
	}

	private function hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_gt_link_quick_edit', array( $this, 'ajax_quick_edit' ) );
	}

	/**
	 * @param mixed $status
	 * @param mixed $option
	 * @param mixed $value
	 * @return mixed
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( 'gt_links_per_page' === $option ) {
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
			'gt-links',
			array( $this, 'render_links_page' ),
			'dashicons-admin-links',
			26
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'add_links_screen_options' ) );
		}

		add_submenu_page( 'gt-links', esc_html__( 'All Links', 'gt-link-manager' ), esc_html__( 'All Links', 'gt-link-manager' ), $capability, 'gt-links', array( $this, 'render_links_page' ) );
		add_submenu_page( 'gt-links', esc_html__( 'Add New', 'gt-link-manager' ), esc_html__( 'Add New', 'gt-link-manager' ), $capability, 'gt-links-edit', array( $this, 'render_edit_page' ) );
		add_submenu_page( 'gt-links', esc_html__( 'Categories', 'gt-link-manager' ), esc_html__( 'Categories', 'gt-link-manager' ), $capability, 'gt-links-categories', array( $this, 'render_categories_page' ) );
		add_submenu_page( 'gt-links', esc_html__( 'Settings', 'gt-link-manager' ), esc_html__( 'Settings', 'gt-link-manager' ), 'manage_options', 'gt-links-settings', array( $this, 'render_settings_page' ) );
		add_submenu_page( 'gt-links', esc_html__( 'Import / Export', 'gt-link-manager' ), esc_html__( 'Import / Export', 'gt-link-manager' ), $capability, 'gt-links-import-export', array( $this, 'render_import_export_page' ) );
	}

	public function add_links_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => esc_html__( 'Links per page', 'gt-link-manager' ),
				'default' => 20,
				'option'  => 'gt_links_per_page',
			)
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( 'gt-links', 'gt-links-edit', 'gt-links-categories', 'gt-links-settings', 'gt-links-import-export' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'gt-link-manager-admin',
			GT_LINK_MANAGER_URL . 'assets/css/admin.css',
			array(),
			GT_LINK_MANAGER_VERSION
		);

		wp_enqueue_script(
			'gt-link-manager-admin',
			GT_LINK_MANAGER_URL . 'assets/js/admin.js',
			array(),
			GT_LINK_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'gt-link-manager-admin',
			'gtlmAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'quickEditNonce' => wp_create_nonce( 'gt_link_quick_edit' ),
				'prefix'         => $this->settings->prefix(),
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

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['nonce'] ) ), 'gt_link_quick_edit' ) ) {
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

		$ok = $this->db->update_link(
			$link_id,
			array_merge(
				$link,
				array(
					'url'           => $url,
					'redirect_type' => $redirect_type,
				)
			)
		);

		if ( ! $ok ) {
			wp_send_json_error();
		}

		wp_send_json_success(
			array(
				'url'           => $url,
				'redirect_type' => $redirect_type,
			)
		);
	}

	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( 'gt-links', 'gt-links-edit', 'gt-links-categories', 'gt-links-settings', 'gt-links-import-export' ), true ) ) {
			return;
		}

		if ( 'gt-links-import-export' === $page ) {
			$this->importer->handle_actions();
			return;
		}

		if ( ! current_user_can( $this->links_capability( 'actions' ) ) ) {
			return;
		}

		if ( 'gt-links' === $page ) {
			$this->handle_link_actions();
		}

		if ( 'gt-links-edit' === $page ) {
			$this->handle_link_save_action();
		}

		if ( 'gt-links-categories' === $page ) {
			$this->handle_category_actions();
		}

		if ( 'gt-links-settings' === $page && current_user_can( 'manage_options' ) ) {
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

		$redirect_url = admin_url( 'admin.php?page=gt-links' );

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
		if ( ! isset( $_POST['gt_link_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gt_link_action'] ) );
		if ( 'save_link' !== $action ) {
			return;
		}

		check_admin_referer( 'gt_link_save' );

		$data = array(
			'name'          => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'          => sanitize_title( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
			'url'           => esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) ),
			'redirect_type' => absint( $_POST['redirect_type'] ?? 301 ),
			'rel'           => $this->sanitize_rel_from_post( $_POST['rel'] ?? array() ),
			'noindex'       => ! empty( $_POST['noindex'] ) ? 1 : 0,
			'category_id'   => absint( $_POST['category_id'] ?? 0 ),
			'tags'          => sanitize_text_field( (string) wp_unslash( $_POST['tags'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( (string) wp_unslash( $_POST['notes'] ?? '' ) ),
		);

		if ( '' === $data['name'] || '' === $data['url'] ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-edit' ), 'invalid' );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		$link_id = absint( $_POST['link_id'] ?? 0 );

		// Preserve is_active from existing link when editing (not in form).
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

		$save_and_add = ! empty( $_POST['save_add_another'] );
		if ( $ok && $save_and_add ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-edit' ), 'saved' );
		}

		if ( $ok ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-edit&link_id=' . $link_id ), 'saved' );
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-edit' . ( $link_id > 0 ? '&link_id=' . $link_id : '' ) ), 'save_failed' );
	}

	private function handle_category_actions(): void {
		if ( isset( $_GET['action'], $_GET['category_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( (string) wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id     = absint( $_GET['category_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'delete' === $action && $id > 0 ) {
				check_admin_referer( 'gt_link_category_delete_' . $id );
				$ok = $this->db->delete_category( $id );
				$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-categories' ), $ok ? 'category_deleted' : 'category_delete_failed' );
			}
		}

		if ( ! isset( $_POST['gt_category_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gt_category_action'] ) );
		if ( 'save_category' !== $action ) {
			return;
		}

		check_admin_referer( 'gt_link_category_save' );

		$data = array(
			'name'        => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) wp_unslash( $_POST['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) wp_unslash( $_POST['description'] ?? '' ) ),
			'parent_id'   => absint( $_POST['parent_id'] ?? 0 ),
		);

		if ( '' === $data['name'] ) {
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-categories' ), 'invalid_category' );
		}

		$category_id = absint( $_POST['category_id'] ?? 0 );
		$ok          = $category_id > 0 ? $this->db->update_category( $category_id, $data ) : ( $this->db->insert_category( $data ) > 0 );

		$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-categories' ), $ok ? 'category_saved' : 'category_save_failed' );
	}

	private function handle_settings_action(): void {
		if ( ! isset( $_POST['gt_settings_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gt_settings_action'] ) );
		if ( ! in_array( $action, array( 'save_settings', 'flush_permalinks', 'run_diagnostics' ), true ) ) {
			return;
		}

		check_admin_referer( 'gt_link_settings_save' );

		if ( 'flush_permalinks' === $action ) {
			flush_rewrite_rules();
			$this->db->flush_cache_group();
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-settings' ), 'permalinks_flushed' );
		}

		if ( 'run_diagnostics' === $action ) {
			update_option( 'gt_link_manager_diagnostics', $this->run_diagnostics(), false );
			$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-settings' ), 'diagnostics_done' );
		}

		$rel   = $this->sanitize_rel_from_post( $_POST['default_rel'] ?? array() );
		$saved = $this->settings->update(
			array(
				'base_prefix'           => sanitize_text_field( (string) wp_unslash( $_POST['base_prefix'] ?? 'go' ) ),
				'default_redirect_type' => absint( $_POST['default_redirect_type'] ?? 301 ),
				'default_rel'           => '' !== $rel ? explode( ',', $rel ) : array(),
				'default_noindex'       => ! empty( $_POST['default_noindex'] ) ? 1 : 0,
			)
		);

		if ( $saved ) {
			$this->db->flush_cache_group();
			flush_rewrite_rules();
		}

		$this->redirect_with_notice( admin_url( 'admin.php?page=gt-links-settings' ), $saved ? 'settings_saved' : 'settings_unchanged' );
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
			$needle = '^' . preg_quote( $prefix, '/' ) . '/([^/]+)/?$';
			$rule_match = isset( $rules[ $needle ] );
		}

		$table_links = GT_Link_DB::links_table();
		$table_cats  = GT_Link_DB::categories_table();
		$tables_ok   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_links ) ) === $table_links )
			&& ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_cats ) ) === $table_cats );

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
					$location ?: $expected
				);
			}
		}

		return array(
			'checked_at' => current_time( 'mysql' ),
			'prefix'     => $prefix,
			'tables_ok'  => $tables_ok,
			'rewrite_ok' => $rule_match,
			'loopback_ok'=> $loopback_ok,
			'message'    => $loopback_message,
		);
	}

	public function render_links_page(): void {
		if ( ! current_user_can( $this->links_capability( 'links_page' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-list-table.php';

		$view       = isset( $_GET['link_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['link_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_views = array( 'active', 'inactive', 'trash' );
		if ( ! in_array( $view, $valid_views, true ) ) {
			$view = '';
		}

		$categories = $this->db->get_categories();
		$table      = new GT_Link_List_Table( $this->db, $categories, $this->settings->prefix(), $view );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'GT Links', 'gt-link-manager' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=gt-links-edit' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'gt-link-manager' ) . '</a>';
		$this->render_notice();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="gt-links" />';
		if ( '' !== $view ) {
			echo '<input type="hidden" name="link_status" value="' . esc_attr( $view ) . '" />';
		}
		$table->search_box( esc_html__( 'Search links', 'gt-link-manager' ), 'gt-links-search' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	public function render_edit_page(): void {
		if ( ! current_user_can( $this->links_capability( 'edit_page' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$link_id    = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link       = $link_id > 0 ? $this->db->get_link_by_id( $link_id ) : null;
		$settings   = $this->settings->all();
		$categories = $this->db->get_categories();

		$defaults = array(
			'name'          => '',
			'url'           => '',
			'slug'          => '',
			'redirect_type' => (int) $settings['default_redirect_type'],
			'rel'           => implode( ',', (array) $settings['default_rel'] ),
			'noindex'       => (int) $settings['default_noindex'],
			'category_id'   => 0,
			'tags'          => '',
			'notes'         => '',
		);
		$form = is_array( $link ) ? wp_parse_args( $link, $defaults ) : $defaults;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $link_id > 0 ? __( 'Edit Link', 'gt-link-manager' ) : __( 'Add New Link', 'gt-link-manager' ) ) . '</h1>';
		$this->render_notice();
		echo '<form method="post" action="">';
		wp_nonce_field( 'gt_link_save' );
		echo '<input type="hidden" name="gt_link_action" value="save_link" />';
		echo '<input type="hidden" name="link_id" value="' . (int) $link_id . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'name', __( 'Link Name', 'gt-link-manager' ), (string) $form['name'], true );
		$this->render_text_field( 'url', __( 'Destination URL', 'gt-link-manager' ), (string) $form['url'], true, 'url' );
		$this->render_text_field( 'slug', __( 'Slug', 'gt-link-manager' ), (string) $form['slug'], false );

		echo '<tr><th scope="row">' . esc_html__( 'Branded URL Preview', 'gt-link-manager' ) . '</th><td>';
		echo '<span id="gtlm-branded-preview">-</span> <button type="button" class="button" id="gtlm-copy-preview">' . esc_html__( 'Copy URL', 'gt-link-manager' ) . '</button>';
		echo '</td></tr>';

		$this->render_redirect_type_field( (int) $form['redirect_type'] );
		$this->render_rel_field( (string) $form['rel'] );
		$this->render_checkbox_field( 'noindex', __( 'Noindex', 'gt-link-manager' ), __( 'Prevent indexing this redirect', 'gt-link-manager' ), ! empty( $form['noindex'] ) );
		$this->render_category_field( $categories, (int) $form['category_id'] );
		$this->render_text_field( 'tags', __( 'Tags (comma-separated)', 'gt-link-manager' ), (string) $form['tags'], false );
		$this->render_textarea_field( 'notes', __( 'Notes', 'gt-link-manager' ), (string) $form['notes'] );
		echo '</tbody></table>';

		submit_button( __( 'Save Link', 'gt-link-manager' ), 'primary', 'save_link' );
		submit_button( __( 'Save & Add Another', 'gt-link-manager' ), 'secondary', 'save_add_another', false );
		echo '</form></div>';
	}

	public function render_categories_page(): void {
		if ( ! current_user_can( $this->links_capability( 'categories_page' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$editing_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing       = $editing_id > 0 ? $this->db->get_category( $editing_id ) : null;
		$categories    = $this->db->get_categories();
		$category_form = wp_parse_args(
			is_array( $editing ) ? $editing : array(),
			array(
				'id'          => 0,
				'name'        => '',
				'slug'        => '',
				'description' => '',
				'parent_id'   => 0,
			)
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Link Categories', 'gt-link-manager' ) . '</h1>';
		$this->render_notice();
		echo '<h2>' . esc_html( $editing_id > 0 ? __( 'Edit Category', 'gt-link-manager' ) : __( 'Add Category', 'gt-link-manager' ) ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'gt_link_category_save' );
		echo '<input type="hidden" name="gt_category_action" value="save_category" />';
		echo '<input type="hidden" name="category_id" value="' . (int) $category_form['id'] . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'name', __( 'Name', 'gt-link-manager' ), (string) $category_form['name'], true );
		$this->render_text_field( 'slug', __( 'Slug', 'gt-link-manager' ), (string) $category_form['slug'], false );
		$this->render_parent_category_field( $categories, (int) $category_form['parent_id'], (int) $category_form['id'] );
		$this->render_textarea_field( 'description', __( 'Description', 'gt-link-manager' ), (string) $category_form['description'] );
		echo '</tbody></table>';
		submit_button( __( 'Save Category', 'gt-link-manager' ) );
		echo '</form>';

		echo '<hr /><h2>' . esc_html__( 'All Categories', 'gt-link-manager' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Slug', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Parent', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Count', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Actions', 'gt-link-manager' ) . '</th></tr></thead><tbody>';

		if ( empty( $categories ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No categories found.', 'gt-link-manager' ) . '</td></tr>';
		}

		foreach ( $categories as $category ) {
			$edit_url = add_query_arg( array( 'page' => 'gt-links-categories', 'edit' => (int) $category['id'] ), admin_url( 'admin.php' ) );
			$delete_url = wp_nonce_url( add_query_arg( array( 'page' => 'gt-links-categories', 'action' => 'delete', 'category_id' => (int) $category['id'] ), admin_url( 'admin.php' ) ), 'gt_link_category_delete_' . (int) $category['id'] );

			echo '<tr><td>' . esc_html( (string) $category['name'] ) . '</td><td><code>' . esc_html( (string) $category['slug'] ) . '</code></td><td>' . esc_html( $this->category_name_by_id( $categories, (int) $category['parent_id'] ) ) . '</td><td>' . (int) $category['count'] . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'gt-link-manager' ) . '</a> | <a href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'gt-link-manager' ) . '</a></td></tr>';
		}

		echo '</tbody></table></div>';
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$settings    = $this->settings->all();
		$diagnostics = get_option( 'gt_link_manager_diagnostics', array() );

		echo '<div class="wrap"><h1>' . esc_html__( 'GT Links Settings', 'gt-link-manager' ) . '</h1>';
		$this->render_notice();
		echo '<form method="post" action="">';
		wp_nonce_field( 'gt_link_settings_save' );
		echo '<input type="hidden" name="gt_settings_action" value="save_settings" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'base_prefix', __( 'Base Prefix', 'gt-link-manager' ), (string) $settings['base_prefix'], true );
		$this->render_redirect_type_field( (int) $settings['default_redirect_type'], 'default_redirect_type', __( 'Default Redirect Type', 'gt-link-manager' ) );
		$this->render_rel_field( implode( ',', (array) $settings['default_rel'] ), 'default_rel[]', __( 'Default Rel Attributes', 'gt-link-manager' ) );
		$this->render_checkbox_field( 'default_noindex', __( 'Default Noindex', 'gt-link-manager' ), __( 'Apply noindex to new links by default', 'gt-link-manager' ), ! empty( $settings['default_noindex'] ) );
		echo '</tbody></table>';
		submit_button( __( 'Save Settings', 'gt-link-manager' ) );
		echo '</form>';

		echo '<div class="gtlm-settings-actions">';

		echo '<form method="post" action="" style="display:inline-block;">';
		wp_nonce_field( 'gt_link_settings_save' );
		echo '<input type="hidden" name="gt_settings_action" value="flush_permalinks" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Flush Permalinks', 'gt-link-manager' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="" style="display:inline-block;">';
		wp_nonce_field( 'gt_link_settings_save' );
		echo '<input type="hidden" name="gt_settings_action" value="run_diagnostics" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Run Diagnostics', 'gt-link-manager' ) . '</button>';
		echo '</form>';

		echo '</div>';

		echo '<h2>' . esc_html__( 'Diagnostics', 'gt-link-manager' ) . '</h2>';
		if ( ! is_array( $diagnostics ) || empty( $diagnostics ) ) {
			echo '<p>' . esc_html__( 'No diagnostics run yet.', 'gt-link-manager' ) . '</p>';
		} else {
			$loopback = $diagnostics['loopback_ok'] ?? null;
			$label    = is_bool( $loopback ) ? ( $loopback ? __( 'OK', 'gt-link-manager' ) : __( 'Failed', 'gt-link-manager' ) ) : __( 'Skipped', 'gt-link-manager' );

			echo '<div class="gtlm-diagnostics-card">';
			echo '<table class="gtlm-diagnostics-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Checked At', 'gt-link-manager' ) . '</th><td>' . esc_html( (string) ( $diagnostics['checked_at'] ?? '-' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Prefix', 'gt-link-manager' ) . '</th><td><code>' . esc_html( (string) ( $diagnostics['prefix'] ?? '-' ) ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Tables', 'gt-link-manager' ) . '</th><td>' . ( ! empty( $diagnostics['tables_ok'] ) ? '<span class="gt-link-status gt-link-status--active">' . esc_html__( 'OK', 'gt-link-manager' ) . '</span>' : '<span class="gt-link-status gt-link-status--inactive">' . esc_html__( 'Failed', 'gt-link-manager' ) . '</span>' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Rewrite Rule', 'gt-link-manager' ) . '</th><td>' . ( ! empty( $diagnostics['rewrite_ok'] ) ? '<span class="gt-link-status gt-link-status--active">' . esc_html__( 'OK', 'gt-link-manager' ) . '</span>' : '<span class="gt-link-status gt-link-status--inactive">' . esc_html__( 'Missing', 'gt-link-manager' ) . '</span>' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Runtime Redirect', 'gt-link-manager' ) . '</th><td>' . ( true === $loopback ? '<span class="gt-link-status gt-link-status--active">' . esc_html( $label ) . '</span>' : '<span class="gt-link-status gt-link-status--inactive">' . esc_html( $label ) . '</span>' ) . '</td></tr>';
			if ( ! empty( $diagnostics['message'] ) ) {
				echo '<tr><th>' . esc_html__( 'Details', 'gt-link-manager' ) . '</th><td>' . esc_html( (string) $diagnostics['message'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}
		echo '</div>';
	}

	public function render_import_export_page(): void {
		if ( ! current_user_can( $this->links_capability( 'import_export' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$this->render_notice();
		$this->importer->render_page();
	}

	private function render_notice(): void {
		if ( ! isset( $_GET['gtlm_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( (string) wp_unslash( $_GET['gtlm_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'saved'                  => array( 'success', __( 'Saved successfully.', 'gt-link-manager' ) ),
			'deleted'                => array( 'success', __( 'Deleted permanently.', 'gt-link-manager' ) ),
			'trashed'                => array( 'success', __( 'Link moved to trash.', 'gt-link-manager' ) ),
			'restored'               => array( 'success', __( 'Link restored from trash.', 'gt-link-manager' ) ),
			'activated'              => array( 'success', __( 'Link activated.', 'gt-link-manager' ) ),
			'deactivated'            => array( 'success', __( 'Link deactivated.', 'gt-link-manager' ) ),
			'category_saved'         => array( 'success', __( 'Category saved.', 'gt-link-manager' ) ),
			'category_deleted'       => array( 'success', __( 'Category deleted.', 'gt-link-manager' ) ),
			'settings_saved'         => array( 'success', __( 'Settings updated.', 'gt-link-manager' ) ),
			'permalinks_flushed'     => array( 'success', __( 'Permalinks flushed.', 'gt-link-manager' ) ),
			'diagnostics_done'       => array( 'success', __( 'Diagnostics completed.', 'gt-link-manager' ) ),
			'invalid'                => array( 'error', __( 'Please enter required fields.', 'gt-link-manager' ) ),
			'invalid_category'       => array( 'error', __( 'Category name is required.', 'gt-link-manager' ) ),
			'save_failed'            => array( 'error', __( 'Save failed. Please check values.', 'gt-link-manager' ) ),
			'delete_failed'          => array( 'error', __( 'Delete failed.', 'gt-link-manager' ) ),
			'trash_failed'           => array( 'error', __( 'Could not move to trash.', 'gt-link-manager' ) ),
			'restore_failed'         => array( 'error', __( 'Could not restore link.', 'gt-link-manager' ) ),
			'activate_failed'        => array( 'error', __( 'Could not activate link.', 'gt-link-manager' ) ),
			'deactivate_failed'      => array( 'error', __( 'Could not deactivate link.', 'gt-link-manager' ) ),
			'category_save_failed'   => array( 'error', __( 'Category save failed.', 'gt-link-manager' ) ),
			'category_delete_failed' => array( 'error', __( 'Category delete failed.', 'gt-link-manager' ) ),
			'settings_unchanged'     => array( 'warning', __( 'No settings changed.', 'gt-link-manager' ) ),
			'import_done'            => array(
				'success',
				sprintf(
					/* translators: 1: imported, 2: updated, 3: skipped */
					__( 'Import complete. Imported: %1$d, Updated: %2$d, Skipped: %3$d.', 'gt-link-manager' ),
					isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
			),
			'import_failed'          => array( 'error', __( 'Import failed. Please check the file and try again.', 'gt-link-manager' ) ),
			'import_bad_columns'     => array( 'error', __( 'CSV columns are invalid. Required: name and url (or Destination URL in LinkCentral preset).', 'gt-link-manager' ) ),
			'preview_ready'          => array( 'success', __( 'Preview generated. Review mapping and run import.', 'gt-link-manager' ) ),
			'export_done'            => array( 'success', __( 'Export started.', 'gt-link-manager' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$type = (string) $map[ $notice ][0];
		$text = (string) $map[ $notice ][1];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	private function render_text_field( string $name, string $label, string $value, bool $required = false, string $type = 'text' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" class="regular-text" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . ' /></td></tr>';
	}

	private function render_textarea_field( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea></td></tr>';
	}

	private function render_checkbox_field( string $name, string $label, string $description, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $description ) . '</label></td></tr>';
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 */
	private function render_category_field( array $categories, int $selected_id ): void {
		echo '<tr><th scope="row"><label for="category_id">' . esc_html__( 'Category', 'gt-link-manager' ) . '</label></th><td><select name="category_id" id="category_id"><option value="0">' . esc_html__( 'None', 'gt-link-manager' ) . '</option>';
		foreach ( $categories as $category ) {
			echo '<option value="' . (int) $category['id'] . '" ' . selected( $selected_id, (int) $category['id'], false ) . '>' . esc_html( (string) $category['name'] ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private function render_redirect_type_field( int $current, string $name = 'redirect_type', string $label = '' ): void {
		if ( '' === $label ) {
			$label = __( 'Redirect Type', 'gt-link-manager' );
		}

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		foreach ( array( 301, 302, 307 ) as $type ) {
			echo '<label style="margin-right:12px;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . (int) $type . '" ' . checked( $current, $type, false ) . ' /> ' . (int) $type . '</label>';
		}
		echo '</td></tr>';
	}

	private function render_rel_field( string $rel_csv, string $name = 'rel[]', string $label = '' ): void {
		if ( '' === $label ) {
			$label = __( 'Rel Attributes', 'gt-link-manager' );
		}

		$selected = array_filter( array_map( 'sanitize_key', explode( ',', strtolower( $rel_csv ) ) ) );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		foreach ( array( 'nofollow', 'sponsored', 'ugc' ) as $value ) {
			echo '<label style="margin-right:12px;"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . checked( in_array( $value, $selected, true ), true, false ) . ' /> ' . esc_html( $value ) . '</label>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 */
	private function render_parent_category_field( array $categories, int $selected_id, int $editing_id ): void {
		echo '<tr><th scope="row"><label for="parent_id">' . esc_html__( 'Parent Category', 'gt-link-manager' ) . '</label></th><td><select name="parent_id" id="parent_id"><option value="0">' . esc_html__( 'None', 'gt-link-manager' ) . '</option>';
		foreach ( $categories as $category ) {
			if ( (int) $category['id'] === $editing_id ) {
				continue;
			}
			echo '<option value="' . (int) $category['id'] . '" ' . selected( $selected_id, (int) $category['id'], false ) . '>' . esc_html( (string) $category['name'] ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 */
	private function category_name_by_id( array $categories, int $id ): string {
		if ( $id <= 0 ) {
			return '—';
		}

		foreach ( $categories as $category ) {
			if ( (int) $category['id'] === $id ) {
				return (string) $category['name'];
			}
		}

		return '—';
	}

	private function sanitize_rel_from_post( mixed $rel ): string {
		if ( is_string( $rel ) ) {
			$rel = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $rel ) ) ) );
		}

		if ( ! is_array( $rel ) ) {
			return '';
		}

		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$clean   = array();
		foreach ( $rel as $value ) {
			$token = sanitize_key( (string) wp_unslash( $value ) );
			if ( in_array( $token, $allowed, true ) ) {
				$clean[] = $token;
			}
		}

		return implode( ',', array_unique( $clean ) );
	}

	private function links_capability( string $context ): string {
		return (string) apply_filters( 'gt_link_manager_capabilities', 'edit_posts', $context );
	}

	private function redirect_with_notice( string $url, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'gtlm_notice' => sanitize_key( $notice ) ), $url ) );
		exit;
	}
}
