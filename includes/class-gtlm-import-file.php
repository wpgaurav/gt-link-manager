<?php
/** Private, bounded, expiring CSV import storage. @package GTLinkManager */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class GTLM_Import_File {
	public const MAX_BYTES = 5242880;

	/** Never fall back to uploads or another public directory. */
	public static function directory( bool $create = false ) {
		$root = realpath( sys_get_temp_dir() );
		if ( false === $root ) {
			return new WP_Error( 'gtlm_import_private', __( 'Private temporary storage is unavailable.', 'gt-link-manager' ) );
		}
		$site_root = realpath( ABSPATH );
		if ( false === $site_root ) {
			return new WP_Error( 'gtlm_import_private', __( 'The site directory could not be verified.', 'gt-link-manager' ) );
		}
		$dir = $root . DIRECTORY_SEPARATOR . 'gtlm-imports-' . substr( hash( 'sha256', $site_root . ':' . get_current_blog_id() ), 0, 20 );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $public ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a server-owned containment boundary.
			$public = $public ? realpath( $public ) : false;
			if ( $public && str_starts_with( strtolower( wp_normalize_path( $dir ) ) . '/', strtolower( rtrim( wp_normalize_path( $public ), '/' ) ) . '/' ) ) {
				return new WP_Error( 'gtlm_import_private', __( 'Import storage must be outside the public site directory.', 'gt-link-manager' ) );
			}
		}
		if ( is_link( $dir ) ) {
			return new WP_Error( 'gtlm_import_private', __( 'Import storage must not be a symbolic link.', 'gt-link-manager' ) );
		}
		if ( $create && ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return new WP_Error( 'gtlm_import_private', __( 'Private import storage could not be created.', 'gt-link-manager' ) );
		}
		if ( is_dir( $dir ) && ( ! is_writable( $dir ) || ( 0 !== ( fileperms( $dir ) & 0077 ) && ! chmod( $dir, 0700 ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			return new WP_Error( 'gtlm_import_private', __( 'Private import storage needs restricted permissions.', 'gt-link-manager' ) );
		}
		return $dir;
	}

	public static function stage( array $file ) {
		$tmp  = $file['tmp_name'] ?? null;
		$name = $file['name'] ?? null;
		if ( ! is_string( $tmp ) || ! is_string( $name ) || UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || ! is_uploaded_file( $tmp ) || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'gtlm_import_file', __( 'Choose a CSV file to import.', 'gt-link-manager' ) );
		}
		$size = filesize( $tmp );
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'gtlm_import_size', __( 'Choose a non-empty CSV file no larger than 5 MB.', 'gt-link-manager' ) );
		}
		$dir = self::directory( true );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		self::cleanup_expired();
		$token = bin2hex( random_bytes( 16 ) );
		$path  = $dir . DIRECTORY_SEPARATOR . $token . '.csv';
		if ( ! move_uploaded_file( $tmp, $path ) || ! chmod( $path, 0600 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			self::delete( $token );
			return new WP_Error( 'gtlm_import_private', __( 'The import file could not be stored privately.', 'gt-link-manager' ) );
		}
		if ( ! wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'gtlm_import_expire', array( $token ) ) ) {
			self::delete( $token );
			return new WP_Error( 'gtlm_import_cleanup', __( 'Import cleanup could not be scheduled. Please try again.', 'gt-link-manager' ) );
		}
		return array(
			'file_path'  => $path,
			'file_token' => $token,
		);
	}

	public static function path( string $token ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return '';
		}
		$dir = self::directory();
		if ( is_wp_error( $dir ) ) {
			return '';
		}
		$file = $dir . DIRECTORY_SEPARATOR . $token . '.csv';
		return ! is_link( $file ) && is_file( $file ) && realpath( dirname( $file ) ) === realpath( $dir ) ? $file : '';
	}

	public static function delete( string $token ): void {
		$file = self::path( $token );
		if ( '' !== $file ) {
			wp_delete_file( $file );
		}
		wp_clear_scheduled_hook( 'gtlm_import_expire', array( $token ) );
	}

	public static function cleanup_expired(): void {
		$dir = self::directory();
		if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( $dir . DIRECTORY_SEPARATOR . '*.csv' );
		foreach ( array_slice( is_array( $files ) ? $files : array(), 0, 100 ) as $file ) {
			if ( ! is_link( $file ) && filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
				self::delete( basename( $file, '.csv' ) );
			}
		}
	}
}
