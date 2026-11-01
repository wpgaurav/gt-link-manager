<?php
/**
 * Novashare link shortening.
 *
 * Novashare's Custom shortener passes each share URL through the
 * novashare_short_link_url filter and saves what comes back.
 *
 * @see https://novashare.io/docs/link-shortening/#custom
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GTLM_Novashare {
	private const SLUG_PREFIX   = 'ns-';
	private const CATEGORY_SLUG = 'novashare';

	private GTLM_DB $db;

	private GTLM_Settings $settings;

	public static function init( GTLM_DB $db, GTLM_Settings $settings ): void {
		$instance = new self( $db, $settings );

		add_filter( 'novashare_short_link_url', array( $instance, 'shorten' ), 10, 3 );
	}

	private function __construct( GTLM_DB $db, GTLM_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	/**
	 * Find or create the branded link for a share URL.
	 *
	 * The slug is derived from the URL, so the UNIQUE index keeps one link per
	 * URL across networks, repeat calls and concurrent first shares.
	 *
	 * @param mixed $short_url Novashare's default, returned whenever no link applies.
	 * @param mixed $long_url  URL being shared.
	 * @param mixed $network   Network being shared to.
	 * @return mixed
	 */
	public function shorten( $short_url, $long_url = '', $network = '' ) {
		if ( ! is_string( $long_url ) ) {
			return $short_url;
		}

		$url = esc_url_raw( trim( $long_url ) );
		if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return $short_url;
		}

		$post_id = url_to_postid( $url );

		/**
		 * Filter whether a URL Novashare is sharing becomes a branded link.
		 *
		 * @param bool   $should_shorten True for published content on this site.
		 * @param string $url            URL being shared.
		 * @param string $network        Network being shared to.
		 */
		if ( ! apply_filters( 'gtlm_novashare_should_shorten', $this->is_shareable( $url, $post_id ), $url, is_string( $network ) ? $network : '' ) ) {
			return $short_url;
		}

		foreach ( array( 8, 16, 32 ) as $length ) {
			$slug = self::SLUG_PREFIX . substr( md5( $url ), 0, $length );
			$link = $this->db->get_link_by_exact_slug( $slug );

			if ( null === $link ) {
				if ( $this->create_link( $slug, $url, $post_id ) > 0 ) {
					return $this->settings->link_url( $slug );
				}

				// Lost a race to a concurrent first share, or the write failed.
				$link = $this->db->get_link_by_exact_slug( $slug );
				if ( null === $link ) {
					return $short_url;
				}
			}

			// A different destination holds this slug, so try a longer one.
			if ( (string) $link['url'] !== $url ) {
				continue;
			}

			// A trashed or paused link stays that way: sharing never revives it.
			return ! empty( $link['is_active'] ) && empty( $link['trashed_at'] ) ? $this->settings->link_url( $slug ) : $short_url;
		}

		return $short_url;
	}

	/**
	 * Limit link creation to published content on this site.
	 *
	 * Share links can be generated while a public page renders, so this must not
	 * turn a visitor-controlled URL into an open redirect or a new row per query
	 * string. Novashare's UTM parameters are the only query args allowed, plus
	 * the ones plain permalinks use.
	 */
	private function is_shareable( string $url, int $post_id ): bool {
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}

		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		foreach ( array_keys( $args ) as $key ) {
			$key = (string) $key;
			if ( 'p' !== $key && 'page_id' !== $key && ! str_starts_with( $key, 'utm_' ) ) {
				return false;
			}
		}

		return true;
	}

	private function create_link( string $slug, string $url, int $post_id ): int {
		$settings = $this->settings->all();
		$title    = $post_id > 0 ? trim( (string) get_post_field( 'post_title', $post_id ) ) : '';

		return $this->db->insert_link(
			array(
				'name'          => mb_substr( '' !== $title ? $title : $url, 0, 255 ),
				'slug'          => $slug,
				'url'           => $url,
				'redirect_type' => (int) $settings['default_redirect_type'],
				'rel'           => implode( ',', (array) $settings['default_rel'] ),
				'noindex'       => (int) $settings['default_noindex'],
				'category_id'   => $this->category_id(),
				'notes'         => __( 'Created automatically when this page was shared through Novashare.', 'gt-link-manager' ),
			)
		);
	}

	/**
	 * Keep generated links in one category, away from hand-made ones.
	 */
	private function category_id(): int {
		foreach ( $this->db->get_categories() as $category ) {
			if ( self::CATEGORY_SLUG === (string) $category['slug'] ) {
				return (int) $category['id'];
			}
		}

		return $this->db->insert_category(
			array(
				'name' => 'Novashare',
				'slug' => self::CATEGORY_SLUG,
			)
		);
	}
}
