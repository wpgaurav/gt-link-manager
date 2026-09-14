<?php
/** Focused analytics overview and collection settings. @package GTLinkManager */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation and filters; mutations use the controller's capability and nonce checks.
class GTLM_Analytics_View {
	public static function render(): void {
		if ( ! current_user_can( (string) apply_filters( 'gtlm_analytics_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You cannot view analytics.', 'gt-link-manager' ), '', array( 'response' => 403 ) ); }
		$settings    = GTLM_Settings::get_instance();
		$initialized = $settings->analytics_initialized();
		$config      = array(
			'state'          => 'disabled',
			'event_days'     => 7,
			'summary_days'   => 90,
			'country_source' => 'none',
			'country_header' => '',
			'campaigns'      => array(),
			'exclude_links'  => array(),
		);
		if ( $initialized ) {
			require_once __DIR__ . '/class-gtlm-analytics.php';
			$config = array_merge( $config, GTLM_Analytics::status() ); }
		$view = isset( $_GET['view'] ) && 'settings' === $_GET['view'] ? 'settings' : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$base = admin_url( 'admin.php?page=gtlm-links-analytics' );
		echo '<div class="wrap gtlm-analytics"><div class="gtlm-analytics-heading"><h1>' . esc_html__( 'Analytics', 'gt-link-manager' ) . '</h1><span class="gtlm-analytics-status">' . esc_html( 'active' === $config['state'] ? __( 'Collecting clicks', 'gt-link-manager' ) : ( $settings->advanced_analytics_enabled() ? __( 'Waiting for maintenance', 'gt-link-manager' ) : ( $initialized ? __( 'Collection paused', 'gt-link-manager' ) : __( 'Not enabled', 'gt-link-manager' ) ) ) ) . '</span></div>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Analytics views', 'gt-link-manager' ) . '">';
		foreach ( array(
			'overview' => __( 'Overview', 'gt-link-manager' ),
			'settings' => __( 'Settings', 'gt-link-manager' ),
		) as $key => $label ) {
			if ( 'settings' === $key && ! current_user_can( 'manage_options' ) ) {
				continue; }
			echo '<a class="nav-tab ' . ( $view === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'view', $key, $base ) ) . '"' . ( $view === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'gt-link-manager' ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Static notice only.
		if ( 'settings' === $view && current_user_can( 'manage_options' ) ) {
			self::settings( $config, $initialized ); } elseif ( ! $initialized ) {
			echo '<section class="gtlm-analytics-empty"><h2>' . esc_html__( 'See how your links are used', 'gt-link-manager' ) . '</h2><p>' . esc_html__( 'View click trends, referring websites and device information. Your existing basic click counts stay separate.', 'gt-link-manager' ) . '</p><p>' . esc_html__( 'Analytics is off until you enable it. No analytics data or background jobs are created beforehand.', 'gt-link-manager' ) . '</p><a class="button button-primary" href="' . esc_url( add_query_arg( 'view', 'settings', $base ) ) . '">' . esc_html__( 'Set up analytics', 'gt-link-manager' ) . '</a></section>';
			} else {
				self::overview( $config ); }
			echo '</div>';
	}

