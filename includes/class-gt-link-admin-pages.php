<?php
/**
 * Admin page rendering.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Admin_Pages {
	private GTLM_DB $db;

	private GTLM_Settings $settings;

	private GTLM_Import $importer;

	public function __construct( GTLM_DB $db, GTLM_Settings $settings, GTLM_Import $importer ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->importer = $importer;
	}

	public function render_links_page(): void {
		if ( ! current_user_can( $this->links_capability( 'links_page' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		require_once GTLM_PATH . 'includes/class-gt-link-list-table.php';

		$view        = isset( $_GET['link_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['link_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_views = array( 'active', 'inactive', 'trash' );
		if ( ! in_array( $view, $valid_views, true ) ) {
			$view = '';
		}

		$categories = $this->db->get_categories();
		$table      = new GTLM_List_Table( $this->db, $categories, $this->settings->prefix(), $view );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'GT Links', 'gt-link-manager' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=gtlm-links-edit' ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'gt-link-manager' ) . '</a>';
		$this->render_notice();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="gtlm-links" />';
		if ( '' !== $view ) {
			echo '<input type="hidden" name="link_status" value="' . esc_attr( $view ) . '" />';
		}
		$table->search_box( esc_html__( 'Search links', 'gt-link-manager' ), 'gtlm-links-search' );
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

		$defaults         = array(
			'name'              => '',
			'url'               => '',
			'slug'              => '',
			'redirect_type'     => (int) $settings['default_redirect_type'],
			'rel'               => implode( ',', (array) $settings['default_rel'] ),
			'noindex'           => (int) $settings['default_noindex'],
			'link_mode'         => 'standard',
			'regex_replacement' => '',
			'priority'          => 10,
			'geo_mode'          => 'off',
			'geo_rules'         => '',
			'category_id'       => 0,
			'tags'              => '',
			'notes'             => '',
		);
		$form             = is_array( $link ) ? wp_parse_args( $link, $defaults ) : $defaults;
		$advanced_enabled = ! empty( $settings['enable_advanced_redirects'] );
		$geo_enabled      = ! empty( $settings['enable_geo_targeting'] );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $link_id > 0 ? __( 'Edit Link', 'gt-link-manager' ) : __( 'Add New Link', 'gt-link-manager' ) ) . '</h1>';
		$this->render_notice();
		echo '<div class="gtlm-card">';
		echo '<form method="post" action="">';
		wp_nonce_field( 'gtlm_link_save' );
		echo '<input type="hidden" name="gtlm_action" value="save_link" />';
		echo '<input type="hidden" name="link_id" value="' . (int) $link_id . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'name', __( 'Link Name', 'gt-link-manager' ), (string) $form['name'], true );
		$this->render_text_field( 'url', __( 'Destination URL', 'gt-link-manager' ), (string) $form['url'], true, 'url' );

		if ( $advanced_enabled ) {
			$this->render_link_mode_field( (string) $form['link_mode'] );
		} else {
			echo '<input type="hidden" name="link_mode" value="standard" />';
		}

		$this->render_text_field( 'slug', __( 'Slug', 'gt-link-manager' ), (string) $form['slug'], false );

		if ( $advanced_enabled ) {
			echo '<tr class="gtlm-field-regex-replacement" style="' . ( 'regex' !== $form['link_mode'] ? 'display:none;' : '' ) . '">';
			echo '<th scope="row"><label for="regex_replacement">' . esc_html__( 'Regex Replacement', 'gt-link-manager' ) . '</label></th>';
			echo '<td><input name="regex_replacement" id="regex_replacement" type="text" class="regular-text" value="' . esc_attr( (string) $form['regex_replacement'] ) . '" />';
			echo '<p class="description">' . esc_html__( 'Use $1, $2 for capture groups. If empty, capture groups are substituted in the Destination URL.', 'gt-link-manager' ) . '</p></td></tr>';

			echo '<tr class="gtlm-field-priority" style="' . ( 'regex' !== $form['link_mode'] ? 'display:none;' : '' ) . '">';
			echo '<th scope="row"><label for="priority">' . esc_html__( 'Priority', 'gt-link-manager' ) . '</label></th>';
			echo '<td><input name="priority" id="priority" type="number" class="small-text" value="' . (int) $form['priority'] . '" min="0" step="1" />';
			echo '<p class="description">' . esc_html__( 'Lower numbers are matched first.', 'gt-link-manager' ) . '</p></td></tr>';

			echo '<tr class="gtlm-field-conflict-warning" style="display:none;">';
			echo '<th scope="row"></th><td><div class="notice notice-warning inline"><p id="gtlm-conflict-message"></p></div></td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Branded URL Preview', 'gt-link-manager' ) . '</th><td>';
		echo '<span id="gtlm-branded-preview">-</span> <button type="button" class="button" id="gtlm-copy-preview">' . esc_html__( 'Copy URL', 'gt-link-manager' ) . '</button>';
		echo '</td></tr>';

		$this->render_redirect_type_field( (int) $form['redirect_type'] );
		$this->render_rel_field( (string) $form['rel'] );
		$this->render_checkbox_field( 'noindex', __( 'Noindex', 'gt-link-manager' ), __( 'Prevent indexing this redirect', 'gt-link-manager' ), ! empty( $form['noindex'] ) );
		$this->render_category_field( $categories, (int) $form['category_id'] );

		if ( $geo_enabled ) {
			$this->render_geo_rules_field( (string) $form['geo_mode'], (string) $form['geo_rules'], (int) $form['redirect_type'] );
		} else {
			echo '<input type="hidden" name="geo_mode" value="' . esc_attr( (string) $form['geo_mode'] ) . '" />';
			echo '<input type="hidden" name="geo_rules_json" value="' . esc_attr( (string) $form['geo_rules'] ) . '" />';
		}

		$this->render_text_field( 'tags', __( 'Tags (comma-separated)', 'gt-link-manager' ), (string) $form['tags'], false );
		$this->render_textarea_field( 'notes', __( 'Notes', 'gt-link-manager' ), (string) $form['notes'] );
		echo '</tbody></table>';

		echo '<div class="gtlm-submit-row">';
		submit_button( __( 'Save Link', 'gt-link-manager' ), 'primary', 'save_link', false );
		submit_button( __( 'Save & View All', 'gt-link-manager' ), 'secondary', 'save_view_all', false );
		submit_button( __( 'Save & Add Another', 'gt-link-manager' ), 'secondary', 'save_add_another', false );
		echo '</div>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
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

		echo '<div class="gtlm-card">';
		echo '<h2>' . esc_html( $editing_id > 0 ? __( 'Edit Category', 'gt-link-manager' ) : __( 'Add Category', 'gt-link-manager' ) ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'gtlm_category_save' );
		echo '<input type="hidden" name="gtlm_category_action" value="save_category" />';
		echo '<input type="hidden" name="category_id" value="' . (int) $category_form['id'] . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'name', __( 'Name', 'gt-link-manager' ), (string) $category_form['name'], true );
		$this->render_text_field( 'slug', __( 'Slug', 'gt-link-manager' ), (string) $category_form['slug'], false );
		$this->render_parent_category_field( $categories, (int) $category_form['parent_id'], (int) $category_form['id'] );
		$this->render_textarea_field( 'description', __( 'Description', 'gt-link-manager' ), (string) $category_form['description'] );
		echo '</tbody></table>';
		submit_button( __( 'Save Category', 'gt-link-manager' ) );
		echo '</form>';
		echo '</div>';

		echo '<div class="gtlm-card">';
		echo '<h2>' . esc_html__( 'All Categories', 'gt-link-manager' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Slug', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Parent', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Count', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Actions', 'gt-link-manager' ) . '</th></tr></thead><tbody>';

		if ( empty( $categories ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No categories found.', 'gt-link-manager' ) . '</td></tr>';
		}

		foreach ( $categories as $category ) {
			$edit_url   = add_query_arg(
				array(
					'page' => 'gtlm-links-categories',
					'edit' => (int) $category['id'],
				),
				admin_url( 'admin.php' )
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'gtlm-links-categories',
						'action'      => 'delete',
						'category_id' => (int) $category['id'],
					),
					admin_url( 'admin.php' )
				),
				'gtlm_category_delete_' . (int) $category['id']
			);

			echo '<tr><td>' . esc_html( (string) $category['name'] ) . '</td><td><code>' . esc_html( (string) $category['slug'] ) . '</code></td><td>' . esc_html( $this->category_name_by_id( $categories, (int) $category['parent_id'] ) ) . '</td><td>' . (int) $category['count'] . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'gt-link-manager' ) . '</a> | <a href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'gt-link-manager' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '</div>';
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$settings    = $this->settings->all();
		$diagnostics = get_option( 'gtlm_diagnostics', array() );

		echo '<div class="wrap"><h1>' . esc_html__( 'GT Links Settings', 'gt-link-manager' ) . '</h1>';
		$this->render_notice();

		echo '<div class="gtlm-card">';
		echo '<h2>' . esc_html__( 'General', 'gt-link-manager' ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'gtlm_settings_save' );
		echo '<input type="hidden" name="gtlm_settings_action" value="save_settings" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_text_field( 'base_prefix', __( 'Base Prefix', 'gt-link-manager' ), (string) $settings['base_prefix'], true );
		$this->render_redirect_type_field( (int) $settings['default_redirect_type'], 'default_redirect_type', __( 'Default Redirect Type', 'gt-link-manager' ) );
		$this->render_rel_field( implode( ',', (array) $settings['default_rel'] ), 'default_rel[]', __( 'Default Rel Attributes', 'gt-link-manager' ) );
		$this->render_checkbox_field( 'default_noindex', __( 'Default Noindex', 'gt-link-manager' ), __( 'Apply noindex to new links by default', 'gt-link-manager' ), ! empty( $settings['default_noindex'] ) );
		$this->render_checkbox_field( 'delete_data_on_uninstall', __( 'Delete Data on Uninstall', 'gt-link-manager' ), __( 'Remove all links, categories, and settings when the plugin is deleted', 'gt-link-manager' ), ! empty( $settings['delete_data_on_uninstall'] ) );
		$this->render_checkbox_field( 'enable_advanced_redirects', __( 'Advanced Redirects', 'gt-link-manager' ), __( 'Enable direct (prefix-free) and regex (pattern-based) redirect modes', 'gt-link-manager' ), ! empty( $settings['enable_advanced_redirects'] ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Geolocation Targeting', 'gt-link-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Send visitors from different countries to different destinations. The country is read from the header your CDN or web server already provides — no lookup service is contacted and no database file is required.', 'gt-link-manager' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_checkbox_field( 'enable_geo_targeting', __( 'Enable Geolocation', 'gt-link-manager' ), __( 'Allow individual links to route by visitor country', 'gt-link-manager' ), ! empty( $settings['enable_geo_targeting'] ) );
		$this->render_geo_method_field( (string) $settings['geo_detection_method'] );
		$this->render_text_field( 'geo_custom_header', __( 'Custom Country Header', 'gt-link-manager' ), (string) $settings['geo_custom_header'], false );
		echo '<tr><th scope="row"></th><td><p class="description">' . esc_html__( 'Only needed if your CDN sends the country in a header GT Link Manager does not already recognise, e.g. X-Geo-Country. Leave blank otherwise.', 'gt-link-manager' ) . '</p></td></tr>';
		$this->render_checkbox_field( 'geo_debug_header', __( 'Debug Header', 'gt-link-manager' ), __( 'Send X-GTLM-Country on geo redirects, showing the detected country and its source', 'gt-link-manager' ), ! empty( $settings['geo_debug_header'] ) );
		$this->render_geo_detection_status();
		echo '</tbody></table>';

		submit_button( __( 'Save Settings', 'gt-link-manager' ) );
		echo '</form>';
		echo '</div>';

		echo '<div class="gtlm-card">';
		echo '<h2>' . esc_html__( 'Tools', 'gt-link-manager' ) . '</h2>';
		echo '<div class="gtlm-settings-actions">';

		echo '<form method="post" action="" style="display:inline-block;">';
		wp_nonce_field( 'gtlm_settings_save' );
		echo '<input type="hidden" name="gtlm_settings_action" value="flush_permalinks" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Flush Permalinks', 'gt-link-manager' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="" style="display:inline-block;">';
		wp_nonce_field( 'gtlm_settings_save' );
		echo '<input type="hidden" name="gtlm_settings_action" value="run_diagnostics" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Run Diagnostics', 'gt-link-manager' ) . '</button>';
		echo '</form>';

		echo '</div>';
		echo '</div>';

		echo '<div class="gtlm-card">';
		echo '<h2>' . esc_html__( 'Diagnostics', 'gt-link-manager' ) . '</h2>';
		if ( ! is_array( $diagnostics ) || empty( $diagnostics ) ) {
			echo '<p>' . esc_html__( 'No diagnostics run yet.', 'gt-link-manager' ) . '</p>';
		} else {
			$loopback = $diagnostics['loopback_ok'] ?? null;
			$label    = is_bool( $loopback ) ? ( $loopback ? __( 'OK', 'gt-link-manager' ) : __( 'Failed', 'gt-link-manager' ) ) : __( 'Skipped', 'gt-link-manager' );

			echo '<table class="gtlm-diagnostics-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Checked At', 'gt-link-manager' ) . '</th><td>' . esc_html( (string) ( $diagnostics['checked_at'] ?? '-' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Prefix', 'gt-link-manager' ) . '</th><td><code>' . esc_html( (string) ( $diagnostics['prefix'] ?? '-' ) ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Tables', 'gt-link-manager' ) . '</th><td>' . ( ! empty( $diagnostics['tables_ok'] ) ? '<span class="gtlm-status gtlm-status--active">' . esc_html__( 'OK', 'gt-link-manager' ) . '</span>' : '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'Failed', 'gt-link-manager' ) . '</span>' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Rewrite Rule', 'gt-link-manager' ) . '</th><td>' . ( ! empty( $diagnostics['rewrite_ok'] ) ? '<span class="gtlm-status gtlm-status--active">' . esc_html__( 'OK', 'gt-link-manager' ) . '</span>' : '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'Missing', 'gt-link-manager' ) . '</span>' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Runtime Redirect', 'gt-link-manager' ) . '</th><td>' . ( true === $loopback ? '<span class="gtlm-status gtlm-status--active">' . esc_html( $label ) . '</span>' : '<span class="gtlm-status gtlm-status--inactive">' . esc_html( $label ) . '</span>' ) . '</td></tr>';
			if ( ! empty( $diagnostics['message'] ) ) {
				echo '<tr><th>' . esc_html__( 'Details', 'gt-link-manager' ) . '</th><td>' . esc_html( (string) $diagnostics['message'] ) . '</td></tr>';
			}

			if ( array_key_exists( 'geo_enabled', $diagnostics ) ) {
				$geo_country = (string) ( $diagnostics['geo_country'] ?? '' );
				$geo_sources = (array) ( $diagnostics['geo_sources'] ?? array() );

				echo '<tr><th>' . esc_html__( 'Geolocation', 'gt-link-manager' ) . '</th><td>';
				if ( empty( $diagnostics['geo_enabled'] ) ) {
					echo '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'Disabled', 'gt-link-manager' ) . '</span>';
				} elseif ( '' !== $geo_country ) {
					echo '<span class="gtlm-status gtlm-status--active">' . esc_html( $geo_country ) . '</span> <code>' . esc_html( (string) ( $diagnostics['geo_source'] ?? '' ) ) . '</code>';
				} else {
					echo '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'Enabled, but no country header found', 'gt-link-manager' ) . '</span>';
				}
				echo '</td></tr>';

				echo '<tr><th>' . esc_html__( 'Country Headers Seen', 'gt-link-manager' ) . '</th><td>';
				echo '' !== implode( '', $geo_sources ) ? '<code>' . esc_html( implode( ', ', $geo_sources ) ) . '</code>' : esc_html__( 'None — your CDN is not forwarding a country header.', 'gt-link-manager' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		echo '</div>';
	}

	public function render_import_export_page(): void {
		if ( ! current_user_can( $this->links_capability( 'import_export' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		$this->render_notice();
		$this->importer->render_page();
	}

	public function render_notice(): void {
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
			'invalid_regex'          => array( 'error', __( 'Invalid regex pattern. Please check the syntax.', 'gt-link-manager' ) ),
			'reserved_path'          => array( 'error', __( 'This path is reserved by WordPress and cannot be used for direct links.', 'gt-link-manager' ) ),
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

	/**
	 * Country routing repeater for the link editor.
	 *
	 * Rows are rendered server-side from stored rules; admin.js clones the
	 * template row for additions and reorders. Values post as parallel arrays.
	 */
	private function render_geo_rules_field( string $geo_mode, string $geo_rules, int $link_redirect_type ): void {
		$decoded  = GTLM_Geo::decode_rules( $geo_rules );
		$rules    = $decoded['rules'];
		$is_on    = 'off' !== $geo_mode;
		$statuses = array(
			0   => __( 'Inherit', 'gt-link-manager' ),
			302 => '302',
			307 => '307',
			301 => '301',
		);

		echo '<tr><th scope="row">' . esc_html__( 'Geolocation', 'gt-link-manager' ) . '</th><td>';
		echo '<label><input type="checkbox" name="geo_mode" value="targeted" id="gtlm-geo-toggle" ' . checked( $is_on, true, false ) . ' /> <strong>' . esc_html__( 'Send visitors to a different URL based on their country', 'gt-link-manager' ) . '</strong></label>';
		echo '<p class="description">' . esc_html__( 'Everyone else keeps using the Destination URL above.', 'gt-link-manager' ) . '</p>';
		echo '</td></tr>';

		echo '<tr class="gtlm-field-geo-rules" style="' . ( $is_on ? '' : 'display:none;' ) . '"><th scope="row">' . esc_html__( 'Country Rules', 'gt-link-manager' ) . '</th><td>';

		if ( 301 === $link_redirect_type ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This link uses a 301. Browsers cache a 301 permanently, which pins each visitor to whichever country they were in on their first click — so geo rules stop working for returning visitors. Switch Redirect Type to 302 above.', 'gt-link-manager' ) . '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'Rules are checked from the top down and the first country match wins, so put your most specific rules first. A country listed twice only ever matches its highest rule.', 'gt-link-manager' ) . '</p>';

		echo '<table class="gtlm-geo-rules widefat"><thead><tr>';
		echo '<th class="gtlm-geo-col-order">' . esc_html__( 'Order', 'gt-link-manager' ) . '</th>';
		echo '<th class="gtlm-geo-col-countries">' . esc_html__( 'When the visitor is in…', 'gt-link-manager' ) . '</th>';
		echo '<th class="gtlm-geo-col-url">' . esc_html__( 'Send them to', 'gt-link-manager' ) . '</th>';
		echo '<th class="gtlm-geo-col-status">' . esc_html__( 'Status', 'gt-link-manager' ) . '</th>';
		echo '<th class="gtlm-geo-col-actions"><span class="screen-reader-text">' . esc_html__( 'Actions', 'gt-link-manager' ) . '</span></th>';
		echo '</tr></thead><tbody id="gtlm-geo-rows">';

		if ( empty( $rules ) ) {
			$rules = array(
				array(
					'countries'     => array(),
					'url'           => '',
					'redirect_type' => 0,
				),
			);
		}

		foreach ( $rules as $index => $rule ) {
			$this->render_geo_rule_row( $rule, $statuses, (string) $index );
		}

		echo '</tbody>';

		// The fallback reads as the final row so precedence is visible top-to-bottom.
		echo '<tfoot><tr class="gtlm-geo-fallback-row">';
		echo '<td><span class="gtlm-geo-order-badge gtlm-geo-order-badge--last">' . esc_html__( 'Last', 'gt-link-manager' ) . '</span></td>';
		echo '<td><strong>' . esc_html__( 'Everyone else', 'gt-link-manager' ) . '</strong><br /><span class="description">' . esc_html__( 'Including visitors whose country cannot be determined', 'gt-link-manager' ) . '</span></td>';
		echo '<td colspan="3"><select name="geo_fallback" id="gtlm-geo-fallback">';
		echo '<option value="default" ' . selected( $decoded['fallback'], 'default', false ) . '>' . esc_html__( 'The main Destination URL', 'gt-link-manager' ) . '</option>';
		echo '<option value="block" ' . selected( $decoded['fallback'], 'block', false ) . '>' . esc_html__( 'Nothing — show a 404', 'gt-link-manager' ) . '</option>';
		echo '</select></td>';
		echo '</tr></tfoot></table>';

		echo '<p><button type="button" class="button" id="gtlm-geo-add-rule">' . esc_html__( '+ Add Rule', 'gt-link-manager' ) . '</button></p>';

		echo '<div id="gtlm-geo-warnings" role="status"></div>';

		// Rule tester: resolves against the form as it stands, before saving.
		echo '<div class="gtlm-geo-tester">';
		echo '<strong>' . esc_html__( 'Preview', 'gt-link-manager' ) . '</strong> ';
		echo '<label>' . esc_html__( 'A visitor from', 'gt-link-manager' ) . ' ';
		echo '<select id="gtlm-geo-test-country">';
		echo '<option value="">' . esc_html__( '— pick a country —', 'gt-link-manager' ) . '</option>';
		foreach ( GTLM_Geo::countries() as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select></label> ';
		echo '<span id="gtlm-geo-test-result" class="gtlm-geo-test-result" role="status" aria-live="polite"></span>';
		echo '<p class="description">' . esc_html__( 'Previews your unsaved rules in the browser. No request is made.', 'gt-link-manager' ) . '</p>';
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Privacy: the country comes from a header your CDN already sends. No IP address is read, no external service is called, and the country is never stored.', 'gt-link-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'A 404 fallback is not a security control: country headers can be forged on requests that reach your site without passing through your CDN.', 'gt-link-manager' ) . '</p>';

		// __INDEX__ is replaced by admin.js when a row is cloned.
		echo '<script type="text/html" id="gtlm-geo-row-template">';
		$this->render_geo_rule_row(
			array(
				'countries'     => array(),
				'url'           => '',
				'redirect_type' => 0,
			),
			$statuses,
			'__INDEX__'
		);
		echo '</script>';

		echo '</td></tr>';
	}

	/**
	 * @param array<string, mixed> $rule     Rule.
	 * @param array<int, string>   $statuses Status options.
	 * @param string               $index    Row index, so each row's country
	 *                                       multi-select posts as its own array
	 *                                       instead of flattening into one.
	 */
	private function render_geo_rule_row( array $rule, array $statuses, string $index ): void {
		$selected  = array_map( 'strval', (array) ( $rule['countries'] ?? array() ) );
		$countries = GTLM_Geo::countries();
		$groups    = GTLM_Geo::groups();

		echo '<tr class="gtlm-geo-rule" data-index="' . esc_attr( $index ) . '">';

		// data-label carries the column name into the stacked mobile layout,
		// where the header row is hidden.
		echo '<td class="gtlm-geo-order" data-label="' . esc_attr__( 'Order', 'gt-link-manager' ) . '">';
		// Not aria-hidden: the number is the rule's precedence, which is the
		// whole point of the column and must be announced.
		echo '<span class="gtlm-geo-order-badge"></span>';
		echo '<span class="gtlm-geo-move">';
		echo '<button type="button" class="button-link gtlm-geo-move-up" aria-label="' . esc_attr__( 'Move rule up', 'gt-link-manager' ) . '" title="' . esc_attr__( 'Move up', 'gt-link-manager' ) . '">&uarr;</button>';
		echo '<button type="button" class="button-link gtlm-geo-move-down" aria-label="' . esc_attr__( 'Move rule down', 'gt-link-manager' ) . '" title="' . esc_attr__( 'Move down', 'gt-link-manager' ) . '">&darr;</button>';
		echo '</span></td>';

		echo '<td class="gtlm-geo-countries-cell" data-label="' . esc_attr__( 'When the visitor is in…', 'gt-link-manager' ) . '">';

		// Quick picks cover the combinations most affiliate links actually use.
		echo '<span class="gtlm-geo-presets">';
		foreach ( $this->geo_quick_picks() as $preset ) {
			echo '<button type="button" class="button button-small gtlm-geo-preset" data-codes="' . esc_attr( implode( ',', $preset['codes'] ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: market name, e.g. "US + CA" */ __( 'Add %s to this rule', 'gt-link-manager' ), $preset['label'] ) ) . '">' . esc_html( $preset['label'] ) . '</button>';
		}
		echo '</span>';

		echo '<input type="search" class="gtlm-geo-filter" placeholder="' . esc_attr__( 'Type to filter countries…', 'gt-link-manager' ) . '" aria-label="' . esc_attr__( 'Filter the country list', 'gt-link-manager' ) . '" />';

		echo '<select name="geo_countries[' . esc_attr( $index ) . '][]" multiple size="6" class="gtlm-geo-countries" aria-label="' . esc_attr__( 'Countries this rule applies to', 'gt-link-manager' ) . '">';
		echo '<optgroup label="' . esc_attr__( 'Groups', 'gt-link-manager' ) . '">';
		foreach ( array_keys( $groups ) as $code ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . ( in_array( $code, $selected, true ) ? 'selected' : '' ) . '>' . esc_html( GTLM_Geo::label( $code ) ) . '</option>';
		}
		echo '</optgroup>';
		echo '<optgroup label="' . esc_attr__( 'Countries', 'gt-link-manager' ) . '">';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . ( in_array( $code, $selected, true ) ? 'selected' : '' ) . '>' . esc_html( $name ) . ' (' . esc_html( $code ) . ')</option>';
		}
		echo '</optgroup>';
		echo '</select>';

		echo '<div class="gtlm-geo-chips" aria-live="polite"></div>';
		echo '</td>';

		echo '<td data-label="' . esc_attr__( 'Send them to', 'gt-link-manager' ) . '"><input type="url" name="geo_urls[' . esc_attr( $index ) . ']" class="large-text gtlm-geo-url" value="' . esc_attr( (string) ( $rule['url'] ?? '' ) ) . '" placeholder="https://" aria-label="' . esc_attr__( 'Destination URL for this rule', 'gt-link-manager' ) . '" /></td>';

		echo '<td data-label="' . esc_attr__( 'Status', 'gt-link-manager' ) . '"><select name="geo_types[' . esc_attr( $index ) . ']" class="gtlm-geo-type" aria-label="' . esc_attr__( 'Redirect status code for this rule', 'gt-link-manager' ) . '">';
		foreach ( $statuses as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (int) ( $rule['redirect_type'] ?? 0 ), (int) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><button type="button" class="button-link gtlm-geo-remove-rule" aria-label="' . esc_attr__( 'Remove rule', 'gt-link-manager' ) . '">' . esc_html__( 'Remove', 'gt-link-manager' ) . '</button></td>';

		echo '</tr>';
	}

	/**
	 * Common market groupings offered as one-click country selections.
	 *
	 * @return array<int, array{label: string, codes: array<int, string>}>
	 */
	private function geo_quick_picks(): array {
		$picks = array(
			array(
				'label' => __( 'US + CA', 'gt-link-manager' ),
				'codes' => array( 'US', 'CA' ),
			),
			array(
				'label' => __( 'EU', 'gt-link-manager' ),
				'codes' => array( 'EU' ),
			),
			array(
				'label' => __( 'UK', 'gt-link-manager' ),
				'codes' => array( 'GB' ),
			),
			array(
				'label' => __( 'India', 'gt-link-manager' ),
				'codes' => array( 'IN' ),
			),
			array(
				'label' => __( 'AU + NZ', 'gt-link-manager' ),
				'codes' => array( 'AU', 'NZ' ),
			),
		);

		/**
		 * Filter the one-click country groupings shown in the link editor.
		 *
		 * @param array<int, array{label: string, codes: array<int, string>}> $picks Quick picks.
		 */
		return (array) apply_filters( 'gtlm_geo_quick_picks', $picks );
	}

	private function render_geo_method_field( string $current ): void {
		$methods = array(
			'auto'       => __( 'Auto-detect (recommended)', 'gt-link-manager' ),
			'cloudflare' => __( 'Cloudflare only', 'gt-link-manager' ),
			'custom'     => __( 'Custom header only', 'gt-link-manager' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Detection Method', 'gt-link-manager' ) . '</th><td>';
		foreach ( $methods as $value => $label ) {
			echo '<label style="margin-right:12px;"><input type="radio" name="geo_detection_method" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Auto-detect checks Cloudflare first, then CloudFront, Vercel, App Engine, and common web-server GeoIP variables. Choose "Cloudflare only" to ignore every other source — the strictest option, because Cloudflare overwrites its own country header at the edge and it cannot be forged by a visitor.', 'gt-link-manager' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Live readout of what the current request resolves to.
	 *
	 * The single most useful thing on this screen: it answers "is my CDN
	 * actually sending a country?" without the user having to guess.
	 */
	private function render_geo_detection_status(): void {
		GTLM_Geo::reset();
		$country = GTLM_Geo::country();
		$source  = GTLM_Geo::source();

		echo '<tr><th scope="row">' . esc_html__( 'Detected Now', 'gt-link-manager' ) . '</th><td>';

		echo '<p id="gtlm-geo-current">';
		if ( '' !== $country ) {
			printf(
				'<span class="gtlm-status gtlm-status--active">%s</span> <code>%s</code> <span class="description">%s</span>',
				esc_html( $country ),
				esc_html( $source ),
				esc_html( GTLM_Geo::label( $country ) )
			);
		} else {
			echo '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'No country detected', 'gt-link-manager' ) . '</span>';
		}
		echo '</p>';

		if ( '' === $country ) {
			$proxies = GTLM_Geo::proxy_signals();

			if ( empty( $proxies ) ) {
				// Nothing in front of PHP at all: expected on local and staging
				// installs, and not a sign that anything is misconfigured.
				echo '<p class="description">' . esc_html__( 'Expected here — nothing is proxying this site, so no country header exists to read. This is normal on a local, staging, or direct-to-origin install. Geolocation will start resolving once the site is served through a CDN. Use "Check Detection" below to confirm the plugin itself works regardless.', 'gt-link-manager' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html(
					sprintf(
					/* translators: %s: comma-separated proxy names */
						__( '%s is in front of this site but did not send a country header. On Cloudflare, turn on IP Geolocation for the zone (Rules → Settings, or Network → IP Geolocation). On another CDN, forward its country header and name it above.', 'gt-link-manager' ),
						implode( ', ', $proxies )
					)
				) . '</p>';
			}
		}

		echo '<p class="gtlm-geo-check-controls">';
		echo '<button type="button" class="button" id="gtlm-geo-check-btn" data-nonce="' . esc_attr( wp_create_nonce( 'gtlm_geo_check' ) ) . '">' . esc_html__( 'Check Detection', 'gt-link-manager' ) . '</button> ';
		echo '<label style="margin-inline-start:8px;">' . esc_html__( 'Test a country code:', 'gt-link-manager' ) . ' ';
		echo '<input type="text" id="gtlm-geo-check-simulate" class="small-text" maxlength="2" placeholder="US" style="text-transform:uppercase;" />';
		echo '</label>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The check reports every country header present on the request right now, so you can confirm your CDN setup. Entering a code additionally validates that it is usable in a rule.', 'gt-link-manager' ) . '</p>';

		echo '<div id="gtlm-geo-check-result" class="gtlm-geo-check-result" hidden></div>';

		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Privacy', 'gt-link-manager' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Country detection reads a two-letter code from a header your CDN already added to the request. The plugin never reads the visitor\'s IP address, never calls an external geolocation service, and never stores or logs the country — it exists only for that one request, to pick a destination.', 'gt-link-manager' ) . '</p>';
		echo '<p class="description">' . sprintf(
			/* translators: %s: link to the Privacy Settings screen */
			esc_html__( 'Suggested wording for your privacy policy is available under %s.', 'gt-link-manager' ),
			'<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'Settings → Privacy → Policy Guide', 'gt-link-manager' ) . '</a>'
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'If you added click tracking through the gtlm_before_redirect hook, that integration receives the country and may store it — disclose it there.', 'gt-link-manager' ) . '</p>';

		echo '</td></tr>';
	}

	private function render_link_mode_field( string $current ): void {
		$modes = array(
			'standard' => __( 'Standard (with prefix)', 'gt-link-manager' ),
			'direct'   => __( 'Direct (no prefix)', 'gt-link-manager' ),
			'regex'    => __( 'Regex (pattern match)', 'gt-link-manager' ),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Link Mode', 'gt-link-manager' ) . '</th><td>';
		foreach ( $modes as $value => $label ) {
			echo '<label style="margin-right:12px;"><input type="radio" name="link_mode" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . ' class="gtlm-link-mode-radio" /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description gtlm-mode-hint" data-mode="standard">' . esc_html__( 'URL: yoursite.com/prefix/slug', 'gt-link-manager' ) . '</p>';
		echo '<p class="description gtlm-mode-hint" data-mode="direct" style="display:none;">' . esc_html__( 'URL: yoursite.com/path — redirects without the prefix.', 'gt-link-manager' ) . '</p>';
		echo '<p class="description gtlm-mode-hint" data-mode="regex" style="display:none;">' . esc_html__( 'Slug is a regex pattern matched against the request path. Use capture groups ($1, $2) in the destination URL.', 'gt-link-manager' ) . '</p>';
		echo '</td></tr>';
	}

	private function links_capability( string $context ): string {
		return (string) apply_filters( 'gtlm_capabilities', 'edit_posts', $context );
	}
}
