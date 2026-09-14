<?php
/**
 * Links list table.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class GTLM_List_Table extends WP_List_Table {
	private GTLM_DB $db;

	private string $prefix;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $categories;

	/**
	 * Current view context: '' (all non-trashed), 'active', 'inactive', 'trash'.
	 */
	private string $view;

	/**
	 * @param array<int, array<string, mixed>> $categories Categories.
	 */
	public function __construct( GTLM_DB $db, array $categories, string $prefix, string $view = '' ) {
		$this->db         = $db;
		$this->categories = $categories;
		$this->prefix     = sanitize_title_with_dashes( $prefix );
		$this->view       = $view;

		parent::__construct(
			array(
				'singular' => 'gtlm_link',
				'plural'   => 'gtlm_links',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function get_table_classes(): array {
		$classes   = parent::get_table_classes();
		$classes[] = 'gtlm-links-table';

		return $classes;
	}

	/**
	 * Column definitions shared between get_columns() and screen options registration.
	 *
	 * @return array<string, string>
	 */
	public static function define_columns(): array {
		return array(
			'cb'            => '<input type="checkbox" />',
			'id'            => esc_html__( 'ID', 'gt-link-manager' ),
			'name'          => esc_html__( 'Name', 'gt-link-manager' ),
			'branded_url'   => esc_html__( 'Branded URL', 'gt-link-manager' ),
			'url'           => esc_html__( 'Destination', 'gt-link-manager' ),
			'link_mode'     => esc_html__( 'Mode', 'gt-link-manager' ),
			'geo'           => esc_html__( 'Geo', 'gt-link-manager' ),
			'redirect_type' => esc_html__( 'Type', 'gt-link-manager' ),
			'rel'           => esc_html__( 'Rel', 'gt-link-manager' ),
			'status'        => esc_html__( 'Status', 'gt-link-manager' ),
			'category'      => esc_html__( 'Category', 'gt-link-manager' ),
			'tags'          => esc_html__( 'Tags', 'gt-link-manager' ),
			'total_clicks'  => esc_html__( 'Clicks', 'gt-link-manager' ),
			'created_at'    => esc_html__( 'Created', 'gt-link-manager' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return (array) apply_filters( 'gtlm_link_columns', self::define_columns() );
	}

	/**
	 * @return array<string, array<int, string|bool>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'id'            => array( 'id', true ),
			'name'          => array( 'name', false ),
			'branded_url'   => array( 'slug', false ),
			'url'           => array( 'url', false ),
			'link_mode'     => array( 'link_mode', false ),
			'redirect_type' => array( 'redirect_type', false ),
			'rel'           => array( 'rel', false ),
			'status'        => array( 'is_active', false ),
			'category'      => array( 'category_id', false ),
			'total_clicks'  => array( 'total_clicks', false ),
			'created_at'    => array( 'created_at', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		if ( 'trash' === $this->view ) {
			return array(
				'bulk_restore'          => esc_html__( 'Restore', 'gt-link-manager' ),
				'bulk_permanent_delete' => esc_html__( 'Delete Permanently', 'gt-link-manager' ),
			);
		}

		return array(
			'bulk_trash'         => esc_html__( 'Move to Trash', 'gt-link-manager' ),
			'bulk_activate'      => esc_html__( 'Activate', 'gt-link-manager' ),
			'bulk_deactivate'    => esc_html__( 'Deactivate', 'gt-link-manager' ),
			'bulk_301'           => esc_html__( 'Set 301', 'gt-link-manager' ),
			'bulk_302'           => esc_html__( 'Set 302', 'gt-link-manager' ),
			'bulk_307'           => esc_html__( 'Set 307', 'gt-link-manager' ),
			'bulk_rel_none'      => esc_html__( 'Clear rel', 'gt-link-manager' ),
			'bulk_rel_nofollow'  => esc_html__( 'Set rel: nofollow', 'gt-link-manager' ),
			'bulk_rel_sponsored' => esc_html__( 'Set rel: sponsored', 'gt-link-manager' ),
			'bulk_rel_ugc'       => esc_html__( 'Set rel: ugc', 'gt-link-manager' ),
			'bulk_set_category'  => esc_html__( 'Set category', 'gt-link-manager' ),
		);
	}

	/**
	 * Render view links (All | Active | Inactive | Trash).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$base_url = admin_url( 'admin.php?page=gtlm-links' );
		$all      = $this->db->count_links( array() );
		$active   = $this->db->count_links( array( 'status' => 'active' ) );
		$inactive = $this->db->count_links( array( 'status' => 'inactive' ) );
		$trash    = $this->db->count_links( array( 'trashed' => true ) );

		$views = array();

		$class        = '' === $this->view ? 'current' : '';
		$views['all'] = '<a href="' . esc_url( $base_url ) . '" class="' . $class . '">' . sprintf(
			/* translators: %s: number of links */
			esc_html__( 'All %s', 'gt-link-manager' ),
			'<span class="count">(' . $all . ')</span>'
		) . '</a>';

		$class           = 'active' === $this->view ? 'current' : '';
		$views['active'] = '<a href="' . esc_url( add_query_arg( 'link_status', 'active', $base_url ) ) . '" class="' . $class . '">' . sprintf(
			/* translators: %s: number of links */
			esc_html__( 'Active %s', 'gt-link-manager' ),
			'<span class="count">(' . $active . ')</span>'
		) . '</a>';

		$class             = 'inactive' === $this->view ? 'current' : '';
		$views['inactive'] = '<a href="' . esc_url( add_query_arg( 'link_status', 'inactive', $base_url ) ) . '" class="' . $class . '">' . sprintf(
			/* translators: %s: number of links */
			esc_html__( 'Inactive %s', 'gt-link-manager' ),
			'<span class="count">(' . $inactive . ')</span>'
		) . '</a>';

		$class          = 'trash' === $this->view ? 'current' : '';
		$views['trash'] = '<a href="' . esc_url( add_query_arg( 'link_status', 'trash', $base_url ) ) . '" class="' . $class . '">' . sprintf(
			/* translators: %s: number of links */
			esc_html__( 'Trash %s', 'gt-link-manager' ),
			'<span class="count">(' . $trash . ')</span>'
		) . '</a>';

		return $views;
	}

	public function no_items(): void {
		if ( 'trash' === $this->view ) {
			echo esc_html__( 'No links in the trash.', 'gt-link-manager' );
			return;
		}

		echo '<p>' . esc_html__( 'No links found.', 'gt-link-manager' ) . '</p>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=gtlm-links-edit' ) ) . '" class="button button-primary">' . esc_html__( 'Create your first link', 'gt-link-manager' ) . '</a>';
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_cb( $item ): string {
		$id = (int) $item['id'];

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input type="checkbox" id="cb-select-%1$d" name="link_ids[]" value="%1$d" />',
			$id,
			esc_html(
				sprintf(
					/* translators: %s: link name. */
					__( 'Select %s', 'gt-link-manager' ),
					(string) ( $item['name'] ?? '' )
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_name( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'    => 'gtlm-links-edit',
				'link_id' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$mode = (string) ( $item['link_mode'] ?? 'standard' );
		if ( 'direct' === $mode ) {
			$branded_url = home_url( '/' . (string) $item['slug'] );
		} elseif ( 'regex' === $mode ) {
			$branded_url = '';
		} else {
			$branded_url = home_url( '/' . trim( $this->prefix, '/' ) . '/' . (string) $item['slug'] );
		}

		if ( 'trash' === $this->view ) {
			$restore_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'gtlm-links',
						'action' => 'restore',
						'link'   => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'gtlm_restore_' . (int) $item['id']
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'gtlm-links',
						'action' => 'permanent_delete',
						'link'   => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'gtlm_permanent_delete_' . (int) $item['id']
			);

			$actions = array(
				'restore' => '<a href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore', 'gt-link-manager' ) . '</a>',
				'delete'  => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete">' . esc_html__( 'Delete Permanently', 'gt-link-manager' ) . '</a>',
			);

			return '<strong>' . esc_html( (string) $item['name'] ) . '</strong>' . $this->row_actions( $actions );
		}

		$trash_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'gtlm-links',
					'action' => 'trash',
					'link'   => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'gtlm_trash_' . (int) $item['id']
		);

		$is_active     = ! empty( $item['is_active'] );
		$toggle_action = $is_active ? 'deactivate' : 'activate';
		$toggle_label  = $is_active ? esc_html__( 'Deactivate', 'gt-link-manager' ) : esc_html__( 'Activate', 'gt-link-manager' );
		$toggle_url    = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'gtlm-links',
					'action' => $toggle_action,
					'link'   => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'gtlm_' . $toggle_action . '_' . (int) $item['id']
		);

		$actions = array(
			'edit'       => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'gt-link-manager' ) . '</a>',
			'quick_edit' => '<a href="#" class="gtlm-quick-edit" data-link-id="' . (int) $item['id'] . '" data-url="' . esc_attr( (string) $item['url'] ) . '" data-redirect-type="' . (int) $item['redirect_type'] . '" data-slug="' . esc_attr( (string) $item['slug'] ) . '" data-rel="' . esc_attr( (string) $item['rel'] ) . '" data-category-id="' . (int) ( $item['category_id'] ?? 0 ) . '" data-is-active="' . (int) $item['is_active'] . '">' . esc_html__( 'Quick Edit', 'gt-link-manager' ) . '</a>',
			'toggle'     => '<a href="' . esc_url( $toggle_url ) . '">' . $toggle_label . '</a>',
			'trash'      => '<a href="' . esc_url( $trash_url ) . '">' . esc_html__( 'Trash', 'gt-link-manager' ) . '</a>',
		);

		if ( '' !== $branded_url ) {
			$actions['copy_url'] = '<a href="#" class="gtlm-copy-url" data-copy-url="' . esc_attr( $branded_url ) . '">' . esc_html__( 'Copy URL', 'gt-link-manager' ) . '</a>';
			$actions['view']     = '<a href="' . esc_url( $branded_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'gt-link-manager' ) . '</a>';
		}

		// Only offer a reset when there is a non-zero count to reset.
		if ( GTLM_Settings::get_instance()->click_tracking_enabled() && (int) ( $item['total_clicks'] ?? 0 ) > 0 ) {
			$reset_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'gtlm-links',
						'action' => 'reset_clicks',
						'link'   => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'gtlm_reset_clicks_' . (int) $item['id']
			);

			$actions['reset_clicks'] = '<a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset Clicks', 'gt-link-manager' ) . '</a>';
		}

		if ( GTLM_Settings::get_instance()->analytics_initialized() && current_user_can( (string) apply_filters( 'gtlm_analytics_capability', 'manage_options' ) ) ) {
			$analytics_url        = add_query_arg(
				array(
					'page'    => 'gtlm-links-analytics',
					'link_id' => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			);
			$actions['analytics'] = '<a href="' . esc_url( $analytics_url ) . '">' . esc_html__( 'Analytics', 'gt-link-manager' ) . '</a>';
		}
		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_branded_url( $item ): string {
		$mode = (string) ( $item['link_mode'] ?? 'standard' );

		if ( 'regex' === $mode ) {
			return '<code class="gtlm-url-cell" title="' . esc_attr__( 'Regex pattern', 'gt-link-manager' ) . '">' . esc_html( (string) $item['slug'] ) . '</code>';
		}

		if ( 'direct' === $mode ) {
			$path = '/' . ltrim( (string) $item['slug'], '/' );
		} else {
			$path = '/' . trim( $this->prefix, '/' ) . '/' . (string) $item['slug'];
		}

		$url = home_url( $path );

		// Show the path rather than the absolute URL: it is what identifies the
		// link, it stays readable in a narrow column, and the full URL is still
		// available from the title attribute and the copy button.
		return sprintf(
			'<span class="gtlm-branded-cell">'
				. '<a class="gtlm-url-cell" href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s"><code>%3$s</code></a>'
				. '<button type="button" class="gtlm-copy-inline" data-copy-url="%1$s" aria-label="%4$s" title="%4$s">'
					. '<span class="gtlm-copy-inline__icon dashicons dashicons-admin-page" aria-hidden="true"></span>'
					. '<span class="gtlm-copy-inline__done" aria-hidden="true">%5$s</span>'
				. '</button>'
				. '<span class="screen-reader-text" aria-live="polite"></span>'
			. '</span>',
			esc_url( $url ),
			esc_attr( $url ),
			esc_html( $path ),
			/* translators: %s: branded link path. */
			esc_attr( sprintf( __( 'Copy branded URL for %s', 'gt-link-manager' ), $path ) ),
			esc_html__( 'Copied', 'gt-link-manager' )
		);
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_link_mode( $item ): string {
		$mode   = (string) ( $item['link_mode'] ?? 'standard' );
		$labels = array(
			'standard' => __( 'Standard', 'gt-link-manager' ),
			'direct'   => __( 'Direct', 'gt-link-manager' ),
			'regex'    => __( 'Regex', 'gt-link-manager' ),
		);
		return esc_html( $labels[ $mode ] ?? $labels['standard'] );
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_url( $item ): string {
		$url = (string) $item['url'];

		return sprintf(
			'<a class="gtlm-url-cell" href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $url ),
			esc_html( $url )
		);
	}

	/**
	 * Click counter cell.
	 *
	 * Shows an em dash rather than a misleading 0 when tracking is switched
	 * off, so an untracked link is not mistaken for one nobody clicked.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_total_clicks( $item ): string {
		$tracking = GTLM_Settings::get_instance()->click_tracking_enabled();

		$count = $tracking ? esc_html( number_format_i18n( (int) ( $item['total_clicks'] ?? 0 ) ) ) : esc_html__( 'View analytics', 'gt-link-manager' );
		if ( GTLM_Settings::get_instance()->advanced_analytics_enabled() && current_user_can( (string) apply_filters( 'gtlm_analytics_capability', 'manage_options' ) ) ) {
			/* translators: %s: Link name. */
			$label = sprintf( __( 'View analytics for %s', 'gt-link-manager' ), $item['name'] );
			return '<a href="' . esc_url(
				add_query_arg(
					array(
						'page'    => 'gtlm-links-analytics',
						'link_id' => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				)
			) . '" aria-label="' . esc_attr( $label ) . '">' . $count . '</a>';
		}
		if ( ! GTLM_Settings::get_instance()->click_tracking_enabled() ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Click tracking is off', 'gt-link-manager' ) . '</span>';
		}

		return $count;
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_status( $item ): string {
		if ( ! empty( $item['is_active'] ) ) {
			return '<span class="gtlm-status gtlm-status--active">' . esc_html__( 'Active', 'gt-link-manager' ) . '</span>';
		}

		return '<span class="gtlm-status gtlm-status--inactive">' . esc_html__( 'Inactive', 'gt-link-manager' ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_category( $item ): string {
		$category_id = (int) ( $item['category_id'] ?? 0 );
		if ( $category_id <= 0 ) {
			return '&mdash;';
		}

		foreach ( $this->categories as $category ) {
			if ( (int) $category['id'] === $category_id ) {
				return esc_html( (string) $category['name'] );
			}
		}

		return '&mdash;';
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_tags( $item ): string {
		$tags = trim( (string) ( $item['tags'] ?? '' ) );
		return '' !== $tags ? esc_html( $tags ) : '&mdash;';
	}

	/**
	 * @param array<string, mixed> $item Item.
	 */
	protected function column_geo( array $item ): string {
		if ( 'off' === (string) ( $item['geo_mode'] ?? 'off' ) ) {
			return '<span class="gtlm-status gtlm-status--na" aria-label="' . esc_attr__( 'No geo rules', 'gt-link-manager' ) . '">' . esc_html__( 'N/A', 'gt-link-manager' ) . '</span>';
		}

		$count = GTLM_Geo::rule_count( $item );

		return '<span class="gtlm-status gtlm-status--active">' . esc_html(
			sprintf(
				/* translators: %d: number of country rules */
				_n( '%d rule', '%d rules', $count, 'gt-link-manager' ),
				$count
			)
		) . '</span>';
	}

	protected function column_default( $item, $column_name ): string {
		if ( isset( $item[ $column_name ] ) ) {
			return esc_html( (string) $item[ $column_name ] );
		}

		return '';
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || 'trash' === $this->view ) {
			return;
		}

		$category      = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_type = isset( $_GET['redirect_type'] ) ? absint( $_GET['redirect_type'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rel           = isset( $_GET['rel'] ) ? sanitize_key( (string) wp_unslash( $_GET['rel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_category = isset( $_REQUEST['bulk_category_id'] ) ? absint( $_REQUEST['bulk_category_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_m = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$months    = $this->db->get_link_months();

		echo '<div class="alignleft actions">';

		if ( ! empty( $months ) ) {
			echo '<label class="screen-reader-text" for="gtlm-filter-m">' . esc_html__( 'Filter by date', 'gt-link-manager' ) . '</label>';
			echo '<select name="m" id="gtlm-filter-m">';
			echo '<option value="0">' . esc_html__( 'All dates', 'gt-link-manager' ) . '</option>';
			global $wp_locale;
			foreach ( $months as $m ) {
				$val   = (int) $m['year'] * 100 + (int) $m['month'];
				$label = $wp_locale->get_month( str_pad( (string) $m['month'], 2, '0', STR_PAD_LEFT ) ) . ' ' . (int) $m['year'];
				echo '<option value="' . (int) $val . '" ' . selected( $current_m, $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}

		echo '<label class="screen-reader-text" for="gtlm-filter-category">' . esc_html__( 'Filter by category', 'gt-link-manager' ) . '</label>';
		echo '<select name="category_id" id="gtlm-filter-category"><option value="0">' . esc_html__( 'All categories', 'gt-link-manager' ) . '</option>';
		foreach ( $this->categories as $cat ) {
			echo '<option value="' . (int) $cat['id'] . '" ' . selected( $category, (int) $cat['id'], false ) . '>' . esc_html( (string) $cat['name'] ) . '</option>';
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="gtlm-filter-type">' . esc_html__( 'Filter by redirect type', 'gt-link-manager' ) . '</label>';
		echo '<select name="redirect_type" id="gtlm-filter-type">';
		echo '<option value="0">' . esc_html__( 'All types', 'gt-link-manager' ) . '</option>';
		echo '<option value="301" ' . selected( $redirect_type, 301, false ) . '>301</option>';
		echo '<option value="302" ' . selected( $redirect_type, 302, false ) . '>302</option>';
		echo '<option value="307" ' . selected( $redirect_type, 307, false ) . '>307</option>';
		echo '</select>';

		echo '<label class="screen-reader-text" for="gtlm-filter-rel">' . esc_html__( 'Filter by rel value', 'gt-link-manager' ) . '</label>';
		echo '<select name="rel" id="gtlm-filter-rel">';
		echo '<option value="">' . esc_html__( 'All rel values', 'gt-link-manager' ) . '</option>';
		echo '<option value="nofollow" ' . selected( $rel, 'nofollow', false ) . '>nofollow</option>';
		echo '<option value="sponsored" ' . selected( $rel, 'sponsored', false ) . '>sponsored</option>';
		echo '<option value="ugc" ' . selected( $rel, 'ugc', false ) . '>ugc</option>';
		echo '</select>';

		$link_mode = isset( $_GET['link_mode'] ) ? sanitize_key( (string) wp_unslash( $_GET['link_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="screen-reader-text" for="gtlm-filter-mode">' . esc_html__( 'Filter by link mode', 'gt-link-manager' ) . '</label>';
		echo '<select name="link_mode" id="gtlm-filter-mode">';
		echo '<option value="">' . esc_html__( 'All modes', 'gt-link-manager' ) . '</option>';
		echo '<option value="standard" ' . selected( $link_mode, 'standard', false ) . '>' . esc_html__( 'Standard', 'gt-link-manager' ) . '</option>';
		echo '<option value="direct" ' . selected( $link_mode, 'direct', false ) . '>' . esc_html__( 'Direct', 'gt-link-manager' ) . '</option>';
		echo '<option value="regex" ' . selected( $link_mode, 'regex', false ) . '>' . esc_html__( 'Regex', 'gt-link-manager' ) . '</option>';
		echo '</select>';

		submit_button( esc_html__( 'Filter', 'gt-link-manager' ), 'secondary', 'filter_action', false );
		echo '</div>';

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="gtlm-bulk-category">' . esc_html__( 'Category for bulk action', 'gt-link-manager' ) . '</label>';
		echo '<select name="bulk_category_id" id="gtlm-bulk-category">';
		echo '<option value="0">' . esc_html__( 'Category for bulk action', 'gt-link-manager' ) . '</option>';
		foreach ( $this->categories as $cat ) {
			echo '<option value="' . (int) $cat['id'] . '" ' . selected( $bulk_category, (int) $cat['id'], false ) . '>' . esc_html( (string) $cat['name'] ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
	}

	public function prepare_items(): void {

		$per_page     = $this->get_items_per_page( 'gtlm_links_per_page', 20 );
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( (string) wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['orderby'] ) ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order        = isset( $_REQUEST['order'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$sortable = $this->get_sortable_columns();
		if ( isset( $sortable[ $orderby ] ) ) {
			$orderby = (string) $sortable[ $orderby ][0];
		}

		$filters = array(
			'search'        => $search,
			'category_id'   => isset( $_REQUEST['category_id'] ) ? absint( $_REQUEST['category_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'redirect_type' => isset( $_REQUEST['redirect_type'] ) ? absint( $_REQUEST['redirect_type'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'rel'           => isset( $_REQUEST['rel'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['rel'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'link_mode'     => isset( $_REQUEST['link_mode'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['link_mode'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'm'             => isset( $_REQUEST['m'] ) ? absint( $_REQUEST['m'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		// Apply view-based filtering.
		if ( 'trash' === $this->view ) {
			$filters['trashed'] = true;
		} elseif ( 'active' === $this->view ) {
			$filters['status'] = 'active';
		} elseif ( 'inactive' === $this->view ) {
			$filters['status'] = 'inactive';
		}

		$total_items = $this->db->count_links( $filters );
		$this->items = $this->db->list_links( $filters, $current_page, $per_page, $orderby, strtoupper( $order ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}
}