	private static function settings( array $config, bool $initialized ): void {
		$enabled = GTLM_Settings::get_instance()->advanced_analytics_enabled();
		echo '<form method="post" class="gtlm-analytics-settings">';
		wp_nonce_field( 'gtlm_analytics_settings' );
		echo '<input type="hidden" name="gtlm_analytics_action" value="save_settings"><h2>' . esc_html__( 'Click analytics', 'gt-link-manager' ) . '</h2>';
		echo '<label class="gtlm-setting-check"><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . '> <strong>' . esc_html__( 'Enable advanced analytics', 'gt-link-manager' ) . '</strong></label><p class="description">' . esc_html__( 'Records click times, referring pages and basic device information. No visitor scripts, cookies or IP addresses.', 'gt-link-manager' ) . '</p>';
		echo '<div class="gtlm-setting-field"><label for="gtlm-history"><strong>' . esc_html__( 'Keep reports for', 'gt-link-manager' ) . '</strong></label><select name="summary_days" id="gtlm-history">';
		$periods = array_filter( array_unique( array( 30, 60, 90, (int) $config['summary_days'] ) ) );
		sort( $periods );
		foreach ( $periods as $days ) {
			/* translators: %d: Number of days to keep reports. */
			echo '<option value="' . (int) $days . '" ' . selected( $config['summary_days'], $days, false ) . '>' . esc_html( sprintf( __( '%d days', 'gt-link-manager' ), $days ) ) . '</option>'; }
		echo '<option value="0" ' . selected( $config['summary_days'], 0, false ) . '>' . esc_html__( 'Forever', 'gt-link-manager' ) . '</option></select><p class="description">' . esc_html__( 'Choose Forever to keep reports until you delete them. Individual click records follow their separate retention setting. Storage safeguards can pause collection.', 'gt-link-manager' ) . '</p></div>';
		echo '<label class="gtlm-setting-check"><input type="checkbox" name="countries" value="1" ' . checked( 'none' !== $config['country_source'], true, false ) . '> ' . esc_html__( 'Include countries', 'gt-link-manager' ) . '</label><p class="description">' . esc_html__( 'Uses country information already supplied by your CDN or server. Unavailable locations appear as unknown.', 'gt-link-manager' ) . '</p>';
		echo '<p class="gtlm-analytics-timezone">' . esc_html__( 'Time follows WordPress:', 'gt-link-manager' ) . ' <strong>' . esc_html( wp_timezone_string() ) . '</strong>. <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Change in WordPress settings', 'gt-link-manager' ) . '</a></p>';
		echo '<section class="gtlm-settings-section"><h2>' . esc_html__( 'Advanced options', 'gt-link-manager' ) . '</h2><div class="gtlm-setting-field"><label for="gtlm-events">' . esc_html__( 'Keep individual click records (days)', 'gt-link-manager' ) . '</label><input id="gtlm-events" name="event_days" type="number" min="1" max="30" value="' . (int) $config['event_days'] . '"><p class="description">' . esc_html__( 'The default is 7 days. Dated summaries remain for the report period above.', 'gt-link-manager' ) . '</p></div><div class="gtlm-setting-field"><label for="gtlm-country-source">' . esc_html__( 'Country source', 'gt-link-manager' ) . '</label><select id="gtlm-country-source" name="country_source">';
		foreach ( array(
			'auto'       => __( 'Use existing country detection', 'gt-link-manager' ),
			'cloudflare' => __( 'Cloudflare', 'gt-link-manager' ),
			'custom'     => __( 'Custom country header', 'gt-link-manager' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( 'none' === $config['country_source'] ? 'auto' : $config['country_source'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></div><div class="gtlm-setting-field"><label for="gtlm-country-header">' . esc_html__( 'Custom header name (optional)', 'gt-link-manager' ) . '</label><input type="text" id="gtlm-country-header" name="country_header" value="' . esc_attr( $config['country_header'] ) . '" placeholder="X-Geo-Country"></div><div class="gtlm-setting-field"><label for="gtlm-excluded-links">' . esc_html__( 'Exclude link IDs (comma-separated)', 'gt-link-manager' ) . '</label><input type="text" class="regular-text" id="gtlm-excluded-links" aria-describedby="gtlm-exclusions-help" name="exclude_links" value="' . esc_attr( implode( ', ', $config['exclude_links'] ) ) . '"><p id="gtlm-exclusions-help" class="description">' . esc_html__( 'Enter link IDs separated by commas. Leave empty to include all eligible links.', 'gt-link-manager' ) . '</p></div></section>';
		echo '<section class="gtlm-settings-section"><h2>' . esc_html__( 'Campaign tracking', 'gt-link-manager' ) . '</h2><p>' . esc_html__( 'Optional: match exact UTM values to a campaign. Add a new row when changing an existing campaign.', 'gt-link-manager' ) . '</p><div class="gtlm-table-scroll"><table class="widefat gtlm-campaigns"><thead><tr><th>' . esc_html__( 'Source', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Medium', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Campaign', 'gt-link-manager' ) . '</th><th>' . esc_html__( 'Action', 'gt-link-manager' ) . '</th></tr></thead><tbody id="gtlm-campaign-rows">';
		$rows   = array_values( array_filter( $config['campaigns'], static fn( $row ) => ! empty( $row['enabled'] ) ) );
		$rows[] = array( 'id' => 0 );
		foreach ( $rows as $index => $row ) {
			self::campaign_row( (string) $index, $row ); }
		echo '</tbody></table></div><p><button type="button" class="button" id="gtlm-add-campaign">' . esc_html__( 'Add campaign', 'gt-link-manager' ) . '</button></p><template id="gtlm-campaign-template">';
		self::campaign_row( '__index__', array( 'id' => 0 ) );
		echo '</template></section>';
		echo '<section class="gtlm-settings-section"><h2>' . esc_html__( 'Privacy and data', 'gt-link-manager' ) . '</h2><p>' . esc_html__( 'Only eligible GET redirects are recorded. Recognized bots, prefetches and signed-in link managers are excluded. Referring page URLs are stored without credentials, query strings or fragments. Raw user agents and IP addresses are not stored. Reports use your WordPress timezone.', 'gt-link-manager' ) . '</p><p>' . esc_html__( 'Turning analytics off stops new collection. Existing reports are kept until they expire or you delete them. Basic lifetime click counts are unchanged.', 'gt-link-manager' ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'privacy-policy-guide.php?tab=policyguide' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View suggested privacy policy text', 'gt-link-manager' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span></a> ' . esc_html__( 'Look for the GT Link Manager section in the WordPress Privacy Policy Guide.', 'gt-link-manager' ) . '</p></section>';
		submit_button( __( 'Save settings', 'gt-link-manager' ) );
		echo '</form>';
		if ( $initialized ) {
			echo '<section class="gtlm-settings-section gtlm-analytics-settings"><h2>' . esc_html__( 'Maintenance and deletion', 'gt-link-manager' ) . '</h2><form method="post">';
			wp_nonce_field( 'gtlm_analytics_settings' );
			echo '<input type="hidden" name="gtlm_analytics_action" value="process"><p>' . esc_html__( 'Reports update automatically. Use this if an update is overdue.', 'gt-link-manager' ) . '</p><button class="button">' . esc_html__( 'Update reports now', 'gt-link-manager' ) . '</button></form><hr><form method="post">';
			wp_nonce_field( 'gtlm_analytics_settings' );
			echo '<input type="hidden" name="gtlm_analytics_action" value="delete"><p><label><input type="checkbox" required name="confirm_delete" value="DELETE_ANALYTICS"> ' . esc_html__( 'Permanently delete all analytics data. Links and basic counts will remain.', 'gt-link-manager' ) . '</label></p><button class="button">' . esc_html__( 'Delete analytics data', 'gt-link-manager' ) . '</button></form></section>';
		}
	}

	private static function overview( array $config ): void {
		$input = array();
		foreach ( array( 'from', 'to', 'period', 'link_id', 'category_id', 'dimension', 'granularity', 'referrer' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$input[ $key ] = 'referrer' === $key ? wp_unslash( $_GET[ $key ] ) : sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); }
		} // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only validated filters.
		if ( ! in_array( $input['dimension'] ?? 'source', array( 'source', 'country', 'device', 'browser', 'os', 'campaign' ), true ) ) {
			$input['dimension'] = 'source';}
		$period = $input['period'] ?? ( isset( $input['from'] ) ? 'custom' : '30' );
		if ( ! in_array( $period, array( '1', '7', '30', '90', 'custom' ), true ) ) {
			$period = '30'; }
		$input['period'] = $period;
		if ( in_array( $period, array( '1', '7', '30', '90' ), true ) ) {
			$input['from'] = current_datetime()->modify( '-' . ( (int) $period - 1 ) . ' days' )->format( 'Y-m-d' );
			$input['to']   = current_datetime()->format( 'Y-m-d' ); }
		try {
			$report = GTLM_Analytics_Controller::report( $input );
		} catch ( Throwable $error ) {
			$report = new WP_Error( 'report_unavailable', __( 'Reports could not load. Check analytics maintenance in Settings.', 'gt-link-manager' ) ); }
		if ( is_wp_error( $report ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $report->get_error_message() ) . '</p></div>';
			return; }
		$f    = $report['filters'];
		$base = admin_url( 'admin.php?page=gtlm-links-analytics' );
		if ( ! empty( $f['referrer'] ) ) {
			$page_label = self::page_label( $f['referrer'] );
			$title      = is_array( $page_label ) ? $page_label['label'] : $page_label;
			echo '<section class="gtlm-page-context"><a href="' . esc_url(
				self::report_url(
					array_merge(
						$input,
						array(
							'referrer' => false,
							'link_id'  => 0,
						)
					),
					$base
				)
			) . '">' . esc_html__( 'All referring pages', 'gt-link-manager' ) . '</a><h2>' . esc_html( $title ) . '</h2>';
			if ( is_array( $page_label ) ) {
				echo '<a href="' . esc_url( $f['referrer'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open page', 'gt-link-manager' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span></a>';}
			echo '<p class="description">' . esc_html__( 'This report shows clicks from the selected page. Choose a link below to inspect its activity from this page.', 'gt-link-manager' ) . '</p></section>';
		}
		if ( ! in_array( $config['state'], array( 'active', 'paused' ), true ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Collection is paused because reports have not updated recently. Check maintenance in Settings.', 'gt-link-manager' ) . '</p></div>'; }
		echo '<form method="get" class="gtlm-analytics-filters"><input type="hidden" name="referrer" value="' . esc_attr( $f['referrer'] ) . '"><input type="hidden" name="page" value="gtlm-links-analytics"><input type="hidden" name="link_id" value="' . (int) $f['link_id'] . '"><input type="hidden" name="dimension" value="' . esc_attr( $f['dimension'] ) . '"><label>' . esc_html__( 'Date range', 'gt-link-manager' ) . '<select name="period" id="gtlm-period">';
		foreach ( array(
			'1'      => __( 'Today', 'gt-link-manager' ),
			'7'      => __( 'Last 7 days', 'gt-link-manager' ),
			'30'     => __( 'Last 30 days', 'gt-link-manager' ),
			'90'     => __( 'Last 90 days', 'gt-link-manager' ),
			'custom' => __( 'Custom dates', 'gt-link-manager' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $period, (string) $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><span id="gtlm-custom-dates" class="gtlm-custom-dates"><label>' . esc_html__( 'From', 'gt-link-manager' ) . '<input type="date" name="from" value="' . esc_attr( $f['from_local'] ) . '"></label><label>' . esc_html__( 'To', 'gt-link-manager' ) . '<input type="date" name="to" value="' . esc_attr( $f['to_local'] ) . '"></label></span><label>' . esc_html__( 'Group by', 'gt-link-manager' ) . '<select name="granularity">';
		foreach ( array(
			'day'  => __( 'Day', 'gt-link-manager' ),
			'hour' => __( 'Hour', 'gt-link-manager' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $f['granularity'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><label>' . esc_html__( 'Category', 'gt-link-manager' ) . '<select name="category_id"><option value="0">' . esc_html__( 'All categories', 'gt-link-manager' ) . '</option>';
		foreach ( ( new GTLM_DB() )->get_categories() as $category ) {
			echo '<option value="' . (int) $category['id'] . '" ' . selected( $f['category_id'], $category['id'], false ) . '>' . esc_html( $category['name'] ) . '</option>'; }
		echo '</select></label><button class="button">' . esc_html__( 'Apply', 'gt-link-manager' ) . '</button></form>';
		if ( $f['link_id'] ) {
			$link = ( new GTLM_DB() )->get_link_by_id( $f['link_id'] );
			echo '<p>' . esc_html__( 'Showing:', 'gt-link-manager' ) . ' <strong>' . esc_html( $link['name'] ?? __( 'Selected link', 'gt-link-manager' ) ) . '</strong> <a href="' . esc_url( self::report_url( array_merge( $input, array( 'link_id' => 0 ) ), $base ) ) . '">' . esc_html__( 'Show all links', 'gt-link-manager' ) . '</a></p>'; }
		/* translators: %s: Click count in the previous selected period. */
		echo '<section class="gtlm-analytics-trend"><div class="gtlm-analytics-metric"><span>' . esc_html__( 'Recorded clicks', 'gt-link-manager' ) . '</span><strong>' . esc_html( number_format_i18n( $report['total'] ) ) . '</strong><span>' . esc_html( null === $report['previous'] ? __( 'Previous period has no complete history yet', 'gt-link-manager' ) : sprintf( __( '%s in the previous period', 'gt-link-manager' ), number_format_i18n( $report['previous'] ) ) ) . '</span></div>';
		if ( $report['trend'] ) {
			$chart  = self::chart_data( $report['trend'], $f['granularity'] );
			$points = $chart['points'];
			echo '<svg class="gtlm-analytics-chart" viewBox="0 0 900 205" role="img" aria-label="' . esc_attr__( 'Recorded click trend. Use View click totals for exact values.', 'gt-link-manager' ) . '"><line x1="16" y1="185" x2="884" y2="185" stroke="#c3c4c7"/><polyline fill="none" stroke="currentColor" stroke-width="3" points="' . esc_attr( implode( ' ', $points ) ) . '"/>';
			if ( 1 === count( $points ) ) {
				$xy = explode( ',', $points[0] );
				echo '<circle cx="' . esc_attr( $xy[0] ) . '" cy="' . esc_attr( $xy[1] ) . '" r="4" fill="currentColor"/>';
			} echo '</svg><div class="gtlm-chart-dates' . ( count( $points ) === 1 ? ' gtlm-chart-dates--single' : '' ) . '"><span>' . esc_html( $chart['first_label'] ) . '</span>';
			if ( count( $points ) > 1 ) {
				echo '<span>' . esc_html( $chart['last_label'] ) . '</span>';}
			echo '</div><p class="description">' . esc_html__( 'Showing the period with recorded clicks.', 'gt-link-manager' ) . '</p>';
		} else {
			echo '<p class="gtlm-chart-empty">' . esc_html__( 'No recorded clicks in this period. New activity appears after the next report update.', 'gt-link-manager' ) . '</p>'; }
		echo '<p><button type="button" class="button" id="gtlm-open-totals" aria-haspopup="dialog" aria-controls="gtlm-click-totals">' . esc_html__( 'View click totals', 'gt-link-manager' ) . '</button></p><dialog id="gtlm-click-totals" class="gtlm-totals-dialog" aria-labelledby="gtlm-totals-title"><div class="gtlm-dialog-header"><h2 id="gtlm-totals-title">' . esc_html__( 'Click totals', 'gt-link-manager' ) . '</h2><button type="button" class="button" id="gtlm-close-totals">' . esc_html__( 'Close', 'gt-link-manager' ) . '</button></div><p class="gtlm-dialog-description">' . esc_html( $f['from_local'] . ' – ' . $f['to_local'] . ' · ' . wp_timezone_string() ) . '</p><div class="gtlm-dialog-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Click totals table', 'gt-link-manager' ) . '">';
		self::table( array( __( 'Time', 'gt-link-manager' ), __( 'Clicks', 'gt-link-manager' ) ), array_map( static fn( $row ) => array( $row['day'], number_format_i18n( (int) $row['clicks'] ) ), $report['trend'] ) );
		echo '</div></dialog></section>';
		if ( empty( $f['referrer'] ) ) {
			echo '<section class="gtlm-analytics-pages"><h2>' . esc_html__( 'Clicked from', 'gt-link-manager' ) . '</h2><p class="description">' . esc_html__( 'Top posts and URLs that referred clicks to the selected links. Browsers may share only a website or no referrer. Older clicks have no page details. Query strings and fragments are not stored.', 'gt-link-manager' ) . '</p>';
			$pages = array();
			foreach ( $report['pages'] as $page ) {

				$label = self::page_label( $page['value'] );
				if ( is_array( $label ) && isset( $label['url'] ) ) {
					$label['url'] = self::report_url(
						array_merge(
							$input,
							array(
								'referrer' => $page['value'],
								'link_id'  => 0,
							)
						),
						$base
					);
					unset( $label['new_tab'] );
				}
				$pages[] = array( $label, number_format_i18n( (int) $page['clicks'] ) );
			}
			self::table( array( __( 'Post or URL', 'gt-link-manager' ), __( 'Clicks', 'gt-link-manager' ) ), $pages );
			echo '</section>';
		}
		echo '<div class="gtlm-analytics-grid"><section class="gtlm-analytics-breakdown"><h2>' . esc_html( empty( $f['referrer'] ) ? __( 'Top links', 'gt-link-manager' ) : __( 'Links clicked from this page', 'gt-link-manager' ) ) . '</h2>';
		$rows = array();
		foreach ( array_slice( $report['links'], 0, empty( $f['referrer'] ) ? 10 : 50 ) as $row ) {
			$rows[] = array(
				array(
					'label' => $row['name'],
					'url'   => self::report_url( array_merge( $input, array( 'link_id' => $row['link_id'] ) ), $base ),
				),
				number_format_i18n( (int) $row['clicks'] ),
			); }
		self::table( array( __( 'Link', 'gt-link-manager' ), __( 'Clicks', 'gt-link-manager' ) ), $rows );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gtlm-links' ) ) . '">' . esc_html__( 'Browse all links', 'gt-link-manager' ) . '</a></p>';
		echo '</section><section class="gtlm-analytics-breakdown" id="gtlm-breakdown"><h2>' . esc_html__( 'Click breakdown', 'gt-link-manager' ) . '</h2><nav class="gtlm-breakdown-nav nav-tab-wrapper" aria-label="' . esc_attr__( 'Click breakdown', 'gt-link-manager' ) . '">';
		$tabs = array(
			'source'   => __( 'Sources', 'gt-link-manager' ),
			'country'  => __( 'Countries', 'gt-link-manager' ),
			'device'   => __( 'Devices', 'gt-link-manager' ),
			'browser'  => __( 'Browsers', 'gt-link-manager' ),
			'os'       => __( 'OS', 'gt-link-manager' ),
			'campaign' => __( 'Campaigns', 'gt-link-manager' ),
		);
		foreach ( $tabs as $dimension => $label ) {
			echo '<button type="button" id="gtlm-tab-' . esc_attr( $dimension ) . '" data-gtlm-tab="' . esc_attr( $dimension ) . '" aria-controls="gtlm-panel-' . esc_attr( $dimension ) . '" class="nav-tab' . ( $dimension === $f['dimension'] ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</nav><noscript><p class="gtlm-tab-fallback">';
		foreach ( $tabs as $dimension => $label ) {
			echo '<a href="' . esc_url( self::report_url( array_merge( $input, array( 'dimension' => $dimension ) ), $base ) . '#gtlm-breakdown' ) . '">' . esc_html( $label ) . '</a> ';}
		echo '</p></noscript>';
		if ( ! empty( $report['details_unavailable'] ) ) {
			echo '<p class="description">' . esc_html__( 'Some older clicks have page totals but no retained breakdown details. They are shown as Details unavailable.', 'gt-link-manager' ) . '</p>';}
		foreach ( $tabs as $dimension => $label ) {
			echo '<div id="gtlm-panel-' . esc_attr( $dimension ) . '" class="gtlm-breakdown-panel" aria-labelledby="gtlm-tab-' . esc_attr( $dimension ) . '" tabindex="0"' . ( $dimension !== $f['dimension'] ? ' hidden' : '' ) . '>';
			self::table(
				array( __( 'Value', 'gt-link-manager' ), __( 'Clicks', 'gt-link-manager' ) ),
				array_map(
					static function ( $row ) use ( $dimension ) {
						$value = $row['value'];
						if ( '' === $value || ( 'campaign' === $dimension && '0' === $value ) ) {
							$value = 'source' === $dimension ? __( 'Direct / unknown', 'gt-link-manager' ) : __( 'Unknown / unattributed', 'gt-link-manager' );
						} elseif ( '_not_recorded' === $value ) {
							$value = __( 'Details unavailable', 'gt-link-manager' );
						} elseif ( '_other' === $value ) {
							$value = __( 'Other', 'gt-link-manager' );
						} elseif ( 'country' === $dimension ) {
							$value = GTLM_Geo::label( $value );}
						return array( $value, number_format_i18n( (int) $row['clicks'] ) );
					},
					$report['breakdowns'][ $dimension ]
				)
			);
			echo '</div>';
		}
		echo '</section></div>';
		echo '<footer class="gtlm-analytics-footer"><p>' . esc_html__( 'WordPress time:', 'gt-link-manager' ) . ' ' . esc_html( wp_timezone_string() ) . ' · ' . esc_html__( 'Last updated:', 'gt-link-manager' ) . ' ' . esc_html( empty( $config['last_processed_at'] ) ? '-' : wp_date( 'M j, H:i', strtotime( $config['last_processed_at'] ) ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gtlm_analytics_export' );
		echo '<input type="hidden" name="action" value="gtlm_analytics_export">';
		foreach ( array_merge(
			$input,
			array(
				'from' => $f['from_local'],
				'to'   => $f['to_local'],
			)
		) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		} echo '<button class="button">' . esc_html__( 'Export CSV', 'gt-link-manager' ) . '</button></form></footer><p class="description">' . esc_html__( 'Counts are eligible redirect requests, not unique visitors. Filters and paused collection can leave gaps. Categories reflect current membership.', 'gt-link-manager' ) . '</p>';
	}

	/** Fit the recorded period while preserving real time spacing and WordPress timezone labels. */
	private static function chart_data( array $trend, string $granularity ): array {
		$timezone = wp_timezone();
		$times    = array_map( static fn( $row ) => ( new DateTimeImmutable( $row['day'], $timezone ) )->getTimestamp(), $trend );
		$first    = min( $times );
		$last     = max( $times );
		$span     = max( 1, $last - $first );
		$max      = max( 1, max( array_map( 'intval', array_column( $trend, 'clicks' ) ) ) );
		$points   = array();
		foreach ( $trend as $index => $row ) {
			$x        = count( $trend ) === 1 ? 450 : 24 + 852 * ( $times[ $index ] - $first ) / $span;
			$y        = 185 - 160 * (int) $row['clicks'] / $max;
			$points[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		$format = 'hour' === $granularity ? 'M j, H:i P' : 'M j, Y';
		return array(
			'points'      => $points,
			'first_label' => wp_date( $format, $first ),
			'last_label'  => wp_date( $format, $last ),
		);
	}

	/** WordPress query builders expect new parameter values to be URL-encoded. */
	private static function report_url( array $input, string $base ): string {
		if ( isset( $input['referrer'] ) && is_string( $input['referrer'] ) ) {
			$input['referrer'] = rawurlencode( $input['referrer'] ); }
		return add_query_arg( $input, $base );
	}

	/** Resolve titles only in this bounded admin table; no post query runs while collecting clicks. */
	private static function page_label( string $url ) {
		if ( '' === $url ) {
			return array(
				'label'   => __( 'Page unavailable', 'gt-link-manager' ),
				'tooltip' => __( "Click counted. The referring page wasn't shared or recorded.", 'gt-link-manager' ),
			);
		}
		if ( '_other' === $url ) {
			return __( 'Other pages', 'gt-link-manager' ); }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'Page unavailable', 'gt-link-manager' ); }
		$result = array(
			'label'   => $url,
			'url'     => $url,
			'new_tab' => true,
		);
		$site   = wp_parse_url( home_url( '/' ) );
		if ( strtolower( $parts['host'] ) === strtolower( $site['host'] ?? '' ) && ( $parts['port'] ?? null ) === ( $site['port'] ?? null ) ) {
			$id   = url_to_postid( $url );
			$post = $id ? get_post( $id ) : null;
			if ( $post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) && '' === $post->post_password ) {
				$result['label']       = wp_strip_all_tags( $post->post_title );
				$result['description'] = $url;
			}
		}
		if ( strtolower( $parts['host'] ) !== strtolower( $site['host'] ?? '' ) ) {
			$result['help'] = __( 'Reported source of a short-link click. External sites can link or redirect to your links.', 'gt-link-manager' );
		}
		return $result;
	}

	private static function campaign_row( string $index, array $campaign ): void {
		echo '<tr><td><input type="hidden" name="campaigns[' . esc_attr( $index ) . '][id]" value="' . (int) $campaign['id'] . '">';
		foreach ( array(
			'utm_source'   => __( 'Campaign source', 'gt-link-manager' ),
			'utm_medium'   => __( 'Campaign medium', 'gt-link-manager' ),
			'utm_campaign' => __( 'Campaign name', 'gt-link-manager' ),
		) as $key => $label ) {
			if ( 'utm_source' !== $key ) {
				echo '<td>';
			} echo '<input type="text" aria-label="' . esc_attr( $label ) . '" maxlength="64" name="campaigns[' . esc_attr( $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $campaign[ $key ] ?? '' ) . '"></td>';
		} echo '<td><button type="button" class="button button-secondary gtlm-remove-campaign">' . esc_html__( 'Remove', 'gt-link-manager' ) . '</button></td></tr>';
	}
	private static function table( array $headings, array $rows ): void {
		echo '<div class="gtlm-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		} echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $value ) {
				echo '<td>';
				if ( is_array( $value ) && isset( $value['tooltip'] ) ) {
					echo '<button type="button" class="gtlm-tooltip-trigger" data-gtlm-tooltip="gtlm-page-unavailable-help" aria-describedby="gtlm-page-unavailable-help">' . esc_html( $value['label'] ) . ' <span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button><span id="gtlm-page-unavailable-help" class="gtlm-tooltip-content screen-reader-text" role="tooltip">' . esc_html( $value['tooltip'] ) . '</span>';
				} elseif ( is_array( $value ) ) {
					$help_id = ! empty( $value['help'] ) ? wp_unique_id( 'gtlm-referrer-help-' ) : '';
					echo '<a href="' . esc_url( $value['url'] ) . '"' . ( ! empty( $value['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $value['label'] );
					if ( ! empty( $value['new_tab'] ) ) {
						echo '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gt-link-manager' ) . '</span>'; }
					echo '</a>';
					if ( $help_id ) {
						echo ' <button type="button" class="gtlm-tooltip-trigger gtlm-tooltip-icon" data-gtlm-tooltip="' . esc_attr( $help_id ) . '" aria-describedby="' . esc_attr( $help_id ) . '" aria-label="' . esc_attr__( 'About this referring website', 'gt-link-manager' ) . '"><span class="dashicons dashicons-editor-help gtlm-inline-help-icon" aria-hidden="true"></span></button><span id="' . esc_attr( $help_id ) . '" class="gtlm-tooltip-content screen-reader-text" role="tooltip">' . esc_html( $value['help'] ) . '</span>';}
					if ( ! empty( $value['description'] ) ) {
						echo '<span class="gtlm-page-url">' . esc_html( $value['description'] ) . '</span>'; }
				} else {
					echo esc_html( (string) $value );
				} echo '</td>';
			} echo '</tr>';
		} if ( ! $rows ) {
			echo '<tr><td colspan="' . count( $headings ) . '">' . esc_html__( 'No data for this selection.', 'gt-link-manager' ) . '</td></tr>';
		} echo '</tbody></table></div>';
	}
}
