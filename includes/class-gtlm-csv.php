<?php
/** Spreadsheet-safe text cells with an explicit lossless GTLM import format. @package GTLinkManager */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class GTLM_CSV {
	public static function text( string $value ): string {
		return str_starts_with( $value, "'" ) || preg_match( '/^[\x00-\x20]*[=+@-]/', $value ) ? "'" . $value : $value;
	}

	public static function decode( string $value ): string {
		if ( str_starts_with( $value, "'" ) ) {
			$rest = substr( $value, 1 );
			if ( str_starts_with( $rest, "'" ) || preg_match( '/^[\x00-\x20]*[=+@-]/', $rest ) ) {
				return $rest;
			}
		}
		return $value;
	}
}
