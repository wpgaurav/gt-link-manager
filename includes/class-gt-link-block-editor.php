<?php
/**
 * Block editor integration.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GT_Link_Block_Editor {
	private GT_Link_Settings $settings;

	public static function init( GT_Link_Settings $settings ): void {
		$instance = new self( $settings );
		$instance->hooks();
	}

	private function __construct( GT_Link_Settings $settings ) {
		$this->settings = $settings;
	}

	private function hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		$build_dir  = GT_LINK_MANAGER_PATH . 'blocks/link-inserter/build/';
		$build_url  = GT_LINK_MANAGER_URL . 'blocks/link-inserter/build/';
		$asset_file = $build_dir . 'index.asset.php';

		if ( ! file_exists( $build_dir . 'index.js' ) ) {
			return;
		}

		$asset = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-block-editor', 'wp-components', 'wp-element', 'wp-rich-text', 'wp-i18n', 'wp-api-fetch', 'wp-dom-ready' ),
				'version'      => filemtime( $build_dir . 'index.js' ),
			);

		wp_enqueue_script(
			'gt-link-manager-editor',
			$build_url . 'index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'gt-link-manager-editor',
			'gtLinkManagerEditor',
			array(
				'restPath' => '/gt-link-manager/v1/links',
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'prefix'   => $this->settings->prefix(),
			)
		);
	}
}
