<?php
/**
 * REST API endpoints.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_REST_API {
	private GT_Link_DB $db;

	private GT_Link_Settings $settings;

	public static function init( GT_Link_DB $db, GT_Link_Settings $settings ): void {
		$instance = new self( $db, $settings );
		$instance->hooks();
	}

	private function __construct( GT_Link_DB $db, GT_Link_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	private function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'gt-link-manager/v1',
			'/links',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_links' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'search'        => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'          => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ): bool {
								return (int) $value >= 1;
							},
						),
						'per_page'      => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 20,
							'validate_callback' => static function ( $value ): bool {
								$int = (int) $value;
								return -1 === $int || ( $int >= 1 && $int <= 200 );
							},
						),
						'category_id'   => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'status'        => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'enum'              => array( '', 'active', 'inactive', 'trash' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'orderby'       => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'id',
							'sanitize_callback' => 'sanitize_key',
						),
						'order'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'DESC',
							'sanitize_callback' => static function ( $value ): string {
								return 'ASC' === strtoupper( (string) $value ) ? 'ASC' : 'DESC';
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_link' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->link_write_args(),
				),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/links/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_link' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_link' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->link_write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_link' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/links/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'restore_link' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/links/(?P<id>\d+)/toggle-active',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'toggle_link_active' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'is_active' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/links/bulk-category',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_category_action' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'link_ids'    => array(
						'type'              => 'array',
						'required'          => true,
						'items'             => array( 'type' => 'integer' ),
						'validate_callback' => static function ( $value ): bool {
							return is_array( $value ) && ! empty( $value );
						},
					),
					'category_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'mode'        => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'move',
						'enum'              => array( 'move', 'copy' ),
					),
				),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'search' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->category_write_args(),
				),
			)
		);

		register_rest_route(
			'gt-link-manager/v1',
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->category_write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	public function permissions_check(): bool {
		$capability = (string) apply_filters( 'gt_link_manager_capabilities', 'edit_posts', 'rest_api' );
		return current_user_can( $capability );
	}

	public function get_links( WP_REST_Request $request ): WP_REST_Response {
		$search      = (string) $request->get_param( 'search' );
		$page        = (int) $request->get_param( 'page' );
		$per_page    = (int) $request->get_param( 'per_page' );
		$category_id = (int) $request->get_param( 'category_id' );
		$orderby     = (string) $request->get_param( 'orderby' );
		$order       = (string) $request->get_param( 'order' );

		$filters = array();
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}
		if ( $category_id > 0 ) {
			$filters['category_id'] = $category_id;
		}

		$status = (string) $request->get_param( 'status' );
		if ( 'trash' === $status ) {
			$filters['trashed'] = true;
		} elseif ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$filters['status'] = $status;
		}

		$total = $this->db->count_links( $filters );

		if ( -1 === $per_page ) {
			$rows        = $this->db->list_links( $filters, 1, max( $total, 1 ), $orderby, $order );
			$total_pages = 1;
		} else {
			$rows        = $this->db->list_links( $filters, $page, $per_page, $orderby, $order );
			$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		}

		$prefix = trim( $this->settings->prefix(), '/' );

		$items = array_map(
			static function ( array $row ) use ( $prefix ): array {
				$slug = (string) $row['slug'];
				return array(
					'id'            => (int) $row['id'],
					'name'          => (string) $row['name'],
					'slug'          => $slug,
					'url'           => home_url( '/' . $prefix . '/' . $slug ),
					'target_url'    => (string) $row['url'],
					'redirect_type' => (int) $row['redirect_type'],
					'rel'           => (string) $row['rel'],
					'noindex'       => (int) $row['noindex'],
					'is_active'     => (int) ( $row['is_active'] ?? 1 ),
					'category_id'   => (int) $row['category_id'],
					'tags'          => (string) ( $row['tags'] ?? '' ),
					'notes'         => (string) ( $row['notes'] ?? '' ),
					'trashed_at'    => $row['trashed_at'] ?? null,
					'created_at'    => (string) ( $row['created_at'] ?? '' ),
					'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
				);
			},
			$rows
		);

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_link( WP_REST_Request $request ) {
		$id   = absint( $request['id'] );
		$link = $this->db->get_link_by_id( $id );
		if ( ! is_array( $link ) ) {
			return new WP_Error( 'gt_link_not_found', __( 'Link not found.', 'gt-link-manager' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $link );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_link( WP_REST_Request $request ) {
		$data = $this->sanitize_link_payload( $request );
		if ( '' === $data['name'] || '' === $data['url'] ) {
			return new WP_Error( 'gt_link_invalid', __( 'Name and URL are required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		if ( null !== $this->db->get_link_by_slug( (string) $data['slug'] ) ) {
			return new WP_Error( 'gt_link_slug_exists', __( 'Slug already exists.', 'gt-link-manager' ), array( 'status' => 409 ) );
		}

		$id = $this->db->insert_link( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'gt_link_create_failed', __( 'Could not create link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		$link = $this->db->get_link_by_id( $id );
		return new WP_REST_Response( $link, 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_link( WP_REST_Request $request ) {
		$id       = absint( $request['id'] );
		$existing = $this->db->get_link_by_id( $id );
		if ( ! is_array( $existing ) ) {
			return new WP_Error( 'gt_link_not_found', __( 'Link not found.', 'gt-link-manager' ), array( 'status' => 404 ) );
		}

		$data = $this->sanitize_link_payload( $request, $existing );
		if ( '' === $data['name'] || '' === $data['url'] ) {
			return new WP_Error( 'gt_link_invalid', __( 'Name and URL are required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$slug_exists = $this->db->get_link_by_slug( (string) $data['slug'] );
		if ( is_array( $slug_exists ) && (int) $slug_exists['id'] !== $id ) {
			return new WP_Error( 'gt_link_slug_exists', __( 'Slug already exists.', 'gt-link-manager' ), array( 'status' => 409 ) );
		}

		$ok = $this->db->update_link( $id, $data );
		if ( ! $ok ) {
			return new WP_Error( 'gt_link_update_failed', __( 'Could not update link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->db->get_link_by_id( $id ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_link( WP_REST_Request $request ) {
		$id    = absint( $request['id'] );
		$force = ! empty( $request->get_param( 'force' ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'gt_link_invalid_id', __( 'Invalid link ID.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( $force ) {
			$ok = $this->db->delete_link( $id );
			if ( ! $ok ) {
				return new WP_Error( 'gt_link_delete_failed', __( 'Could not delete link.', 'gt-link-manager' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
		}

		$ok = $this->db->trash_link( $id );
		if ( ! $ok ) {
			return new WP_Error( 'gt_link_trash_failed', __( 'Could not trash link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'trashed' => true, 'id' => $id ) );
	}

	/**
	 * Restore a link from trash.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_link( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( $id <= 0 ) {
			return new WP_Error( 'gt_link_invalid_id', __( 'Invalid link ID.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$ok = $this->db->restore_link( $id );
		if ( ! $ok ) {
			return new WP_Error( 'gt_link_restore_failed', __( 'Could not restore link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->db->get_link_by_id( $id ) );
	}

	/**
	 * Toggle link active status.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_link_active( WP_REST_Request $request ) {
		$id        = absint( $request['id'] );
		$is_active = ! empty( $request->get_param( 'is_active' ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'gt_link_invalid_id', __( 'Invalid link ID.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$ok = $this->db->toggle_active( $id, $is_active );
		if ( ! $ok ) {
			return new WP_Error( 'gt_link_toggle_failed', __( 'Could not update link status.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->db->get_link_by_id( $id ) );
	}

	/**
	 * Move or copy links to category in bulk.
	 *
	 * mode=move updates category_id on selected links.
	 * mode=copy duplicates selected links into target category with unique slugs.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_category_action( WP_REST_Request $request ) {
		$link_ids    = $request->get_param( 'link_ids' );
		$category_id = absint( $request->get_param( 'category_id' ) );
		$mode        = sanitize_key( (string) $request->get_param( 'mode' ) );

		if ( ! is_array( $link_ids ) || empty( $link_ids ) ) {
			return new WP_Error( 'gt_link_ids_required', __( 'link_ids is required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $mode, array( 'move', 'copy' ), true ) ) {
			$mode = 'move';
		}

		if ( $category_id > 0 && ! is_array( $this->db->get_category( $category_id ) ) ) {
			return new WP_Error( 'gt_category_not_found', __( 'Category not found.', 'gt-link-manager' ), array( 'status' => 404 ) );
		}

		$link_ids = array_values( array_unique( array_filter( array_map( 'absint', $link_ids ) ) ) );
		if ( empty( $link_ids ) ) {
			return new WP_Error( 'gt_link_ids_invalid', __( 'No valid link IDs provided.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$moved  = 0;
		$copied = 0;
		$failed = 0;

		foreach ( $link_ids as $id ) {
			$link = $this->db->get_link_by_id( $id );
			if ( ! is_array( $link ) ) {
				++$failed;
				continue;
			}

			if ( 'move' === $mode ) {
				$ok = $this->db->update_link( $id, array_merge( $link, array( 'category_id' => $category_id ) ) );
				if ( $ok ) {
					++$moved;
				} else {
					++$failed;
				}
				continue;
			}

			$new_slug = $this->make_unique_slug( (string) $link['slug'] );
			$new_id   = $this->db->insert_link(
				array(
					'name'          => (string) $link['name'],
					'slug'          => $new_slug,
					'url'           => (string) $link['url'],
					'redirect_type' => (int) $link['redirect_type'],
					'rel'           => (string) $link['rel'],
					'noindex'       => (int) $link['noindex'],
					'category_id'   => $category_id,
					'tags'          => (string) $link['tags'],
					'notes'         => (string) $link['notes'],
				)
			);

			if ( $new_id > 0 ) {
				++$copied;
			} else {
				++$failed;
			}
		}

		return rest_ensure_response(
			array(
				'mode'        => $mode,
				'category_id' => $category_id,
				'moved'       => $moved,
				'copied'      => $copied,
				'failed'      => $failed,
			)
		);
	}

	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$rows   = $this->db->get_categories();

		if ( '' !== $search ) {
			$search = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $search ): bool {
						return str_contains( strtolower( (string) $row['name'] ), $search ) || str_contains( strtolower( (string) $row['slug'] ), $search );
					}
				)
			);
		}

		return rest_ensure_response( $rows );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( WP_REST_Request $request ) {
		$data = $this->sanitize_category_payload( $request );
		if ( '' === $data['name'] ) {
			return new WP_Error( 'gt_category_invalid', __( 'Category name is required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$id = $this->db->insert_category( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'gt_category_create_failed', __( 'Could not create category.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->db->get_category( $id ), 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! is_array( $this->db->get_category( $id ) ) ) {
			return new WP_Error( 'gt_category_not_found', __( 'Category not found.', 'gt-link-manager' ), array( 'status' => 404 ) );
		}

		$data = $this->sanitize_category_payload( $request );
		if ( '' === $data['name'] ) {
			return new WP_Error( 'gt_category_invalid', __( 'Category name is required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$ok = $this->db->update_category( $id, $data );
		if ( ! $ok ) {
			return new WP_Error( 'gt_category_update_failed', __( 'Could not update category.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->db->get_category( $id ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_category( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( $id <= 0 ) {
			return new WP_Error( 'gt_category_invalid_id', __( 'Invalid category ID.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		$ok = $this->db->delete_category( $id );
		if ( ! $ok ) {
			return new WP_Error( 'gt_category_delete_failed', __( 'Could not delete category.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * @param array<string, mixed> $fallback Fallback data.
	 * @return array<string, mixed>
	 */
	private function sanitize_link_payload( WP_REST_Request $request, array $fallback = array() ): array {
		$name = $request->get_param( 'name' );
		if ( null === $name && isset( $fallback['name'] ) ) {
			$name = (string) $fallback['name'];
		}

		$slug = $request->get_param( 'slug' );
		if ( null === $slug && isset( $fallback['slug'] ) ) {
			$slug = (string) $fallback['slug'];
		}

		$url = $request->get_param( 'url' );
		if ( null === $url && isset( $fallback['url'] ) ) {
			$url = (string) $fallback['url'];
		}

		$redirect_type = $request->get_param( 'redirect_type' );
		if ( null === $redirect_type && isset( $fallback['redirect_type'] ) ) {
			$redirect_type = (int) $fallback['redirect_type'];
		}

		$rel = $request->get_param( 'rel' );
		if ( null === $rel && isset( $fallback['rel'] ) ) {
			$rel = (string) $fallback['rel'];
		}

		$noindex = $request->get_param( 'noindex' );
		if ( null === $noindex && isset( $fallback['noindex'] ) ) {
			$noindex = (int) $fallback['noindex'];
		}

		$category_id = $request->get_param( 'category_id' );
		if ( null === $category_id && isset( $fallback['category_id'] ) ) {
			$category_id = (int) $fallback['category_id'];
		}

		$tags = $request->get_param( 'tags' );
		if ( null === $tags && isset( $fallback['tags'] ) ) {
			$tags = (string) $fallback['tags'];
		}

		$notes = $request->get_param( 'notes' );
		if ( null === $notes && isset( $fallback['notes'] ) ) {
			$notes = (string) $fallback['notes'];
		}

		$is_active = $request->get_param( 'is_active' );
		if ( null === $is_active && isset( $fallback['is_active'] ) ) {
			$is_active = (int) $fallback['is_active'];
		}

		$redirect_type = (int) $redirect_type;
		if ( ! in_array( $redirect_type, array( 301, 302, 307 ), true ) ) {
			$redirect_type = 301;
		}

		$rel_values = $this->normalize_rel( $rel );

		return array(
			'name'          => sanitize_text_field( (string) $name ),
			'slug'          => sanitize_title( (string) $slug ),
			'url'           => esc_url_raw( (string) $url ),
			'redirect_type' => $redirect_type,
			'rel'           => implode( ',', $rel_values ),
			'noindex'       => ! empty( $noindex ) ? 1 : 0,
			'is_active'     => null !== $is_active ? ( ! empty( $is_active ) ? 1 : 0 ) : 1,
			'category_id'   => absint( $category_id ),
			'tags'          => sanitize_text_field( (string) $tags ),
			'notes'         => sanitize_textarea_field( (string) $notes ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sanitize_category_payload( WP_REST_Request $request ): array {
		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$slug        = sanitize_title( (string) $request->get_param( 'slug' ) );
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		$parent_id   = absint( $request->get_param( 'parent_id' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		return array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => $description,
			'parent_id'   => $parent_id,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function normalize_rel( mixed $rel ): array {
		if ( is_string( $rel ) ) {
			$rel = array_filter( array_map( 'trim', explode( ',', $rel ) ) );
		}

		if ( ! is_array( $rel ) ) {
			return array();
		}

		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$clean   = array();
		foreach ( $rel as $value ) {
			$token = sanitize_key( (string) $value );
			if ( in_array( $token, $allowed, true ) ) {
				$clean[] = $token;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function make_unique_slug( string $base_slug ): string {
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
	 * @return array<string, array<string, mixed>>
	 */
	private function link_write_args(): array {
		return array(
			'name'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'url'           => array(
				'type'              => 'string',
				'required'          => false,
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
			),
			'redirect_type' => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 301,
				'enum'              => array( 301, 302, 307 ),
			),
			'rel'           => array(
				'type'              => array( 'string', 'array' ),
				'required'          => false,
				'default'           => '',
			),
			'noindex'       => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'enum'              => array( 0, 1 ),
			),
			'category_id'   => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'tags'          => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'notes'         => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'is_active'     => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'enum'              => array( 0, 1 ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function category_write_args(): array {
		return array(
			'name'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'description' => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'parent_id'   => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
