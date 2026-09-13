<?php
/** Bounded referrer URLs, without credentials, query strings or fragments. @package GTLinkManager */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class GTLM_Analytics_Referrer {
	public static function parse( string $referrer ): array {
		$empty = array(
			'source' => '',
			'page'   => '',
		);
		if ( strlen( $referrer ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $referrer ) ) {
			return $empty;
		}
		// PHP's URL parser may replace raw C1 bytes inside UTF-8 paths; encode them first.
		$referrer = preg_replace_callback( '/[\x80-\xff]/', static fn( $part ) => rawurlencode( $part[0] ), $referrer );
		$url      = wp_parse_url( $referrer );
		if ( ! is_array( $url ) || ! in_array( strtolower( $url['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
			return $empty;
		}
		$host = strtolower( rtrim( $url['host'] ?? '', '.' ) );
		if ( strlen( $host ) > 253 || filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return $empty;
		}
		$result = array(
			'source' => $host,
			'page'   => '',
		);
		$path   = $url['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] || GTLM_DB::protected_path( $path ) ) {
			return $result;
		}
		// Query-only page identities cannot be retained without keeping their identifying query.
		if ( ! empty( $url['query'] ) && preg_match( '/(?:^|&)(?:p|page_id|attachment_id)=/i', $url['query'] ) ) {
			return $result;
		}
		// Encode non-ASCII path bytes and unsafe punctuation, preserving valid URI escapes.
		$path = preg_replace_callback( '/[^a-z0-9\-._~!$&\x27()*+,;=:@\/%]/i', static fn( $part ) => rawurlencode( $part[0] ), $path );
		if ( preg_match( '/%(?![a-f0-9]{2})/i', $path ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $path ) ) {
			return $result;
		}
		$scheme = strtolower( $url['scheme'] );
		$port   = isset( $url['port'] ) && ! ( ( 'https' === $scheme && 443 === $url['port'] ) || ( 'http' === $scheme && 80 === $url['port'] ) ) ? ':' . $url['port'] : '';
		$page   = $scheme . '://' . $host . $port . $path;
		if ( strlen( $page ) <= 1024 ) {
			$result['page'] = $page;
		}
		return $result;
	}
}
