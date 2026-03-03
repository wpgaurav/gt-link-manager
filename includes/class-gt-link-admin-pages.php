<?php
/**
 * Admin page rendering.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Admin_Pages {
	private GT_Link_DB $db;

	private GT_Link_Settings $settings;

	private GT_Link_Import $importer;

	public function __construct( GT_Link_DB $db, GT_Link_Settings $settings, GT_Link_Import $importer ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->importer = $importer;
	}

	public function render_links_page(): void {
		if ( ! current_user_can( $this->links_capability( 'links_page' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'gt-link-manager' ) );
		}

		require_once GT_LINK_MANAGER_PATH . 'includes/class-gt-link-list-table.php';

		$view        = isset( $_GET['link_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['link_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		$form     = is_array( $link ) ? wp_parse_args( $link, $defaults ) : $defaults;

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
			$edit_url   = add_query_arg(
				array(
					'page' => 'gt-links-categories',
					'edit' => (int) $category['id'],
				),
				admin_url( 'admin.php' )
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'gt-links-categories',
						'action'      => 'delete',
						'category_id' => (int) $category['id'],
					),
					admin_url( 'admin.php' )
				),
				'gt_link_category_delete_' . (int) $category['id']
			);

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

	private function links_capability( string $context ): string {
		return (string) apply_filters( 'gt_link_manager_capabilities', 'edit_posts', $context );
	}
}
