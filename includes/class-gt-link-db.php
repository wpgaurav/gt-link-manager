<?php
/**
 * Database access layer.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_DB {
	public const CACHE_GROUP = 'gt_links';

	/**
	 * Column list used in SELECT statements.
	 */
	private const LINK_COLUMNS = 'id, name, slug, url, redirect_type, rel, noindex, is_active, category_id, tags, notes, trashed_at, created_at, updated_at';

	/**
	 * @return string
	 */
	public static function links_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gt_links';
	}

	/**
	 * @return string
	 */
	public static function categories_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gt_link_categories';
	}

	/**
	 * Fetch a single link by slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_link_by_slug( string $slug ): ?array {
		$slug = $this->sanitize_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = $this->cache_key_for_slug( $slug );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT ' . self::LINK_COLUMNS . ' FROM ' . self::links_table() . ' WHERE slug = %s LIMIT 1',
			$slug
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );
		$link = is_array( $row ) ? $this->normalize_link_row( $row ) : null;

		$ttl = (int) apply_filters( 'gt_link_manager_cache_ttl', 0, $slug, $link );
		wp_cache_set( $cache_key, $link, self::CACHE_GROUP, max( 0, $ttl ) );

		return $link;
	}

	/**
	 * Insert a link record.
	 */
	public function insert_link( array $data ): int {
		global $wpdb;

		$insert = $this->normalize_link_for_write( $data );
		$format = array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' );

		$result = $wpdb->insert( self::links_table(), $insert, $format );
		if ( false === $result ) {
			return 0;
		}

		$link_id = (int) $wpdb->insert_id;

		if ( $link_id > 0 ) {
			$this->maybe_increment_category_count( (int) $insert['category_id'] );
			$this->delete_slug_cache( (string) $insert['slug'] );
			do_action( 'gt_link_manager_after_save', $link_id, $insert );
		}

		return $link_id;
	}

	/**
	 * Update an existing link.
	 */
	public function update_link( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$existing = $this->get_link_by_id( $id );
		if ( null === $existing ) {
			return false;
		}

		global $wpdb;

		$update = $this->normalize_link_for_write( $data );
		$format = array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' );

		$result = $wpdb->update(
			self::links_table(),
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$old_cat = (int) ( $existing['category_id'] ?? 0 );
		$new_cat = (int) ( $update['category_id'] ?? 0 );
		if ( $old_cat !== $new_cat ) {
			$this->maybe_decrement_category_count( $old_cat );
			$this->maybe_increment_category_count( $new_cat );
		}

		$this->delete_slug_cache( (string) $existing['slug'] );
		$this->delete_slug_cache( (string) $update['slug'] );

		do_action( 'gt_link_manager_after_save', $id, $update );
		return true;
	}

	/**
	 * Soft-delete: move a link to trash.
	 */
	public function trash_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->update(
			self::links_table(),
			array( 'trashed_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'] );
		return true;
	}

	/**
	 * Restore a link from trash.
	 */
	public function restore_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::links_table() . ' SET trashed_at = NULL WHERE id = %d',
				$id
			)
		);

		if ( false === $result ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( is_array( $link ) ) {
			$this->delete_slug_cache( (string) $link['slug'] );
		}

		return true;
	}

	/**
	 * Permanently delete a link by ID.
	 */
	public function delete_link( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->delete( self::links_table(), array( 'id' => $id ), array( '%d' ) );

		if ( false === $result || 0 === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'] );
		$this->maybe_decrement_category_count( (int) ( $link['category_id'] ?? 0 ) );
		do_action( 'gt_link_manager_after_delete', $id, $link );

		return true;
	}

	/**
	 * Toggle is_active status for a link.
	 */
	public function toggle_active( int $id, bool $active ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$link = $this->get_link_by_id( $id );
		if ( null === $link ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->update(
			self::links_table(),
			array( 'is_active' => $active ? 1 : 0 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->delete_slug_cache( (string) $link['slug'] );
		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_link_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT ' . self::LINK_COLUMNS . ' FROM ' . self::links_table() . ' WHERE id = %d LIMIT 1',
			$id
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->normalize_link_row( $row ) : null;
	}

	public function delete_slug_cache( string $slug ): void {
		$slug = $this->sanitize_slug( $slug );
		if ( '' !== $slug ) {
			wp_cache_delete( $this->cache_key_for_slug( $slug ), self::CACHE_GROUP );
		}
	}

	/**
	 * Lightweight search for editor inserter.
	 * Excludes trashed and inactive links.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search_links( string $search = '', int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$sql   = 'SELECT id, name, slug, rel FROM ' . self::links_table() . ' WHERE trashed_at IS NULL AND is_active = 1';
		$args  = array();

		$search = sanitize_text_field( $search );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql .= ' AND (name LIKE %s OR slug LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		$sql    .= ' ORDER BY name ASC LIMIT %d';
		$args[] = $limit;
		$sql    = $wpdb->prepare( $sql, $args );
		$rows   = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'   => (int) $row['id'],
					'name' => sanitize_text_field( (string) $row['name'] ),
					'slug' => sanitize_title( (string) $row['slug'] ),
					'rel'  => sanitize_text_field( (string) $row['rel'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Count links with filters.
	 *
	 * Supported filters: search, category_id, redirect_type, rel, status (active|inactive|all), trashed (bool).
	 */
	public function count_links( array $filters = array() ): int {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . self::links_table() . ' WHERE 1=1';
		$params = array();

		$this->apply_status_filters( $sql, $params, $filters );
		$this->apply_common_filters( $sql, $params, $filters );

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * List links with pagination/sorting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_links(
		array $filters = array(),
		int $page = 1,
		int $per_page = 20,
		string $orderby = 'id',
		string $order = 'DESC'
	): array {
		global $wpdb;

		$allowed_orderby = array( 'id', 'name', 'slug', 'url', 'redirect_type', 'rel', 'category_id', 'is_active', 'created_at', 'updated_at' );
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'id';
		$order           = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$page            = max( 1, $page );
		$per_page        = max( 1, $per_page );
		$offset          = ( $page - 1 ) * $per_page;

		$sql    = 'SELECT ' . self::LINK_COLUMNS . ' FROM ' . self::links_table() . ' WHERE 1=1';
		$params = array();

		$this->apply_status_filters( $sql, $params, $filters );
		$this->apply_common_filters( $sql, $params, $filters );

		$sql .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$sql = $wpdb->prepare( $sql, $params );
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_link_row' ), $rows );
	}

	/**
	 * List all links for CSV export (excludes trashed).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_links_for_export( array $filters = array() ): array {
		global $wpdb;

		$sql    = 'SELECT ' . self::LINK_COLUMNS . ' FROM ' . self::links_table() . ' WHERE trashed_at IS NULL';
		$params = array();

		$this->apply_common_filters( $sql, $params, $filters );

		$sql .= ' ORDER BY id DESC';
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize_link_row' ), $rows );
	}

	/**
	 * Apply trash/status WHERE clauses.
	 *
	 * @param string              $sql    SQL string (modified by reference).
	 * @param array<int, mixed>   $params Params array (modified by reference).
	 * @param array<string,mixed> $filters Filters.
	 */
	private function apply_status_filters( string &$sql, array &$params, array $filters ): void {
		$trashed = ! empty( $filters['trashed'] );

		if ( $trashed ) {
			$sql .= ' AND trashed_at IS NOT NULL';
		} else {
			$sql .= ' AND trashed_at IS NULL';
		}

		$status = (string) ( $filters['status'] ?? '' );
		if ( 'active' === $status ) {
			$sql .= ' AND is_active = 1';
		} elseif ( 'inactive' === $status ) {
			$sql .= ' AND is_active = 0';
		}
	}

	/**
	 * Apply common WHERE clauses (search, category, redirect_type, rel).
	 *
	 * @param string              $sql
	 * @param array<int, mixed>   $params
	 * @param array<string,mixed> $filters
	 */
	private function apply_common_filters( string &$sql, array &$params, array $filters ): void {
		global $wpdb;

		if ( ! empty( $filters['search'] ) ) {
			$search = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['search'] ) ) . '%';
			$sql   .= ' AND (name LIKE %s OR slug LIKE %s OR url LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		if ( ! empty( $filters['category_id'] ) ) {
			$sql .= ' AND category_id = %d';
			$params[] = absint( $filters['category_id'] );
		}

		if ( ! empty( $filters['redirect_type'] ) ) {
			$sql .= ' AND redirect_type = %d';
			$params[] = (int) $filters['redirect_type'];
		}

		if ( ! empty( $filters['rel'] ) ) {
			$rel_value = sanitize_key( (string) $filters['rel'] );
			if ( in_array( $rel_value, array( 'nofollow', 'sponsored', 'ugc' ), true ) ) {
				$sql     .= ' AND FIND_IN_SET(%s, rel)';
				$params[] = $rel_value;
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_categories(): array {
		global $wpdb;

		$sql  = 'SELECT id, name, slug, description, parent_id, count FROM ' . self::categories_table() . ' ORDER BY name ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$row['id']        = (int) $row['id'];
				$row['parent_id'] = (int) $row['parent_id'];
				$row['count']     = (int) $row['count'];
				$row['name']      = sanitize_text_field( (string) $row['name'] );
				$row['slug']      = sanitize_title( (string) $row['slug'] );
				$row['description'] = sanitize_textarea_field( (string) $row['description'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_category( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT id, name, slug, description, parent_id, count FROM ' . self::categories_table() . ' WHERE id = %d LIMIT 1',
			$id
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['id']          = (int) $row['id'];
		$row['parent_id']   = (int) $row['parent_id'];
		$row['count']       = (int) $row['count'];
		$row['name']        = sanitize_text_field( (string) $row['name'] );
		$row['slug']        = sanitize_title( (string) $row['slug'] );
		$row['description'] = sanitize_textarea_field( (string) $row['description'] );

		return $row;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert_category( array $data ): int {
		global $wpdb;

		$insert = array(
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'parent_id'   => absint( $data['parent_id'] ?? 0 ),
		);

		if ( '' === $insert['slug'] ) {
			$insert['slug'] = sanitize_title( $insert['name'] );
		}

		$result = $wpdb->insert(
			self::categories_table(),
			$insert,
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_category( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$update = array(
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'parent_id'   => absint( $data['parent_id'] ?? 0 ),
		);

		if ( '' === $update['slug'] ) {
			$update['slug'] = sanitize_title( $update['name'] );
		}

		$result = $wpdb->update(
			self::categories_table(),
			$update,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete_category( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$wpdb->update(
			self::links_table(),
			array( 'category_id' => 0 ),
			array( 'category_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		$wpdb->update(
			self::categories_table(),
			array( 'parent_id' => 0 ),
			array( 'parent_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		$result = $wpdb->delete( self::categories_table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result && $result > 0;
	}

	public function flush_cache_group(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}

		wp_cache_flush();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_link_row( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['redirect_type'] = (int) $row['redirect_type'];
		$row['noindex']       = (int) $row['noindex'];
		$row['is_active']     = (int) ( $row['is_active'] ?? 1 );
		$row['category_id']   = isset( $row['category_id'] ) ? (int) $row['category_id'] : 0;
		$row['slug']          = $this->sanitize_slug( (string) $row['slug'] );
		$row['rel']           = $this->sanitize_rel_string( (string) $row['rel'] );
		$row['url']           = esc_url_raw( (string) $row['url'] );
		$row['trashed_at']    = isset( $row['trashed_at'] ) ? (string) $row['trashed_at'] : null;

		return $row;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, int|string>
	 */
	private function normalize_link_for_write( array $data ): array {
		$rel = isset( $data['rel'] ) ? $this->sanitize_rel_string( (string) $data['rel'] ) : '';

		return array(
			'name'          => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'          => $this->sanitize_slug( (string) ( $data['slug'] ?? '' ) ),
			'url'           => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'redirect_type' => $this->sanitize_redirect_type( (int) ( $data['redirect_type'] ?? 301 ) ),
			'rel'           => $rel,
			'noindex'       => ! empty( $data['noindex'] ) ? 1 : 0,
			'is_active'     => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'category_id'   => absint( $data['category_id'] ?? 0 ),
			'tags'          => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
		);
	}

	private function sanitize_slug( string $slug ): string {
		return sanitize_title( $slug );
	}

	private function sanitize_redirect_type( int $type ): int {
		$allowed = array( 301, 302, 307 );
		return in_array( $type, $allowed, true ) ? $type : 301;
	}

	private function sanitize_rel_string( string $rel ): string {
		$allowed = array( 'nofollow', 'sponsored', 'ugc' );
		$parts   = array_filter( array_map( 'trim', explode( ',', strtolower( $rel ) ) ) );
		$parts   = array_map( 'sanitize_key', $parts );
		$parts   = array_values( array_intersect( $parts, $allowed ) );

		return implode( ',', array_unique( $parts ) );
	}

	private function cache_key_for_slug( string $slug ): string {
		return 'slug:' . $slug;
	}

	private function maybe_increment_category_count( int $category_id ): void {
		if ( $category_id <= 0 ) {
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::categories_table() . ' SET count = count + 1 WHERE id = %d',
				$category_id
			)
		);
	}

	private function maybe_decrement_category_count( int $category_id ): void {
		if ( $category_id <= 0 ) {
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::categories_table() . ' SET count = GREATEST(count - 1, 0) WHERE id = %d',
				$category_id
			)
		);
	}
}
