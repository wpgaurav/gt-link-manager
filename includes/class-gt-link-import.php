<?php
/**
 * CSV import/export.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Import {
	private GT_Link_DB $db;

	private GT_Link_Settings $settings;

	private const PREVIEW_TRANSIENT_PREFIX = 'gt_link_import_preview_';

	public function __construct( GT_Link_DB $db, GT_Link_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page = sanitize_key( (string) wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'gt-links-import-export' !== $page ) {
			return;
		}

		if ( ! current_user_can( (string) apply_filters( 'gt_link_manager_capabilities', 'edit_posts', 'import_export' ) ) ) {
			return;
		}

		if ( ! isset( $_POST['gt_import_export_action'] ) ) {
			return;
		}

		$action = sanitize_key( (string) wp_unslash( $_POST['gt_import_export_action'] ) );
		if ( ! in_array( $action, array( 'preview_csv', 'import_csv', 'export_csv' ), true ) ) {
			return;
		}

		check_admin_referer( 'gt_link_import_export' );

		if ( 'export_csv' === $action ) {
			$this->export_csv();
		}

		if ( 'preview_csv' === $action ) {
			$this->preview_csv();
		}

		$this->import_csv();
	}

	public function render_page(): void {
		$preview = $this->get_preview_state();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import / Export', 'gt-link-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Import links from CSV or export links with optional filters.', 'gt-link-manager' ) . '</p>';

		$this->render_export_form();
		echo '<hr />';

		if ( is_array( $preview ) ) {
			$this->render_import_mapping_form( $preview );
		} else {
			$this->render_import_upload_form();
		}

		echo '</div>';
	}

	private function render_export_form(): void {
		$categories = $this->db->get_categories();

		echo '<h2>' . esc_html__( 'Export', 'gt-link-manager' ) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field( 'gt_link_import_export' );
		echo '<input type="hidden" name="gt_import_export_action" value="export_csv" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="export_search">' . esc_html__( 'Search', 'gt-link-manager' ) . '</label></th><td><input type="text" class="regular-text" name="export_search" id="export_search" value="" /></td></tr>';

		echo '<tr><th scope="row"><label for="export_category">' . esc_html__( 'Category', 'gt-link-manager' ) . '</label></th><td><select name="export_category_id" id="export_category"><option value="0">' . esc_html__( 'All categories', 'gt-link-manager' ) . '</option>';
		foreach ( $categories as $category ) {
			echo '<option value="' . (int) $category['id'] . '">' . esc_html( (string) $category['name'] ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="export_type">' . esc_html__( 'Redirect Type', 'gt-link-manager' ) . '</label></th><td><select name="export_redirect_type" id="export_type"><option value="0">' . esc_html__( 'All types', 'gt-link-manager' ) . '</option><option value="301">301</option><option value="302">302</option><option value="307">307</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="export_rel">' . esc_html__( 'Rel', 'gt-link-manager' ) . '</label></th><td><select name="export_rel" id="export_rel"><option value="">' . esc_html__( 'All rel values', 'gt-link-manager' ) . '</option><option value="nofollow">nofollow</option><option value="sponsored">sponsored</option><option value="ugc">ugc</option></select></td></tr>';
		echo '</tbody></table>';
		submit_button( esc_html__( 'Export CSV', 'gt-link-manager' ), 'secondary' );
		echo '</form>';
	}

	private function render_import_upload_form(): void {
		echo '<h2>' . esc_html__( 'Import (Step 1: Upload & Preview)', 'gt-link-manager' ) . '</h2>';
		echo '<form method="post" action="" enctype="multipart/form-data" id="gtlm-import-form">';
		wp_nonce_field( 'gt_link_import_export' );
		echo '<input type="hidden" name="gt_import_export_action" value="preview_csv" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="gt-import-file">' . esc_html__( 'CSV File', 'gt-link-manager' ) . '</label></th><td><input type="file" name="import_file" id="gt-import-file" accept=".csv,text/csv" required /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Preset', 'gt-link-manager' ) . '</th><td><label><input type="radio" name="preset" value="generic" checked /> ' . esc_html__( 'Generic', 'gt-link-manager' ) . '</label><br /><label><input type="radio" name="preset" value="linkcentral" /> ' . esc_html__( 'LinkCentral', 'gt-link-manager' ) . '</label><br /><label><input type="radio" name="preset" value="prettylinks" /> ' . esc_html__( 'Pretty Links', 'gt-link-manager' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( esc_html__( 'Preview CSV', 'gt-link-manager' ) );
		echo '<div id="gtlm-import-progress-wrap" style="display:none;"><p>' . esc_html__( 'Processing file...', 'gt-link-manager' ) . '</p><progress id="gtlm-import-progress"></progress></div>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $preview Preview state.
	 */
	private function render_import_mapping_form( array $preview ): void {
		$header = isset( $preview['header'] ) && is_array( $preview['header'] ) ? $preview['header'] : array();
		$rows   = isset( $preview['rows'] ) && is_array( $preview['rows'] ) ? $preview['rows'] : array();
		$preset = isset( $preview['preset'] ) ? sanitize_key( (string) $preview['preset'] ) : 'generic';

		echo '<h2>' . esc_html__( 'Import (Step 2: Map Columns & Run)', 'gt-link-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Map CSV columns to GT Link fields, then import.', 'gt-link-manager' ) . '</p>';

		echo '<h3>' . esc_html__( 'Preview (first 5 rows)', 'gt-link-manager' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $header as $column ) {
			echo '<th>' . esc_html( (string) $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="' . (int) max( 1, count( $header ) ) . '">' . esc_html__( 'No sample rows found.', 'gt-link-manager' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $value ) {
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$defaults = $this->default_map_from_header( $header, $preset );
		$fields   = array(
			'name'          => __( 'Name', 'gt-link-manager' ),
			'slug'          => __( 'Slug', 'gt-link-manager' ),
			'url'           => __( 'URL', 'gt-link-manager' ),
			'redirect_type' => __( 'Redirect Type', 'gt-link-manager' ),
			'rel'           => __( 'Rel', 'gt-link-manager' ),
			'noindex'       => __( 'Noindex', 'gt-link-manager' ),
			'category'      => __( 'Category', 'gt-link-manager' ),
			'tags'          => __( 'Tags', 'gt-link-manager' ),
			'notes'         => __( 'Notes', 'gt-link-manager' ),
		);

		echo '<form method="post" action="" id="gtlm-import-form">';
		wp_nonce_field( 'gt_link_import_export' );
		echo '<input type="hidden" name="gt_import_export_action" value="import_csv" />';
		echo '<input type="hidden" name="preview_token" value="' . esc_attr( (string) $preview['token'] ) . '" />';
		echo '<input type="hidden" name="preset" value="' . esc_attr( $preset ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $label ) {
			echo '<tr><th scope="row"><label for="map_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select name="map[' . esc_attr( $key ) . ']" id="map_' . esc_attr( $key ) . '">';
			echo '<option value="-1">' . esc_html__( 'Ignore', 'gt-link-manager' ) . '</option>';
			foreach ( $header as $index => $column ) {
				echo '<option value="' . (int) $index . '" ' . selected( (int) ( $defaults[ $key ] ?? -1 ), (int) $index, false ) . '>' . esc_html( (string) $column ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Duplicate Slug Handling', 'gt-link-manager' ) . '</th><td>';
		echo '<label><input type="radio" name="duplicate_mode" value="skip" checked /> ' . esc_html__( 'Skip existing', 'gt-link-manager' ) . '</label><br />';
		echo '<label><input type="radio" name="duplicate_mode" value="overwrite" /> ' . esc_html__( 'Overwrite existing', 'gt-link-manager' ) . '</label><br />';
		echo '<label><input type="radio" name="duplicate_mode" value="suffix" /> ' . esc_html__( 'Auto-suffix slug', 'gt-link-manager' ) . '</label>';
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( esc_html__( 'Run Import', 'gt-link-manager' ) );
		echo '<div id="gtlm-import-progress-wrap" style="display:none;"><p>' . esc_html__( 'Import in progress...', 'gt-link-manager' ) . '</p><progress id="gtlm-import-progress"></progress></div>';
		echo '</form>';
	}

	private function export_csv(): void {
		// Nonce verified in handle_actions() before this method is called.
		$filters = array(
			'search'        => sanitize_text_field( (string) wp_unslash( $_POST['export_search'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'category_id'   => absint( $_POST['export_category_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'redirect_type' => absint( $_POST['export_redirect_type'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'rel'           => sanitize_key( (string) wp_unslash( $_POST['export_rel'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$rows = $this->db->list_links_for_export( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=gt-links-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, array( 'name', 'slug', 'url', 'redirect_type', 'rel', 'noindex', 'category', 'tags', 'notes' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

		$categories = $this->db->get_categories();
		$cat_map    = array();
		foreach ( $categories as $category ) {
			$cat_map[ (int) $category['id'] ] = (string) $category['name'];
		}

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$output,
				array(
					(string) $row['name'],
					(string) $row['slug'],
					(string) $row['url'],
					(int) $row['redirect_type'],
					(string) $row['rel'],
					(int) $row['noindex'],
					$cat_map[ (int) $row['category_id'] ] ?? '',
					(string) $row['tags'],
					(string) $row['notes'],
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function preview_csv(): void {
		$existing_preview = $this->get_preview_state();
		if ( is_array( $existing_preview ) ) {
			$this->cleanup_preview( $existing_preview );
		}

		if ( ! isset( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_notice( 'import_failed' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$uploaded = wp_handle_upload(
			$_FILES['import_file'], // phpcs:ignore WordPress.Security.NonceVerification.Missing
			array( 'test_form' => false )
		);

		if ( ! is_array( $uploaded ) || empty( $uploaded['file'] ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		$file_path = (string) $uploaded['file'];
		$handle    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->redirect_notice( 'import_failed' );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->redirect_notice( 'import_failed' );
		}

		$rows = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
			if ( false === $row || ! is_array( $row ) ) {
				break;
			}
			$rows[] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$preset = isset( $_POST['preset'] ) ? sanitize_key( (string) wp_unslash( $_POST['preset'] ) ) : 'generic'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = wp_generate_uuid4();

		$state = array(
			'token'      => $token,
			'file_path'  => $file_path,
			'header'     => array_values( array_map( 'sanitize_text_field', $header ) ),
			'rows'       => $rows,
			'preset'     => $preset,
			'created_at' => time(),
		);

		set_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id(), $state, HOUR_IN_SECONDS );
		$this->redirect_notice( 'preview_ready' );
	}

	private function import_csv(): void {
		$preview = $this->get_preview_state();
		if ( ! is_array( $preview ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		// Nonce verified in handle_actions() before this method is called.
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['preview_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $token || (string) ( $preview['token'] ?? '' ) !== $token ) {
			$this->redirect_notice( 'import_failed' );
		}

		$file_path = (string) ( $preview['file_path'] ?? '' );
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		$map = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['map'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $map['name'], $map['url'] ) || (int) $map['name'] < 0 || (int) $map['url'] < 0 ) {
			$this->redirect_notice( 'import_bad_columns' );
		}

		$mode = isset( $_POST['duplicate_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['duplicate_mode'] ) ) : 'skip'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $mode, array( 'skip', 'overwrite', 'suffix' ), true ) ) {
			$mode = 'skip';
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->redirect_notice( 'import_failed' );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->redirect_notice( 'import_failed' );
		}

		$imported = 0;
		$updated  = 0;
		$skipped  = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
			if ( ! is_array( $row ) ) {
				continue;
			}

			$data = $this->row_to_link_data( $row, $map );
			if ( '' === $data['name'] || '' === $data['url'] ) {
				++$skipped;
				continue;
			}

			$existing = $this->db->get_link_by_slug( (string) $data['slug'] );
			if ( is_array( $existing ) ) {
				if ( 'skip' === $mode ) {
					++$skipped;
					continue;
				}

				if ( 'overwrite' === $mode ) {
					if ( $this->db->update_link( (int) $existing['id'], $data ) ) {
						++$updated;
					} else {
						++$skipped;
					}
					continue;
				}

				$data['slug'] = $this->next_available_slug( (string) $data['slug'] );
			}

			$link_id = $this->db->insert_link( $data );
			if ( $link_id > 0 ) {
				++$imported;
			} else {
				++$skipped;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->cleanup_preview( $preview );

		$this->redirect_notice(
			'import_done',
			array(
				'imported' => $imported,
				'updated'  => $updated,
				'skipped'  => $skipped,
			)
		);
	}

	/**
	 * @param array<int, string> $header Header.
	 * @return array<string, int>
	 */
	private function default_map_from_header( array $header, string $preset ): array {
		$normalized = array();
		foreach ( $header as $index => $column ) {
			$normalized[ strtolower( trim( (string) $column ) ) ] = (int) $index;
		}

		$map = array(
			'name'          => $normalized['name'] ?? -1,
			'slug'          => $normalized['slug'] ?? -1,
			'url'           => $normalized['url'] ?? ( $normalized['destination_url'] ?? -1 ),
			'redirect_type' => $normalized['redirect_type'] ?? -1,
			'rel'           => $normalized['rel'] ?? -1,
			'noindex'       => $normalized['noindex'] ?? -1,
			'category'      => $normalized['category'] ?? -1,
			'tags'          => $normalized['tags'] ?? -1,
			'notes'         => $normalized['notes'] ?? -1,
		);

		if ( 'linkcentral' === $preset ) {
			$map['name']          = $normalized['link name'] ?? $map['name'];
			$map['slug']          = $normalized['short slug'] ?? $map['slug'];
			$map['url']           = $normalized['destination url'] ?? $map['url'];
			$map['redirect_type'] = $normalized['redirect type'] ?? $map['redirect_type'];
			$map['rel']           = $normalized['rel attributes'] ?? $map['rel'];
			$map['noindex']       = $normalized['noindex'] ?? $map['noindex'];
			$map['category']      = $normalized['category'] ?? $map['category'];
			$map['tags']          = $normalized['tags'] ?? $map['tags'];
			$map['notes']         = $normalized['notes'] ?? $map['notes'];
		}

		if ( 'prettylinks' === $preset ) {
			$map['name']          = $normalized['name'] ?? $map['name'];
			$map['slug']          = $normalized['slug'] ?? $map['slug'];
			$map['url']           = $normalized['url'] ?? $map['url'];
			$map['redirect_type'] = $normalized['redirect_type'] ?? $map['redirect_type'];
			$map['rel']           = $normalized['nofollow'] ?? $map['rel'];
			$map['notes']         = $normalized['description'] ?? $map['notes'];
			$map['category']      = $normalized['link_categories'] ?? ( $normalized['category_id'] ?? $map['category'] );
		}

		return $map;
	}

	/**
	 * @param array<int, string> $row Row.
	 * @param array<string, int> $map Map.
	 * @return array<string, mixed>
	 */
	private function row_to_link_data( array $row, array $map ): array {
		$get = static function ( string $key ) use ( $row, $map ): string {
			$idx = isset( $map[ $key ] ) ? (int) $map[ $key ] : -1;
			if ( $idx < 0 || ! isset( $row[ $idx ] ) ) {
				return '';
			}

			return trim( (string) $row[ $idx ] );
		};

		$name = sanitize_text_field( $get( 'name' ) );
		$url  = esc_url_raw( $get( 'url' ) );
		$slug = sanitize_title( $get( 'slug' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		$redirect_type = (int) $get( 'redirect_type' );
		if ( ! in_array( $redirect_type, array( 301, 302, 307 ), true ) ) {
			$redirect_type = (int) ( $this->settings->all()['default_redirect_type'] ?? 301 );
		}

		$category_id = $this->resolve_category_id( $get( 'category' ) );
		$raw_rel     = $get( 'rel' );
		if ( '1' === $raw_rel ) {
			$raw_rel = 'nofollow';
		} elseif ( '0' === $raw_rel ) {
			$raw_rel = '';
		}
		$rel_tokens  = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', str_replace( ' ', ',', strtolower( $raw_rel ) ) ) ) ) );
		$allowed_rel = array_intersect( $rel_tokens, array( 'nofollow', 'sponsored', 'ugc' ) );
		$noindex_raw = strtolower( $get( 'noindex' ) );

		return array(
			'name'          => $name,
			'slug'          => $slug,
			'url'           => $url,
			'redirect_type' => $redirect_type,
			'rel'           => implode( ',', array_unique( $allowed_rel ) ),
			'noindex'       => in_array( $noindex_raw, array( '1', 'yes', 'true' ), true ) ? 1 : 0,
			'category_id'   => $category_id,
			'tags'          => sanitize_text_field( $get( 'tags' ) ),
			'notes'         => sanitize_textarea_field( $get( 'notes' ) ),
		);
	}

	private function resolve_category_id( string $category_name ): int {
		$category_name = sanitize_text_field( $category_name );
		if ( '' === $category_name ) {
			return 0;
		}

		$slug = sanitize_title( $category_name );
		$all  = $this->db->get_categories();
		foreach ( $all as $category ) {
			if ( $slug === (string) $category['slug'] || strtolower( $category_name ) === strtolower( (string) $category['name'] ) ) {
				return (int) $category['id'];
			}
		}

		return $this->db->insert_category(
			array(
				'name'      => $category_name,
				'slug'      => $slug,
				'parent_id' => 0,
			)
		);
	}

	private function next_available_slug( string $base_slug ): string {
		$base_slug = sanitize_title( $base_slug );
		if ( '' === $base_slug ) {
			$base_slug = 'link';
		}

		$slug = $base_slug;
		$i    = 2;
		while ( null !== $this->db->get_link_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $i;
			++$i;
		}

		return $slug;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_preview_state(): ?array {
		$state = get_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $preview Preview.
	 */
	private function cleanup_preview( array $preview ): void {
		if ( ! empty( $preview['file_path'] ) && is_string( $preview['file_path'] ) && file_exists( $preview['file_path'] ) ) {
			wp_delete_file( $preview['file_path'] );
		}
		delete_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
	}

	/**
	 * @param array<string, int> $stats Stats.
	 */
	private function redirect_notice( string $notice, array $stats = array() ): void {
		$args = array( 'gtlm_notice' => sanitize_key( $notice ) );
		foreach ( array( 'imported', 'updated', 'skipped' ) as $key ) {
			if ( isset( $stats[ $key ] ) ) {
				$args[ $key ] = absint( $stats[ $key ] );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=gt-links-import-export' ) ) );
		exit;
	}
}
