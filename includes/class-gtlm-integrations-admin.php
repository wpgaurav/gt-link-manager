<?php
/**
 * Integrations settings screen.
 *
 * @package GTLinkManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-gtlm-integrations.php';
require_once __DIR__ . '/class-gtlm-integration-plugins.php';

class GTLM_Integrations_Admin {
	private const NOTICE_KEY = 'gtlm_integrations_notice_';

	/** Save the form, and run a test event when one of the test buttons sent it. */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'gt-link-manager' ), 403 );
		}
		check_admin_referer( 'gtlm_integrations_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every field is sanitized against the tool registry in sanitize_config().
		$raw = isset( $_POST['gtlm_integrations'] ) && is_array( $_POST['gtlm_integrations'] ) ? wp_unslash( $_POST['gtlm_integrations'] ) : array();

		$notices = array();
		foreach ( self::apply_form( $raw ) as $error ) {
			$notices[] = array(
				'type' => 'warning',
				'text' => $error,
			);
		}

		$test = isset( $_POST['gtlm_test_tool'] ) ? sanitize_key( (string) wp_unslash( $_POST['gtlm_test_tool'] ) ) : '';
		if ( '' !== $test ) {
			$outcome   = GTLM_Integrations::send_test( $test );
			$tools     = GTLM_Integrations::tools();
			$notices[] = array(
				'type' => $outcome['ok'] ? 'success' : 'error',
				'text' => ( $tools[ $test ]['label'] ?? $test ) . ': ' . $outcome['message'],
			);
		} elseif ( ! $notices ) {
			$notices[] = array(
				'type' => 'success',
				'text' => __( 'Integrations saved.', 'gt-link-manager' ),
			);
		}

		set_transient( self::NOTICE_KEY . get_current_user_id(), $notices, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=gtlm-links-integrations' ) );
		exit;
	}

	/**
	 * Save everything the form holds.
	 *
	 * @param array<string, mixed> $raw Unslashed form data.
	 * @return array<int, string> Problems to show the site owner.
	 */
	public static function apply_form( array $raw ): array {
		$result = GTLM_Integrations::sanitize_config( $raw, GTLM_Integrations::config() );
		GTLM_Integrations::save( $result['config'] );
		GTLM_Settings::get_instance()->set_novashare_enabled( ! empty( $raw['novashare_short_links'] ) );

		return $result['errors'];
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gt-link-manager' ), 403 );
		}

		$config = GTLM_Integrations::config();
		$tools  = GTLM_Integrations::tools();

		echo '<div class="wrap gtlm-wrap gtlm-integrations"><h1 class="gtlm-heading-icon">' . gtlm_icon( 'chart-line', 24 ) . esc_html__( 'Integrations', 'gt-link-manager' ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon markup is static.
		self::render_notices();
		echo '<p class="gtlm-integrations-intro">' . esc_html__( 'Connect your short links to the tools you already use. Everything here is off until you turn it on. Analytics tools never receive bots, prefetches or logged-in link managers.', 'gt-link-manager' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="gtlm_integrations_save" />';
		wp_nonce_field( 'gtlm_integrations_save' );

		foreach ( array( 'plugin', 'service', 'browser' ) as $group ) {
			echo '<section class="gtlm-settings-card"><h2>' . esc_html( self::group_label( $group ) ) . '</h2><p>' . esc_html( self::group_intro( $group ) ) . '</p>';
			foreach ( $tools as $key => $tool ) {
				if ( $group === $tool['type'] ) {
					self::render_tool( $key, $tool, $config['tools'][ $key ] ?? array() );
				}
			}
			echo '</section>';
		}

		echo '<section class="gtlm-settings-card"><h2>' . esc_html__( 'Visitor data', 'gt-link-manager' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'IP address and country', 'gt-link-manager' ) . '</th><td><label><input type="checkbox" name="gtlm_integrations[share_visitor_data]" value="1" ' . checked( ! empty( $config['share_visitor_data'] ), true, false ) . ' /> ' . esc_html__( 'Share each visitor\'s IP address and detected country with analytics services', 'gt-link-manager' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. While it is off, services still count every click and receive the browser\'s user agent and the referring page, but they treat each click as a new visitor and cannot report location. Turning this on makes those services process personal data, so check your privacy policy and any consent requirements. WordPress plugins on this site are not affected: they read the request themselves.', 'gt-link-manager' ) . '</p></td></tr></tbody></table></section>';

		self::render_novashare();

		submit_button( __( 'Save integrations', 'gt-link-manager' ) );
		echo '</form></div>';
	}

	/**
	 * Novashare is not an analytics tool: it asks this plugin for short links, so it
	 * sits apart from the registry and is saved in the main settings option.
	 */
	private static function render_novashare(): void {
		$enabled = GTLM_Settings::get_instance()->novashare_enabled();

		echo '<section class="gtlm-settings-card"><h2>' . esc_html__( 'Social sharing', 'gt-link-manager' ) . '</h2><p>' . esc_html__( 'Use your branded links when posts are shared through Novashare. Each shared URL gets one link in a Novashare category, using your link defaults.', 'gt-link-manager' ) . '</p>';
		echo '<details class="gtlm-integration" id="gtlm-integration-novashare"' . ( $enabled ? ' open' : '' ) . '><summary><span class="gtlm-integration-name">Novashare</span>';
		if ( $enabled ) {
			echo '<span class="gtlm-integration-status is-on">' . esc_html__( 'On', 'gt-link-manager' ) . '</span>';
		}
		echo '</summary><div class="gtlm-integration-body">';
		echo '<p><label><input type="checkbox" name="gtlm_integrations[novashare_short_links]" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Create Novashare short links with GT Link Manager', 'gt-link-manager' ) . '</label></p>';
		echo '<p>' . esc_html__( 'Then, in Novashare, open Configuration, go to Link Shortening, turn on Enable Link Shortening and choose Custom. Short links Novashare has already saved stay as they are.', 'gt-link-manager' ) . ' <a href="https://novashare.io/docs/link-shortening/#custom" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the Novashare guide', 'gt-link-manager' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span></a></p>';
		echo '</div></details></section>';
	}

	private static function group_label( string $group ): string {
		switch ( $group ) {
			case 'plugin':
				return __( 'WordPress analytics plugins', 'gt-link-manager' );
			case 'service':
				return __( 'Analytics services', 'gt-link-manager' );
		}

		return __( 'Browser tags', 'gt-link-manager' );
	}

	private static function group_intro( string $group ): string {
		switch ( $group ) {
			case 'plugin':
				return __( 'Clicks are recorded in the plugin\'s own reports on this site. Nothing leaves your server.', 'gt-link-manager' );
			case 'service':
				return __( 'Your server sends each click after the visitor has been redirected, so it adds no delay and ad blockers cannot stop it.', 'gt-link-manager' );
		}

		return __( 'These tools have no server-side API. A small script reports clicks on short links on your own pages, so links opened from email or social posts are not counted, and neither are visitors who block the tool. It recognises links under your link prefix only, not prefix-free links.', 'gt-link-manager' );
	}

	/**
	 * @param string               $key   Tool key.
	 * @param array<string, mixed> $tool  Tool definition.
	 * @param array<string, mixed> $saved Saved tool config.
	 */
	private static function render_tool( string $key, array $tool, array $saved ): void {
		$available = 'plugin' !== $tool['type'] || GTLM_Integration_Plugins::available( $key );
		$enabled   = $available && ! empty( $saved['enabled'] );
		$detected  = 'service' === $tool['type'] || 'browser' === $tool['type'] ? GTLM_Integrations::detected_values( $key ) : array( 'source' => '' );
		$id        = 'gtlm-integration-' . str_replace( '_', '-', $key );
		$name      = 'gtlm_integrations[tools][' . $key . ']';

		echo '<details class="gtlm-integration" id="' . esc_attr( $id ) . '"' . ( $enabled ? ' open' : '' ) . '><summary><span class="gtlm-integration-name">' . esc_html( $tool['label'] ) . '</span>';
		if ( ! empty( $tool['beta'] ) ) {
			echo '<span class="gtlm-integration-status is-beta">' . esc_html__( 'Beta', 'gt-link-manager' ) . '</span>';
		}
		if ( $enabled ) {
			echo '<span class="gtlm-integration-status is-on">' . esc_html__( 'On', 'gt-link-manager' ) . '</span>';
		} elseif ( ! $available ) {
			echo '<span class="gtlm-integration-status">' . esc_html__( 'Plugin not active', 'gt-link-manager' ) . '</span>';
		} elseif ( '' !== (string) $detected['source'] ) {
			/* translators: %s: plugin name. */
			echo '<span class="gtlm-integration-status">' . esc_html( sprintf( __( 'Found in %s', 'gt-link-manager' ), $detected['source'] ) ) . '</span>';
		} elseif ( 'plugin' === $tool['type'] ) {
			echo '<span class="gtlm-integration-status">' . esc_html__( 'Ready', 'gt-link-manager' ) . '</span>';
		}
		echo '</summary><div class="gtlm-integration-body"><p>' . esc_html( (string) $tool['help'] ) . '</p>';

		echo '<p><label><input type="checkbox" name="' . esc_attr( $name . '[enabled]' ) . '" value="1" ' . checked( $enabled, true, false ) . disabled( $available, false, false ) . ' /> ';
		/* translators: %s: tool name. */
		echo esc_html( sprintf( __( 'Send clicks to %s', 'gt-link-manager' ), $tool['label'] ) ) . '</label></p>';

		if ( ! $available ) {
			echo '<p class="description">' . esc_html__( 'Install and activate the plugin, then come back to turn this on.', 'gt-link-manager' ) . '</p>';
		}

		$fields = (array) ( $tool['fields'] ?? array() );
		if ( $fields ) {
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $field_name => $field ) {
				self::render_field( $id . '-' . $field_name, $name . '[' . $field_name . ']', $field, (string) ( $saved[ $field_name ] ?? '' ), (string) ( $detected['values'][ $field_name ] ?? '' ) );
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $tool['docs'] ) ) {
			/* translators: %s: tool name. */
			echo '<p><a href="' . esc_url( (string) $tool['docs'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( sprintf( __( '%s documentation', 'gt-link-manager' ), $tool['label'] ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span></a></p>';
		}

		$actions = '';
		if ( 'service' === $tool['type'] ) {
			$actions .= '<button type="submit" class="button" name="gtlm_test_tool" value="' . esc_attr( $key ) . '">' . esc_html__( 'Save and send test event', 'gt-link-manager' ) . '</button>';
		}
		if ( $fields && array_diff_key( $saved, array( 'enabled' => 1 ) ) ) {
			$actions .= '<label class="gtlm-integration-forget"><input type="checkbox" name="' . esc_attr( $name . '[forget]' ) . '" value="1" /> ' . esc_html__( 'Forget this tool\'s saved settings', 'gt-link-manager' ) . '</label>';
		}
		if ( '' !== $actions ) {
			echo '<p class="gtlm-integration-actions">' . $actions . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		}

		echo '</div></details>';
	}

	/**
	 * @param string               $id       Input ID.
	 * @param string               $name     Input name.
	 * @param array<string, mixed> $field    Field definition.
	 * @param string               $value    Saved value.
	 * @param string               $detected Value found in another plugin.
	 */
	private static function render_field( string $id, string $name, array $field, string $value, string $detected ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $field['label'] ) . '</label></th><td>';

		if ( 'select' === $field['type'] ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( (array) $field['options'] as $option => $label ) {
				echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $value, (string) $option, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'secret' === $field['type'] ) {
			// A saved secret is never sent back to the browser.
			$placeholder = '' !== $value ? __( 'Saved. Leave blank to keep it.', 'gt-link-manager' ) : ( '' !== $detected ? __( 'Found in another plugin. Leave blank to use it.', 'gt-link-manager' ) : '' );
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( $placeholder ) . '" />';
		} else {
			$placeholder = '' !== $detected ? $detected : (string) ( $field['placeholder'] ?? '' );
			echo '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . ( 'url' === $field['type'] ? 'url' : 'text' ) . '" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
		}

		if ( ! empty( $field['help'] ) ) {
			echo '<p class="description">' . esc_html( (string) $field['help'] ) . '</p>';
		}

		echo '</td></tr>';
	}

	private static function render_notices(): void {
		$key     = self::NOTICE_KEY . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			echo '<div class="notice notice-' . esc_attr( (string) $notice['type'] ) . ' is-dismissible"><p>' . esc_html( (string) $notice['text'] ) . '</p></div>';
		}
	}
}
