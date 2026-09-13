# Security Review: gt-link-manager

## Scope

Offline security audit of the current v1.9.0-rc.1 working tree before UI simplification and remediation.

- Scan mode: repository
- Target kind: git_worktree
- Target ID: target_sha256_4768a758872a1fbd91ba197e249b524f8a1e0ddfc3d9099b952eed5062511236
- Revision: 355e5e1557d72a75841856dfae727f3c714180ea
- Snapshot digest: codex-security-snapshot/v1:sha256:08c9027cdbb6166d77fbea4f90ed8fc25bf8af41b8e1c9e685e72f2cf5018cfa
- Inventory strategy: repository
- Included paths: .
- Excluded paths: none
- Runtime or test status: Security review did not execute application code or access production. Separate functional tests are not security exploit validation.
- Artifacts reviewed: .gitignore, .distignore, .phpactor.json, .github/workflows/test.yml, .github/workflows/release.yml, composer.json, phpcs.xml.dist, build.sh, gt-link-manager.php, uninstall.php, includes/class-gt-link-activator.php, includes/class-gt-link-admin-pages.php, includes/class-gt-link-admin.php, includes/class-gt-link-block-editor.php, includes/class-gt-link-db.php, includes/class-gt-link-deactivator.php, includes/class-gt-link-geo.php, includes/class-gt-link-import.php, includes/class-gt-link-list-table.php, includes/class-gt-link-redirect.php, includes/class-gt-link-rest-api.php, includes/class-gt-link-settings.php, includes/class-gtlm-analytics-cli.php, includes/class-gtlm-analytics-collector.php, includes/class-gtlm-analytics-controller.php, includes/class-gtlm-analytics-db.php, includes/class-gtlm-analytics.php, includes/data/countries.php, assets/css/admin.css, assets/js/admin.js, blocks/link-inserter/package.json, blocks/link-inserter/src/index.js, blocks/link-inserter/build/index.js, blocks/link-inserter/build/index.asset.php, tests/integration.php, tests/failures.php, tests/timezones.php, tests/run.sh, docs/audits/2026-09-13-v1.8.1/probe.php.example

Limitations and exclusions:
- Binary marketing assets and ignored dependencies/test outputs excluded. Dependency advisories and deployed upload exposure were not checked.
- Excluded .wordpress-org/\*.png: Non-executable marketing images; no product trust boundary is implemented in image bytes.
- Excluded vendor/\*\*, \*\*/node_modules/\*\*, build-release/\*\*: Ignored third-party dependencies and local test outputs are outside the authored-source audit; no dependency advisory or production security scan was performed.

### Scan Summary

| Field | Value |
| --- | --- |
| Scan outcome | completed |
| Reportable findings | 3 |
| Severity mix | high: 1, medium: 2 |
| Confidence mix | high: 3 |
| Coverage | complete |
| Validation mode | static source validation |

Canonical artifacts: `scan-manifest.json`, `findings.json`, and `coverage.json`. This report is a deterministic projection of those files.

## Threat Model

GT Link Manager is a WordPress plugin for shared site-wide link management and early HTTP redirects. plugins_loaded initializes settings and redirect resolution; admin requests additionally load administration, import/export, and block-editor integration, while REST classes load at rest_api_init. Redirects resolve standard slugs, optionally direct/regex paths, and optional country rules before emitting Location. Basic lifetime counters and advanced analytics have separate gates. Advanced analytics adds a bounded server-side event append after a matched redirect, with separate cron/CLI aggregation and authenticated reporting. Source: gt-link-manager.php:37-86; gt-link-manager.php:113-134; includes/class-gt-link-redirect.php:60-159; includes/class-gt-link-redirect.php:194-225. This is architecture mapping, not completed security-audit coverage.

### Assets

- Integrity and availability of site-wide links, redirect destinations, categories, and basic counts in {$wpdb-\>prefix}gtlm_links and {$wpdb-\>prefix}gtlm_categories. These tables contain no per-user ownership column; link management is a shared capability. Source: includes/class-gt-link-db.php:51-94; includes/class-gt-link-activator.php:47-100; includes/class-gt-link-rest-api.php:297-299.
- Persisted owner consent in gtlm_settings.enable_advanced_analytics and retained analytics configuration in the non-autoloaded gtlm_analytics option. Ordinary settings updates exclude the consent field and use a database compare-and-swap merge. Source: includes/class-gt-link-settings.php:116-139; includes/class-gt-link-settings.php:161-178; includes/class-gt-link-db.php:348-386; includes/class-gtlm-analytics.php:313-321.
- Short-lived events in {$wpdb-\>prefix}gtlm_analytics_events and minute aggregate buckets in {$wpdb-\>prefix}gtlm_analytics_hourly, including timestamps, referring hostnames, coarse client families, configured campaign IDs, and optional country codes. No raw IP, full referrer URL, or raw user-agent field exists in the event schema. Source: includes/class-gtlm-analytics-collector.php:64-90; includes/class-gtlm-analytics-db.php:42-79.
- Import source files and per-user preview state. wp_handle_upload determines the file's actual storage location, and its returned file path is retained in gtlm_import_preview_\<current-user-id\>. The repository does not establish that this is private temporary storage. Source: includes/class-gt-link-import.php:258-316.
- Privileged distribution integrity and publication credentials. Local builds assemble /tmp/gt-link-manager-build/gt-link-manager and output a versioned ZIP; release.published CI builds publish GitHub artifacts and deploy to WordPress.org using injected repository secrets. Source: build.sh:4-7; build.sh:19-27; build.sh:75-82; .github/workflows/release.yml:3-8; .github/workflows/release.yml:182-198; .github/workflows/release.yml:222-263.

### Trust Boundaries

- Public visitors supply request paths, query values, user-agent, referrer, and potentially country headers. The resolver loads stored rules, rejects inactive/trashed links, sanitizes the destination, checks its scheme/host/port, strips header newlines, and emits a client redirect. A redirect destination is not fetched server-side. Source: includes/class-gt-link-redirect.php:60-159; includes/class-gt-link-redirect.php:194-225; includes/class-gt-link-redirect.php:228-248; includes/class-gt-link-redirect.php:400-443.
- Public redirect requests cross into advanced event storage only when the raw persisted analytics gate is enabled and the collector accepts request method, manager/bot/prefetch exclusions, configuration generation/state, lease, and link exclusions. Incoming strings are bounded and mapped to fixed fields; UTM values select configured campaign IDs. Source: includes/class-gt-link-settings.php:161-169; includes/class-gtlm-analytics-collector.php:14-36; includes/class-gtlm-analytics-collector.php:64-90; includes/class-gtlm-analytics-collector.php:102-132; includes/class-gt-link-db.php:318-345.
- Authenticated link managers receive shared CRUD/import/export authority through the filterable edit_posts capability. WordPress authenticates REST requests; plugin permission callbacks enforce capabilities. Native admin actions require capability checks and action nonces. There is no additional link-owner isolation boundary. Source: includes/class-gt-link-rest-api.php:297-299; includes/class-gt-link-admin.php:525-567; includes/class-gt-link-import.php:24-55.
- Analytics readers receive report/status/export authority through gtlm_analytics_capability, defaulting to manage_options. Analytics mutations separately require manage_options even when read access is delegated. REST enable/resume additionally requires explicit consent; deletion requires the exact confirmation marker. Admin mutations and CSV exports enforce dedicated nonces. Source: includes/class-gt-link-rest-api.php:1089-1115; includes/class-gtlm-analytics-controller.php:17-48; includes/class-gtlm-analytics-controller.php:124-183; includes/class-gtlm-analytics-controller.php:410-457.
- Authenticated importers upload a file, preview it, then submit a per-user preview token and column mapping. Import consumes the stored server-side path rather than a submitted path. Cleanup deletes that retained path and preview state. Host-controlled upload placement and permissions remain outside the plugin's enforced boundary. Source: includes/class-gt-link-import.php:258-316; includes/class-gt-link-import.php:320-347; includes/class-gt-link-import.php:580-584.
- Country detection trusts the selected server/header values, not a plugin-verified proxy identity. Normalization constrains the country code but cannot establish provenance. Analytics defaults to no country source; general geolocation is separately disabled by default. Trusted proxy routing and header replacement are deployment prerequisites. Source: includes/class-gt-link-settings.php:33-47; includes/class-gt-link-geo.php:54-66; includes/class-gt-link-geo.php:90-112; includes/class-gt-link-geo.php:165-208; includes/class-gtlm-analytics.php:37-45; includes/class-gtlm-analytics-collector.php:64-68.
- The administrative geo diagnostic crosses into a separate loopback REST request. manage_options and a nonce protect initiation. A short-lived hashed probe-token option authorizes the probe; the raw token travels in the query string to rest_url('gt-link-manager/v1/geo-probe'). The request disables TLS certificate verification and redirects. The probe returns the caller's detected country/source, not administrative mutation authority. Source: includes/class-gt-link-admin.php:380-386; includes/class-gt-link-admin.php:451-475; includes/class-gt-link-geo.php:239-273; includes/class-gt-link-rest-api.php:279-294.
- Cron and server operators invoke the same analytics lifecycle/maintenance code. CLI trusts existing host execution authority rather than WordPress user capabilities and requires --yes for enable/delete. Lifecycle and maintenance share a nonblocking MySQL named lock; aggregate writes and processed flags share transactions. Source: gt-link-manager.php:69-75; includes/class-gtlm-analytics-cli.php:14-30; includes/class-gtlm-analytics-cli.php:49-65; includes/class-gtlm-analytics-cli.php:86-91; includes/class-gtlm-analytics-db.php:30-38; includes/class-gtlm-analytics-db.php:107-174.
- WordPress executable extensions share application authority. Filters may modify ordinary settings, capabilities, geo sources, and destinations; they are trusted PHP integrations, not untrusted remote callers. The analytics consent reader intentionally bypasses the generic settings filter. Source: includes/class-gt-link-settings.php:64-108; includes/class-gt-link-settings.php:161-169; includes/class-gt-link-redirect.php:140-142; includes/class-gt-link-geo.php:90-112.
- Publication is a distinct maintainer/CI authority boundary. The workflow is triggered by a published GitHub release, has contents:write, verifies the plugin-header version against the tag, and separately builds for WordPress.org. Source: .github/workflows/release.yml:3-8; .github/workflows/release.yml:37-45; .github/workflows/release.yml:89-112; .github/workflows/release.yml:222-263.

### Attacker Capabilities

- An unauthenticated visitor can request known or guessed redirect paths repeatedly and choose ordinary request headers and query values. They cannot directly choose stored link configurations, access protected analytics endpoints, or enable collection through the public redirect interface. Source: includes/class-gt-link-redirect.php:60-159; includes/class-gt-link-rest-api.php:1089-1115.
- A site user holding the effective edit_posts capability can manage shared links and import/export them by design. They are not isolated to their own rows, but lack manage_options analytics mutation authority unless the host grants it. Source: includes/class-gt-link-rest-api.php:297-299; includes/class-gt-link-rest-api.php:1089-1098; includes/class-gt-link-import.php:24-55.
- A delegated analytics reader can retrieve site-wide retained reports and configuration/status exposed by the read endpoints, but delegation alone does not grant enable, pause, resume, or delete authority. Source: includes/class-gt-link-rest-api.php:1089-1115; includes/class-gtlm-analytics.php:278-301.
- An off-site origin can attempt to induce requests from an authenticated browser, but privileged native actions require nonces and REST authentication is delegated to WordPress. WordPress session/origin enforcement was not inspected because WordPress core is outside this repository.
- A host operator or malicious executable plugin already has application/database authority and is not treated as a low-privilege attacker. CLI-selected local paths and PHP filters inherit that existing authority.
- An attacker able to reach an origin that accepts forged country headers can influence reported geography or configured geo routing. This requires deployment conditions not established by the repository; geo routing is documented as unsuitable for security enforcement. Source: CLAUDE.md:116-119; includes/class-gt-link-geo.php:165-208.

### Security Objectives

- User-origin requirement: advanced analytics must remain explicitly opt-in before creating its own storage, option, events, or scheduled job, independently of basic counts. Persisted gate checks and opt-in-only enable provisioning are the intended enforcing mechanisms. Source: includes/class-gt-link-settings.php:33-47; includes/class-gt-link-settings.php:161-178; includes/class-gtlm-analytics.php:94-156; gt-link-manager.php:69-72.
- User-origin requirement: minimize frontend overhead. Ordinary pages must not load analytics collection/storage code; matched opted-in redirects should make only the bounded event append, with no request-time schema creation, aggregation, or external analytics request. Source: gt-link-manager.php:37-86; includes/class-gt-link-redirect.php:194-225; includes/class-gt-link-db.php:318-345.
- User-origin requirement: present analytics in WordPress site time. Storage keeps UTC instants, filters convert local date boundaries to UTC, SQL grouping derives timezone transitions from wp_timezone(), and exports format values with wp_date(). Source: includes/class-gtlm-analytics-collector.php:70-84; includes/class-gtlm-analytics-controller.php:53-95; includes/class-gtlm-analytics-db.php:318-356; includes/class-gtlm-analytics-controller.php:437-450.
- Keep analytics administration separate from delegated reporting, require explicit collection/deletion intent, prevent ordinary settings saves from manufacturing or overwriting consent, and preserve links/basic counts when deleting only analytics. Source: includes/class-gt-link-rest-api.php:1089-1115; includes/class-gtlm-analytics-controller.php:17-48; includes/class-gt-link-db.php:348-386; includes/class-gtlm-analytics.php:187-205.
- Constrain retained visitor data and shared database cost through normalized fields, finite retention, bounded aggregation/pruning, a maintenance health lease, source-cardinality reduction, and bounded exports. These are soft service protections rather than a hard disk or query-time quota. Source: includes/class-gtlm-analytics.php:25-90; includes/class-gtlm-analytics.php:236-264; includes/class-gtlm-analytics-db.php:107-174; includes/class-gtlm-analytics-db.php:183-204; includes/class-gtlm-analytics-db.php:280-315; docs/analytics.md:7-15.
- Keep imported files, report data, credentials, and distribution artifacts within their intended audiences, and accurately describe retained data in product privacy documentation. Source: includes/class-gt-link-import.php:24-55; includes/class-gtlm-analytics-controller.php:410-457; includes/class-gt-link-admin.php:360-368; .github/workflows/release.yml:182-198; .github/workflows/release.yml:254-263.

### Assumptions

- Review scope is the current repository working tree, respecting ignore rules and excluding vendor, node_modules, and build-release. No application execution, external requests, source edits, or vulnerability-triggering inputs were used. The parent reported no applicable root SECURITY.md.
- Provided deployment context authorizes implementation/testing on gauravtiwari.org and does not authorize public release. Local documentation identifies /var/www/gauravtiwari.org as the WordPress root, but current host paths, database prefix, active plugin path, upload configuration, proxy protections, and runtime settings were not verified. Source for documentation only: CLAUDE.md:129-132.
- Advanced analytics enablement rejects multisite before provisioning. Effective per-site table names derive from the runtime WordPress database prefix; the concrete production prefix is unavailable offline. Source: includes/class-gtlm-analytics.php:94-98; includes/class-gtlm-analytics-db.php:20-27.
- The code requires usable InnoDB tables and named-lock support. Maintenance scheduling/traffic, database responsiveness, and host quotas determine actual retention and resource behavior. The 15-minute lease and 100 MiB threshold do not interrupt a blocked SQL operation or prevent temporary threshold overshoot. Source: includes/class-gtlm-analytics-db.php:30-38; includes/class-gtlm-analytics-db.php:42-91; includes/class-gtlm-analytics.php:236-264; docs/analytics.md:9-11.
- PHP-FPM can finish the visitor response before counter/event writes; other SAPIs perform them synchronously. This deployment difference is explicitly documented and implemented. Source: includes/class-gt-link-redirect.php:207-225; readme.txt:74-84.
- Import upload placement is delegated to WordPress. The source establishes neither a private upload directory nor server-level access denial for preview files; exact location and public reachability remain host questions. Source: includes/class-gt-link-import.php:273-316.
- Documentation discrepancy: readme.txt:309-311 still categorically denies request logging and describes only basic totals. The implemented advanced collector retains individual event timestamps and normalized metadata after opt-in; newer analytics documentation and the generated WordPress privacy guidance correctly describe this behavior. Source: includes/class-gtlm-analytics-collector.php:70-84; docs/analytics.md:7-15; includes/class-gt-link-admin.php:360-368.
- Documentation discrepancy: CLAUDE.md:28 describes a v\* tag-push release trigger. The actual workflow triggers on release.published and proceeds to WordPress.org deployment. Source: .github/workflows/release.yml:3-8; .github/workflows/release.yml:222-263.
- The geo-probe documentation calls its token single-use. The actual consumer performs separate get_option and delete_option operations, then compares the hash; atomic single-consumer enforcement is not established by this architecture pass. Its disclosed response remains caller-country/source only. Source: includes/class-gt-link-geo.php:239-273; includes/class-gt-link-rest-api.php:279-294.

## Findings

| Finding | Severity | Confidence | Detailed write-up |
| --- | --- | --- | --- |
| [Reserved WordPress paths can be redirected through REST, CSV, and regex rules](#finding-1) | high | high | inline below |
| [CSV previews publish raw import files and leave abandoned uploads accessible](#finding-2) | medium | high | inline below |
| [Legacy link CSV export preserves attacker-controlled spreadsheet formulas](#finding-3) | medium | high | inline below |

### Confidence Scale

| Label | Meaning |
| --- | --- |
| high | Direct evidence supports the finding with no material unresolved blocker. |
| medium | Evidence supports a plausible issue, but material runtime or reachability proof remains. |
| low | Evidence is incomplete and the item is retained only for explicit follow-up. |

<a id="finding-1"></a>

### [1] Reserved WordPress paths can be redirected through REST, CSV, and regex rules

| Field | Value |
| --- | --- |
| Severity | high |
| Confidence | high |
| Confidence rationale | Independent baseline source review and parent validation established the same control failure; deployment-dependent effects remain explicit. |
| Category | authorization |
| CWE | CWE-863 |
| Affected lines | includes/class-gt-link-admin.php:876-885, includes/class-gt-link-rest-api.php:297-300, includes/class-gt-link-rest-api.php:414-437, includes/class-gt-link-db.php:98-105, includes/class-gt-link-redirect.php:60-103, includes/class-gt-link-redirect.php:202-207 |

#### Summary

The admin form rejects direct paths such as wp-login.php, xmlrpc.php, and wp-json. REST and CSV creation bypass that check: their payloads reach GTLM_DB::insert_link(), whose shared validator accepts every non-regex definition. A direct rule with slug wp-login.php, an external HTTPS destination, and redirect_type 307 is therefore accepted. The init resolver processes direct and regex matches without excluding login paths or POST requests and emits the configured Location response. Regex rules can also match protected paths even when created through the admin form.

#### Root Cause

Paths explicitly reserved for WordPress must not become externally controlled redirects. The admin form rejects direct paths such as wp-login.php, xmlrpc.php, and wp-json. REST and CSV creation bypass that check: their payloads reach GTLM_DB::insert_link(), whose shared validator accepts every non-regex definition. A direct rule with slug wp-login.php, an external HTTPS destination, and redirect_type 307 is therefore accepted. The init resolver processes direct and regex matches without excluding login paths or POST requests and emits the configured Location response. Regex rules can also match protected paths even when created through the admin form.

**Entrypoint** — `includes/class-gt-link-rest-api.php:297-300`

This capability admits lower-privileged link managers to the shared REST boundary.

```php
	public function permissions_check(): bool {
		$capability = (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'rest_api' );
		return current_user_can( $capability );
	}
```

**User input** — `includes/class-gt-link-rest-api.php:414-437`

The REST handler forwards the caller-controlled definition into shared persistence.

```php
	public function create_link( WP_REST_Request $request ) {
		$data = $this->sanitize_link_payload( $request );
		if ( ! GTLM_DB::valid_link_definition( $data ) ) {
			return new WP_Error( 'gtlm_invalid_pattern', __( 'The regular expression is invalid.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === $data['name'] || '' === $data['url'] ) {
			return new WP_Error( 'gtlm_invalid', __( 'Name and URL are required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		if ( null !== $this->db->get_link_by_exact_slug( (string) $data['slug'] ) ) {
			return new WP_Error( 'gtlm_slug_exists', __( 'Slug already exists.', 'gt-link-manager' ), array( 'status' => 409 ) );
		}

		$id = $this->db->insert_link( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'gtlm_create_failed', __( 'Could not create link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		$link = $this->db->get_link_by_id( $id );
		return new WP_REST_Response( is_array( $link ) ? $this->prepare_link_row( $link ) : $link, 201 );
```

**Root control** — `includes/class-gt-link-db.php:98-105`

The shared validator accepts direct definitions without checking protected endpoints.

```php
	/** Validate regex syntax before any write, including admin and imports. */
	public static function valid_link_definition( array $data ): bool {
		if ( 'regex' !== ( $data['link_mode'] ?? 'standard' ) ) {
			return true;
		}
		$pattern = (string) ( $data['slug'] ?? '' );
		// A malformed user-supplied pattern is a validation failure, not a PHP warning.
		return '' !== $pattern && strlen( $pattern ) <= 255 && false !== @preg_match( '#' . $pattern . '#', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
```

**Propagation** — `includes/class-gt-link-redirect.php:60-103`

Stored direct and regex rules reach the resolver without a login-path or POST exclusion.

```php
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$link         = null;
		$match_slug   = '';
		$regex_result = null;

		// Step 1: Try standard prefix-based lookup (fastest path).
		$slug = $this->extract_slug_from_request();

		// A non-empty slug means the request path matched the configured
		// prefix, so this URL is ours to answer for -- including answering 404.
		$prefix_matched = ( '' !== $slug );

		if ( '' !== $slug ) {
			$link = $this->db->get_link_by_slug( $slug );
			if ( is_array( $link ) ) {
				$match_slug = $slug;
			}
		}

		// Step 2 & 3: Try direct and regex matches if advanced redirects are enabled.
		if ( null === $link && ! empty( $this->settings->all()['enable_advanced_redirects'] ) ) {
			$path = $this->extract_path_from_request();

			if ( '' !== $path ) {
				// Step 2: Direct (prefix-free) exact match.
				$link = $this->db->get_direct_link_by_path( $path );
				if ( is_array( $link ) ) {
					$match_slug = $path;
				}

				// Step 3: Regex pattern match.
				if ( null === $link ) {
					$regex_result = $this->match_regex_rules( $path );
					if ( null !== $regex_result ) {
						$link       = $regex_result['link'];
						$match_slug = $path;
					}
				}
			}
		}
```

**Sink** — `includes/class-gt-link-redirect.php:202-207`

A validated external URL and configured status reach the Location header.

```php
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Redirect-By: GT Link Manager', true );
		header( 'Location: ' . $target_url, true, $status );
```

**Expected control** — `includes/class-gt-link-admin.php:876-885`

The admin form enforces a reservation that the other entry paths do not reuse.

```php
		// Block reserved WordPress paths for direct links.
		if ( 'direct' === $link_mode && '' !== $data['slug'] ) {
			$reserved      = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-cron.php', 'wp-json', 'xmlrpc.php', 'feed', 'comments' );
			$first_segment = explode( '/', trim( $data['slug'], '/' ) )[0];
			if ( in_array( strtolower( $first_segment ), $reserved, true ) ) {
				$this->redirect_with_notice(
					admin_url( 'admin.php?page=gtlm-links-edit' . ( absint( $_POST['link_id'] ?? 0 ) > 0 ? '&link_id=' . absint( $_POST['link_id'] ) : '' ) ),
					'reserved_path'
				);
			}
```

#### Validation

The admin form rejects direct paths such as wp-login.php, xmlrpc.php, and wp-json. REST and CSV creation bypass that check: their payloads reach GTLM_DB::insert_link(), whose shared validator accepts every non-regex definition. A direct rule with slug wp-login.php, an external HTTPS destination, and redirect_type 307 is therefore accepted. The init resolver processes direct and regex matches without excluding login paths or POST requests and emits the configured Location response. Regex rules can also match protected paths even when created through the admin form.

Validation method: static source trace

**Entrypoint** — `includes/class-gt-link-rest-api.php:297-300`

This capability admits lower-privileged link managers to the shared REST boundary.

```php
	public function permissions_check(): bool {
		$capability = (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'rest_api' );
		return current_user_can( $capability );
	}
```

**User input** — `includes/class-gt-link-rest-api.php:414-437`

The REST handler forwards the caller-controlled definition into shared persistence.

```php
	public function create_link( WP_REST_Request $request ) {
		$data = $this->sanitize_link_payload( $request );
		if ( ! GTLM_DB::valid_link_definition( $data ) ) {
			return new WP_Error( 'gtlm_invalid_pattern', __( 'The regular expression is invalid.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === $data['name'] || '' === $data['url'] ) {
			return new WP_Error( 'gtlm_invalid', __( 'Name and URL are required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		if ( null !== $this->db->get_link_by_exact_slug( (string) $data['slug'] ) ) {
			return new WP_Error( 'gtlm_slug_exists', __( 'Slug already exists.', 'gt-link-manager' ), array( 'status' => 409 ) );
		}

		$id = $this->db->insert_link( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'gtlm_create_failed', __( 'Could not create link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		$link = $this->db->get_link_by_id( $id );
		return new WP_REST_Response( is_array( $link ) ? $this->prepare_link_row( $link ) : $link, 201 );
```

**Root control** — `includes/class-gt-link-db.php:98-105`

The shared validator accepts direct definitions without checking protected endpoints.

```php
	/** Validate regex syntax before any write, including admin and imports. */
	public static function valid_link_definition( array $data ): bool {
		if ( 'regex' !== ( $data['link_mode'] ?? 'standard' ) ) {
			return true;
		}
		$pattern = (string) ( $data['slug'] ?? '' );
		// A malformed user-supplied pattern is a validation failure, not a PHP warning.
		return '' !== $pattern && strlen( $pattern ) <= 255 && false !== @preg_match( '#' . $pattern . '#', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
```

**Propagation** — `includes/class-gt-link-redirect.php:60-103`

Stored direct and regex rules reach the resolver without a login-path or POST exclusion.

```php
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$link         = null;
		$match_slug   = '';
		$regex_result = null;

		// Step 1: Try standard prefix-based lookup (fastest path).
		$slug = $this->extract_slug_from_request();

		// A non-empty slug means the request path matched the configured
		// prefix, so this URL is ours to answer for -- including answering 404.
		$prefix_matched = ( '' !== $slug );

		if ( '' !== $slug ) {
			$link = $this->db->get_link_by_slug( $slug );
			if ( is_array( $link ) ) {
				$match_slug = $slug;
			}
		}

		// Step 2 & 3: Try direct and regex matches if advanced redirects are enabled.
		if ( null === $link && ! empty( $this->settings->all()['enable_advanced_redirects'] ) ) {
			$path = $this->extract_path_from_request();

			if ( '' !== $path ) {
				// Step 2: Direct (prefix-free) exact match.
				$link = $this->db->get_direct_link_by_path( $path );
				if ( is_array( $link ) ) {
					$match_slug = $path;
				}

				// Step 3: Regex pattern match.
				if ( null === $link ) {
					$regex_result = $this->match_regex_rules( $path );
					if ( null !== $regex_result ) {
						$link       = $regex_result['link'];
						$match_slug = $path;
					}
				}
			}
		}
```

**Sink** — `includes/class-gt-link-redirect.php:202-207`

A validated external URL and configured status reach the Location header.

```php
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Redirect-By: GT Link Manager', true );
		header( 'Location: ' . $target_url, true, $status );
```

**Expected control** — `includes/class-gt-link-admin.php:876-885`

The admin form enforces a reservation that the other entry paths do not reuse.

```php
		// Block reserved WordPress paths for direct links.
		if ( 'direct' === $link_mode && '' !== $data['slug'] ) {
			$reserved      = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-cron.php', 'wp-json', 'xmlrpc.php', 'feed', 'comments' );
			$first_segment = explode( '/', trim( $data['slug'], '/' ) )[0];
			if ( in_array( strtolower( $first_segment ), $reserved, true ) ) {
				$this->redirect_with_notice(
					admin_url( 'admin.php?page=gtlm-links-edit' . ( absint( $_POST['link_id'] ?? 0 ) > 0 ? '&link_id=' . absint( $_POST['link_id'] ) : '' ) ),
					'reserved_path'
				);
			}
```

Limitations:
- No exploit or live security probe was executed. WordPress core and host configuration remain stated prerequisites.

#### Dataflow

The admin form rejects direct paths such as wp-login.php, xmlrpc.php, and wp-json. REST and CSV creation bypass that check: their payloads reach GTLM_DB::insert_link(), whose shared validator accepts every non-regex definition. A direct rule with slug wp-login.php, an external HTTPS destination, and redirect_type 307 is therefore accepted. The init resolver processes direct and regex matches without excluding login paths or POST requests and emits the configured Location response. Regex rules can also match protected paths even when created through the admin form.

- **Source:** An authenticated user with edit_posts, including a default Contributor, on a site where an administrator has enabled advanced redirects.

- **Sink:** Location response

- **Outcome:** A lower-privileged link manager can intercept protected endpoints and disrupt authentication or API access. A login form already open when a malicious 307 rule becomes active can have its submitted credentials forwarded to the external destination because 307 preserves the POST method and body.

**Entrypoint** — `includes/class-gt-link-rest-api.php:297-300`

This capability admits lower-privileged link managers to the shared REST boundary.

```php
	public function permissions_check(): bool {
		$capability = (string) apply_filters( 'gtlm_capabilities', 'edit_posts', 'rest_api' );
		return current_user_can( $capability );
	}
```

**User input** — `includes/class-gt-link-rest-api.php:414-437`

The REST handler forwards the caller-controlled definition into shared persistence.

```php
	public function create_link( WP_REST_Request $request ) {
		$data = $this->sanitize_link_payload( $request );
		if ( ! GTLM_DB::valid_link_definition( $data ) ) {
			return new WP_Error( 'gtlm_invalid_pattern', __( 'The regular expression is invalid.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === $data['name'] || '' === $data['url'] ) {
			return new WP_Error( 'gtlm_invalid', __( 'Name and URL are required.', 'gt-link-manager' ), array( 'status' => 400 ) );
		}

		if ( '' === $data['slug'] ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		if ( null !== $this->db->get_link_by_exact_slug( (string) $data['slug'] ) ) {
			return new WP_Error( 'gtlm_slug_exists', __( 'Slug already exists.', 'gt-link-manager' ), array( 'status' => 409 ) );
		}

		$id = $this->db->insert_link( $data );
		if ( $id <= 0 ) {
			return new WP_Error( 'gtlm_create_failed', __( 'Could not create link.', 'gt-link-manager' ), array( 'status' => 500 ) );
		}

		$link = $this->db->get_link_by_id( $id );
		return new WP_REST_Response( is_array( $link ) ? $this->prepare_link_row( $link ) : $link, 201 );
```

**Root control** — `includes/class-gt-link-db.php:98-105`

The shared validator accepts direct definitions without checking protected endpoints.

```php
	/** Validate regex syntax before any write, including admin and imports. */
	public static function valid_link_definition( array $data ): bool {
		if ( 'regex' !== ( $data['link_mode'] ?? 'standard' ) ) {
			return true;
		}
		$pattern = (string) ( $data['slug'] ?? '' );
		// A malformed user-supplied pattern is a validation failure, not a PHP warning.
		return '' !== $pattern && strlen( $pattern ) <= 255 && false !== @preg_match( '#' . $pattern . '#', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
```

**Propagation** — `includes/class-gt-link-redirect.php:60-103`

Stored direct and regex rules reach the resolver without a login-path or POST exclusion.

```php
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$link         = null;
		$match_slug   = '';
		$regex_result = null;

		// Step 1: Try standard prefix-based lookup (fastest path).
		$slug = $this->extract_slug_from_request();

		// A non-empty slug means the request path matched the configured
		// prefix, so this URL is ours to answer for -- including answering 404.
		$prefix_matched = ( '' !== $slug );

		if ( '' !== $slug ) {
			$link = $this->db->get_link_by_slug( $slug );
			if ( is_array( $link ) ) {
				$match_slug = $slug;
			}
		}

		// Step 2 & 3: Try direct and regex matches if advanced redirects are enabled.
		if ( null === $link && ! empty( $this->settings->all()['enable_advanced_redirects'] ) ) {
			$path = $this->extract_path_from_request();

			if ( '' !== $path ) {
				// Step 2: Direct (prefix-free) exact match.
				$link = $this->db->get_direct_link_by_path( $path );
				if ( is_array( $link ) ) {
					$match_slug = $path;
				}

				// Step 3: Regex pattern match.
				if ( null === $link ) {
					$regex_result = $this->match_regex_rules( $path );
					if ( null !== $regex_result ) {
						$link       = $regex_result['link'];
						$match_slug = $path;
					}
				}
			}
		}
```

**Sink** — `includes/class-gt-link-redirect.php:202-207`

A validated external URL and configured status reach the Location header.

```php
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Redirect-By: GT Link Manager', true );
		header( 'Location: ' . $target_url, true, $status );
```

**Expected control** — `includes/class-gt-link-admin.php:876-885`

The admin form enforces a reservation that the other entry paths do not reuse.

```php
		// Block reserved WordPress paths for direct links.
		if ( 'direct' === $link_mode && '' !== $data['slug'] ) {
			$reserved      = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'wp-cron.php', 'wp-json', 'xmlrpc.php', 'feed', 'comments' );
			$first_segment = explode( '/', trim( $data['slug'], '/' ) )[0];
			if ( in_array( strtolower( $first_segment ), $reserved, true ) ) {
				$this->redirect_with_notice(
					admin_url( 'admin.php?page=gtlm-links-edit' . ( absint( $_POST['link_id'] ?? 0 ) > 0 ? '&link_id=' . absint( $_POST['link_id'] ) : '' ) ),
					'reserved_path'
				);
			}
```

#### Reachability

An authenticated user with edit_posts, including a default Contributor, on a site where an administrator has enabled advanced redirects. Advanced redirects default to disabled and require administrator enablement.

- **Attacker:** An authenticated user with edit_posts, including a default Contributor, on a site where an administrator has enabled advanced redirects.

- **Entry point:** REST/CSV link writes or stored regex routing

- **Outcome:** A lower-privileged link manager can intercept protected endpoints and disrupt authentication or API access. A login form already open when a malicious 307 rule becomes active can have its submitted credentials forwarded to the external destination because 307 preserves the POST method and body.

Limitations:
- Advanced redirects default to disabled and require administrator enablement.
- REST and admin writes require authenticated capabilities, and admin writes require nonces.
- Destination validation rejects unsafe schemes, credentials in URLs, and control characters; an ordinary external HTTPS destination remains valid.
- The existing admin-only check protects direct rules submitted through that particular form, but does not protect REST, imports, existing stored rules, or regex matches.

#### Severity

**High** — Protected routing can be taken over by an authenticated low-privilege link manager when the documented advanced feature is enabled. Login-POST forwarding has additional victim-interaction prerequisites; no server execution was claimed.

Additional runtime or deployment evidence could raise or lower this severity.

#### Remediation

Centralize direct-path validation in the shared data layer and enforce a protected-endpoint exclusion before direct or regex resolution. Cover WordPress subdirectory installations and both stored and newly created rules. Verify that login, XML-RPC, and REST requests cannot be redirected by any link mode, including POST requests and rules created through REST or CSV.

Tests:
- Reject protected direct paths through REST and imports.
- Skip login, XML-RPC and REST paths before all direct/regex matching, including POST and subdirectory installations.

Preventive controls:
- Apply protected-path restrictions across every write path and at redirect resolution

<a id="finding-2"></a>

### [2] CSV previews publish raw import files and leave abandoned uploads accessible

| Field | Value |
| --- | --- |
| Severity | medium |
| Confidence | high |
| Confidence rationale | Independent baseline source review and parent validation established the same control failure; deployment-dependent effects remain explicit. |
| Category | sensitive-data-exposure |
| CWE | CWE-552 |
| Affected lines | includes/class-gt-link-import.php:258-291, includes/class-gt-link-import.php:304-317, includes/class-gt-link-import.php:402-403, includes/class-gt-link-import.php:572-584 |

#### Summary

preview_csv() passes the uploaded file to wp_handle_upload() with its normal public-upload destination and no private storage override. It retains the raw uploaded file while storing only its local path and preview state in a per-user transient. Cleanup occurs after a successful import or when replacing a still-valid preview. The transient expires after one hour without deleting the file, and upload parsing failures can return before any cleanup state is stored.

#### Root Cause

Files supplied to an authenticated administrative import must not become publicly downloadable. preview_csv() passes the uploaded file to wp_handle_upload() with its normal public-upload destination and no private storage override. It retains the raw uploaded file while storing only its local path and preview state in a per-user transient. Cleanup occurs after a successful import or when replacing a still-valid preview. The transient expires after one hour without deleting the file, and upload parsing failures can return before any cleanup state is stored.

**Root control** — `includes/class-gt-link-import.php:258-291`

The upload uses the normal WordPress upload destination without a private override.

```php
	private function preview_csv(): void {
		$existing_preview = $this->get_preview_state();
		if ( is_array( $existing_preview ) ) {
			$this->cleanup_preview( $existing_preview );
		}

		// Nonce verified in handle_actions() via check_admin_referer( 'gtlm_import_export' ).
		if ( ! isset( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_notice( 'import_failed' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$uploaded = wp_handle_upload(
			$_FILES['import_file'], // phpcs:ignore WordPress.Security.NonceVerification.Missing
			array( 'test_form' => false )
		);

		if ( ! is_array( $uploaded ) || empty( $uploaded['file'] ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		$file_path = (string) $uploaded['file'];
		$handle    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->redirect_notice( 'import_failed' );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->redirect_notice( 'import_failed' );
```

**Propagation** — `includes/class-gt-link-import.php:304-317`

Only a transient retains the file path, and expiry does not delete the underlying file.

```php
		$preset = isset( $_POST['preset'] ) ? sanitize_key( (string) wp_unslash( $_POST['preset'] ) ) : 'generic'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = wp_generate_uuid4();

		$state = array(
			'token'      => $token,
			'file_path'  => $file_path,
			'header'     => array_values( array_map( 'sanitize_text_field', $header ) ),
			'rows'       => $rows,
			'preset'     => $preset,
			'created_at' => time(),
		);

		set_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id(), $state, HOUR_IN_SECONDS );
		$this->redirect_notice( 'preview_ready' );
```

**Outcome** — `includes/class-gt-link-import.php:402-403`

Cleanup is reached on successful completion, not every abandonment or failure path.

```php
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->cleanup_preview( $preview );
```

**Outcome** — `includes/class-gt-link-import.php:572-584`

Expired state becomes unavailable, so the normal cleanup path loses its file reference.

```php
	private function get_preview_state(): ?array {
		$state = get_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $preview Preview.
	 */
	private function cleanup_preview( array $preview ): void {
		if ( ! empty( $preview['file_path'] ) && is_string( $preview['file_path'] ) && file_exists( $preview['file_path'] ) ) {
			wp_delete_file( $preview['file_path'] );
		}
		delete_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
```

#### Validation

preview_csv() passes the uploaded file to wp_handle_upload() with its normal public-upload destination and no private storage override. It retains the raw uploaded file while storing only its local path and preview state in a per-user transient. Cleanup occurs after a successful import or when replacing a still-valid preview. The transient expires after one hour without deleting the file, and upload parsing failures can return before any cleanup state is stored.

Validation method: static source trace

**Root control** — `includes/class-gt-link-import.php:258-291`

The upload uses the normal WordPress upload destination without a private override.

```php
	private function preview_csv(): void {
		$existing_preview = $this->get_preview_state();
		if ( is_array( $existing_preview ) ) {
			$this->cleanup_preview( $existing_preview );
		}

		// Nonce verified in handle_actions() via check_admin_referer( 'gtlm_import_export' ).
		if ( ! isset( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_notice( 'import_failed' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$uploaded = wp_handle_upload(
			$_FILES['import_file'], // phpcs:ignore WordPress.Security.NonceVerification.Missing
			array( 'test_form' => false )
		);

		if ( ! is_array( $uploaded ) || empty( $uploaded['file'] ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		$file_path = (string) $uploaded['file'];
		$handle    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->redirect_notice( 'import_failed' );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->redirect_notice( 'import_failed' );
```

**Propagation** — `includes/class-gt-link-import.php:304-317`

Only a transient retains the file path, and expiry does not delete the underlying file.

```php
		$preset = isset( $_POST['preset'] ) ? sanitize_key( (string) wp_unslash( $_POST['preset'] ) ) : 'generic'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = wp_generate_uuid4();

		$state = array(
			'token'      => $token,
			'file_path'  => $file_path,
			'header'     => array_values( array_map( 'sanitize_text_field', $header ) ),
			'rows'       => $rows,
			'preset'     => $preset,
			'created_at' => time(),
		);

		set_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id(), $state, HOUR_IN_SECONDS );
		$this->redirect_notice( 'preview_ready' );
```

**Outcome** — `includes/class-gt-link-import.php:402-403`

Cleanup is reached on successful completion, not every abandonment or failure path.

```php
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->cleanup_preview( $preview );
```

**Outcome** — `includes/class-gt-link-import.php:572-584`

Expired state becomes unavailable, so the normal cleanup path loses its file reference.

```php
	private function get_preview_state(): ?array {
		$state = get_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $preview Preview.
	 */
	private function cleanup_preview( array $preview ): void {
		if ( ! empty( $preview['file_path'] ) && is_string( $preview['file_path'] ) && file_exists( $preview['file_path'] ) ) {
			wp_delete_file( $preview['file_path'] );
		}
		delete_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
```

Limitations:
- No exploit or live security probe was executed. WordPress core and host configuration remain stated prerequisites.

#### Dataflow

preview_csv() passes the uploaded file to wp_handle_upload() with its normal public-upload destination and no private storage override. It retains the raw uploaded file while storing only its local path and preview state in a per-user transient. Cleanup occurs after a successful import or when replacing a still-valid preview. The transient expires after one hour without deleting the file, and upload parsing failures can return before any cleanup state is stored.

- **Source:** An unauthenticated visitor who knows or guesses the uploaded filename and upload date.

- **Sink:** retained WordPress upload file

- **Outcome:** Raw CSV contents, including private notes, inactive destinations, or unmapped source columns, can be retrieved through the normal uploads URL without the plugin's capability or nonce checks. Abandoned or failed imports can remain accessible indefinitely.

**Root control** — `includes/class-gt-link-import.php:258-291`

The upload uses the normal WordPress upload destination without a private override.

```php
	private function preview_csv(): void {
		$existing_preview = $this->get_preview_state();
		if ( is_array( $existing_preview ) ) {
			$this->cleanup_preview( $existing_preview );
		}

		// Nonce verified in handle_actions() via check_admin_referer( 'gtlm_import_export' ).
		if ( ! isset( $_FILES['import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->redirect_notice( 'import_failed' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$uploaded = wp_handle_upload(
			$_FILES['import_file'], // phpcs:ignore WordPress.Security.NonceVerification.Missing
			array( 'test_form' => false )
		);

		if ( ! is_array( $uploaded ) || empty( $uploaded['file'] ) ) {
			$this->redirect_notice( 'import_failed' );
		}

		$file_path = (string) $uploaded['file'];
		$handle    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->redirect_notice( 'import_failed' );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! is_array( $header ) || empty( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->redirect_notice( 'import_failed' );
```

**Propagation** — `includes/class-gt-link-import.php:304-317`

Only a transient retains the file path, and expiry does not delete the underlying file.

```php
		$preset = isset( $_POST['preset'] ) ? sanitize_key( (string) wp_unslash( $_POST['preset'] ) ) : 'generic'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = wp_generate_uuid4();

		$state = array(
			'token'      => $token,
			'file_path'  => $file_path,
			'header'     => array_values( array_map( 'sanitize_text_field', $header ) ),
			'rows'       => $rows,
			'preset'     => $preset,
			'created_at' => time(),
		);

		set_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id(), $state, HOUR_IN_SECONDS );
		$this->redirect_notice( 'preview_ready' );
```

**Outcome** — `includes/class-gt-link-import.php:402-403`

Cleanup is reached on successful completion, not every abandonment or failure path.

```php
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->cleanup_preview( $preview );
```

**Outcome** — `includes/class-gt-link-import.php:572-584`

Expired state becomes unavailable, so the normal cleanup path loses its file reference.

```php
	private function get_preview_state(): ?array {
		$state = get_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $preview Preview.
	 */
	private function cleanup_preview( array $preview ): void {
		if ( ! empty( $preview['file_path'] ) && is_string( $preview['file_path'] ) && file_exists( $preview['file_path'] ) ) {
			wp_delete_file( $preview['file_path'] );
		}
		delete_transient( self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id() );
```

#### Reachability

An unauthenticated visitor who knows or guesses the uploaded filename and upload date. Starting the import requires an authenticated link-management capability and a valid nonce.

- **Attacker:** An unauthenticated visitor who knows or guesses the uploaded filename and upload date.

- **Entry point:** Authenticated import preview followed by public upload access

- **Outcome:** Raw CSV contents, including private notes, inactive destinations, or unmapped source columns, can be retrieved through the normal uploads URL without the plugin's capability or nonce checks. Abandoned or failed imports can remain accessible indefinitely.

Limitations:
- Starting the import requires an authenticated link-management capability and a valid nonce.
- The preview token and state are tied to the current user, which prevents cross-user access through the import controller.
- Successful imports delete their uploaded file.
- WordPress upload MIME checks limit file types, but do not make ordinary uploads private or remove abandoned files.

#### Severity

**Medium** — Administrative input is placed in the normal uploads area and can outlive preview authorization. Exposure depends on ordinary public upload serving and a known or guessed filename.

Additional runtime or deployment evidence could raise or lower this severity.

#### Remediation

Store imports in private temporary storage outside the document root, using random filenames and restrictive permissions. Retain an owner-bound reference for the mapping step and provide cleanup for replacement, cancellation, parse errors, import failures, and expiry. Restrict the importer to supported CSV files and apply a size limit. Handle any existing orphaned public uploads separately using exact verified paths.

Tests:
- Keep import files outside every known public root with restrictive permissions.
- Delete files on replacement, cancellation, parse/import errors and expiry.

Preventive controls:
- Keep administrative import files outside public uploads and clean every lifecycle exit

<a id="finding-3"></a>

### [3] Legacy link CSV export preserves attacker-controlled spreadsheet formulas

| Field | Value |
| --- | --- |
| Severity | medium |
| Confidence | high |
| Confidence rationale | Independent baseline source review and parent validation established the same control failure; deployment-dependent effects remain explicit. |
| Category | csv-injection |
| CWE | CWE-1236 |
| Affected lines | includes/class-gt-link-rest-api.php:832-847, includes/class-gt-link-import.php:228-251, includes/class-gt-link-db.php:1030-1045 |

#### Summary

REST, admin, and CSV inputs store text using sanitize_text_field() or sanitize_textarea_field(), which preserve formula prefixes such as =. GTLM_Import::export_csv() reads those stored fields and passes them directly to fputcsv(), including name, category, tags, and notes. CSV quoting does not prevent spreadsheet software from interpreting a cell as a formula.

#### Root Cause

Exported link metadata must remain data when another user opens the CSV in spreadsheet software. REST, admin, and CSV inputs store text using sanitize_text_field() or sanitize_textarea_field(), which preserve formula prefixes such as =. GTLM_Import::export_csv() reads those stored fields and passes them directly to fputcsv(), including name, category, tags, and notes. CSV quoting does not prevent spreadsheet software from interpreting a cell as a formula.

**User input** — `includes/class-gt-link-rest-api.php:832-847`

Caller-controlled metadata survives text sanitization as formula-like text.

```php
		return array(
			'name'              => sanitize_text_field( (string) $name ),
			'slug'              => $sanitized_slug,
			'url'               => esc_url_raw( (string) $url ),
			'redirect_type'     => $redirect_type,
			'rel'               => implode( ',', $rel_values ),
			'noindex'           => ! empty( $noindex ) ? 1 : 0,
			'is_active'         => null !== $is_active ? ( ! empty( $is_active ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) $regex_replacement ),
			'priority'          => max( 0, (int) $priority ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => GTLM_Geo::encode_rules( $geo_rules ),
			'category_id'       => absint( $category_id ),
			'tags'              => sanitize_text_field( (string) $tags ),
			'notes'             => sanitize_textarea_field( (string) $notes ),
```

**Propagation** — `includes/class-gt-link-db.php:1030-1045`

Database normalization retains the attacker-controlled name and notes.

```php
		return array(
			'name'              => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'              => $slug,
			'url'               => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'redirect_type'     => $this->sanitize_redirect_type( (int) ( $data['redirect_type'] ?? 301 ) ),
			'rel'               => $rel,
			'noindex'           => ! empty( $data['noindex'] ) ? 1 : 0,
			'is_active'         => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) ( $data['regex_replacement'] ?? '' ) ),
			'priority'          => max( 0, (int) ( $data['priority'] ?? 10 ) ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => 'off' === $geo_mode ? '' : GTLM_Geo::encode_rules( $data['geo_rules'] ?? '' ),
			'category_id'       => absint( $data['category_id'] ?? 0 ),
			'tags'              => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
```

**Root control** — `includes/class-gt-link-import.php:228-251`

The export passes stored text to CSV output without neutralizing formula prefixes.

```php
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$output,
				array(
					(string) $row['name'],
					(string) $row['slug'],
					(string) $row['url'],
					(int) $row['redirect_type'],
					(string) $row['rel'],
					(int) $row['noindex'],
					$cat_map[ (int) $row['category_id'] ] ?? '',
					(string) $row['tags'],
					(string) $row['notes'],
					(string) ( $row['geo_mode'] ?? 'off' ),
					(string) ( $row['geo_rules'] ?? '' ),
					(int) ( $row['total_clicks'] ?? 0 ),
					2,
					(string) $row['link_mode'],
					(string) $row['regex_replacement'],
					(int) $row['priority'],
					(int) $row['is_active'],
				)
			);
```

#### Validation

REST, admin, and CSV inputs store text using sanitize_text_field() or sanitize_textarea_field(), which preserve formula prefixes such as =. GTLM_Import::export_csv() reads those stored fields and passes them directly to fputcsv(), including name, category, tags, and notes. CSV quoting does not prevent spreadsheet software from interpreting a cell as a formula.

Validation method: static source trace

**User input** — `includes/class-gt-link-rest-api.php:832-847`

Caller-controlled metadata survives text sanitization as formula-like text.

```php
		return array(
			'name'              => sanitize_text_field( (string) $name ),
			'slug'              => $sanitized_slug,
			'url'               => esc_url_raw( (string) $url ),
			'redirect_type'     => $redirect_type,
			'rel'               => implode( ',', $rel_values ),
			'noindex'           => ! empty( $noindex ) ? 1 : 0,
			'is_active'         => null !== $is_active ? ( ! empty( $is_active ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) $regex_replacement ),
			'priority'          => max( 0, (int) $priority ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => GTLM_Geo::encode_rules( $geo_rules ),
			'category_id'       => absint( $category_id ),
			'tags'              => sanitize_text_field( (string) $tags ),
			'notes'             => sanitize_textarea_field( (string) $notes ),
```

**Propagation** — `includes/class-gt-link-db.php:1030-1045`

Database normalization retains the attacker-controlled name and notes.

```php
		return array(
			'name'              => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'              => $slug,
			'url'               => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'redirect_type'     => $this->sanitize_redirect_type( (int) ( $data['redirect_type'] ?? 301 ) ),
			'rel'               => $rel,
			'noindex'           => ! empty( $data['noindex'] ) ? 1 : 0,
			'is_active'         => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) ( $data['regex_replacement'] ?? '' ) ),
			'priority'          => max( 0, (int) ( $data['priority'] ?? 10 ) ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => 'off' === $geo_mode ? '' : GTLM_Geo::encode_rules( $data['geo_rules'] ?? '' ),
			'category_id'       => absint( $data['category_id'] ?? 0 ),
			'tags'              => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
```

**Root control** — `includes/class-gt-link-import.php:228-251`

The export passes stored text to CSV output without neutralizing formula prefixes.

```php
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$output,
				array(
					(string) $row['name'],
					(string) $row['slug'],
					(string) $row['url'],
					(int) $row['redirect_type'],
					(string) $row['rel'],
					(int) $row['noindex'],
					$cat_map[ (int) $row['category_id'] ] ?? '',
					(string) $row['tags'],
					(string) $row['notes'],
					(string) ( $row['geo_mode'] ?? 'off' ),
					(string) ( $row['geo_rules'] ?? '' ),
					(int) ( $row['total_clicks'] ?? 0 ),
					2,
					(string) $row['link_mode'],
					(string) $row['regex_replacement'],
					(int) $row['priority'],
					(int) $row['is_active'],
				)
			);
```

Limitations:
- No exploit or live security probe was executed. WordPress core and host configuration remain stated prerequisites.

#### Dataflow

REST, admin, and CSV inputs store text using sanitize_text_field() or sanitize_textarea_field(), which preserve formula prefixes such as =. GTLM_Import::export_csv() reads those stored fields and passes them directly to fputcsv(), including name, category, tags, and notes. CSV quoting does not prevent spreadsheet software from interpreting a cell as a formula.

- **Source:** An authenticated link manager who can set link names, tags, notes, or category names; alternatively, a supplier of CSV content subsequently imported by a link manager.

- **Sink:** CSV text-cell output

- **Outcome:** A recipient who opens the exported CSV can evaluate attacker-supplied spreadsheet formulas. Depending on the spreadsheet and its external-content settings, formulas can create deceptive links or initiate external requests. No server-side code execution is established.

**User input** — `includes/class-gt-link-rest-api.php:832-847`

Caller-controlled metadata survives text sanitization as formula-like text.

```php
		return array(
			'name'              => sanitize_text_field( (string) $name ),
			'slug'              => $sanitized_slug,
			'url'               => esc_url_raw( (string) $url ),
			'redirect_type'     => $redirect_type,
			'rel'               => implode( ',', $rel_values ),
			'noindex'           => ! empty( $noindex ) ? 1 : 0,
			'is_active'         => null !== $is_active ? ( ! empty( $is_active ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) $regex_replacement ),
			'priority'          => max( 0, (int) $priority ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => GTLM_Geo::encode_rules( $geo_rules ),
			'category_id'       => absint( $category_id ),
			'tags'              => sanitize_text_field( (string) $tags ),
			'notes'             => sanitize_textarea_field( (string) $notes ),
```

**Propagation** — `includes/class-gt-link-db.php:1030-1045`

Database normalization retains the attacker-controlled name and notes.

```php
		return array(
			'name'              => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'slug'              => $slug,
			'url'               => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'redirect_type'     => $this->sanitize_redirect_type( (int) ( $data['redirect_type'] ?? 301 ) ),
			'rel'               => $rel,
			'noindex'           => ! empty( $data['noindex'] ) ? 1 : 0,
			'is_active'         => isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1,
			'link_mode'         => $link_mode,
			'regex_replacement' => sanitize_text_field( (string) ( $data['regex_replacement'] ?? '' ) ),
			'priority'          => max( 0, (int) ( $data['priority'] ?? 10 ) ),
			'geo_mode'          => $geo_mode,
			'geo_rules'         => 'off' === $geo_mode ? '' : GTLM_Geo::encode_rules( $data['geo_rules'] ?? '' ),
			'category_id'       => absint( $data['category_id'] ?? 0 ),
			'tags'              => sanitize_text_field( (string) ( $data['tags'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
```

**Root control** — `includes/class-gt-link-import.php:228-251`

The export passes stored text to CSV output without neutralizing formula prefixes.

```php
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$output,
				array(
					(string) $row['name'],
					(string) $row['slug'],
					(string) $row['url'],
					(int) $row['redirect_type'],
					(string) $row['rel'],
					(int) $row['noindex'],
					$cat_map[ (int) $row['category_id'] ] ?? '',
					(string) $row['tags'],
					(string) $row['notes'],
					(string) ( $row['geo_mode'] ?? 'off' ),
					(string) ( $row['geo_rules'] ?? '' ),
					(int) ( $row['total_clicks'] ?? 0 ),
					2,
					(string) $row['link_mode'],
					(string) $row['regex_replacement'],
					(int) $row['priority'],
					(int) $row['is_active'],
				)
			);
```

#### Reachability

An authenticated link manager who can set link names, tags, notes, or category names; alternatively, a supplier of CSV content subsequently imported by a link manager. Export requires an authenticated link-management capability and a valid nonce.

- **Attacker:** An authenticated link manager who can set link names, tags, notes, or category names; alternatively, a supplier of CSV content subsequently imported by a link manager.

- **Entry point:** Link metadata writes followed by CSV export

- **Outcome:** A recipient who opens the exported CSV can evaluate attacker-supplied spreadsheet formulas. Depending on the spreadsheet and its external-content settings, formulas can create deceptive links or initiate external requests. No server-side code execution is established.

Limitations:
- Export requires an authenticated link-management capability and a valid nonce.
- HTML output is escaped elsewhere, but HTML escaping does not address spreadsheet formula interpretation.
- The newer analytics export already prefixes formula-like cells in includes/class-gtlm-analytics-controller.php:443-449; that protection is absent from the legacy link export.

#### Severity

**Medium** — Compatible spreadsheets interpret formula-prefixed cells during the normal export/open workflow. External-content effects depend on spreadsheet settings; no server-side execution was established.

Additional runtime or deployment evidence could raise or lower this severity.

#### Remediation

Use a shared CSV text-cell neutralizer for both exports, including leading whitespace/control-character variants of formula prefixes. Preserve numeric columns as numbers and account for safe import round trips so neutralization is not silently removed before spreadsheet consumption.

Tests:
- Export text beginning with formula characters as inert data.
- Round-trip escaped cells without changing literal apostrophes or numeric columns.

Preventive controls:
- Neutralize formula-like text cells before spreadsheet CSV export

## Reviewed Surfaces

| Surface | Risk Area | Outcome | Notes |
| --- | --- | --- | --- |
| Reserved WordPress paths can be redirected through REST, CSV, and regex rules | not recorded | Reported | The admin form rejects direct paths such as wp-login.php, xmlrpc.php, and wp-json. REST and CSV creation bypass that check: their payloads reach GTLM_DB::insert_link(), whose shared validator accepts every non-regex definition. A direct rule with slug wp-login.php, an external HTTPS destination, and redirect_type 307 is therefore accepted. The init resolver processes direct and regex matches without excluding login paths or POST requests and emits the configured Location response. Regex rules can also match protected paths even when created through the admin form. |
| Legacy link CSV export preserves attacker-controlled spreadsheet formulas | not recorded | Reported | REST, admin, and CSV inputs store text using sanitize_text_field() or sanitize_textarea_field(), which preserve formula prefixes such as =. GTLM_Import::export_csv() reads those stored fields and passes them directly to fputcsv(), including name, category, tags, and notes. CSV quoting does not prevent spreadsheet software from interpreting a cell as a formula. |
| CSV previews publish raw import files and leave abandoned uploads accessible | not recorded | Reported | preview_csv() passes the uploaded file to wp_handle_upload() with its normal public-upload destination and no private storage override. It retains the raw uploaded file while storing only its local path and preview state in a per-user transient. Cleanup occurs after a successful import or when replacing a still-valid preview. The transient expires after one hour without deleting the file, and upload parsing failures can return before any cleanup state is stored. |
| Does ordinary link management expose an unintended role or ownership bypass? | not recorded | No issue found | General link management intentionally defaults to edit_posts across admin and REST, and the repository explicitly documents that grant. No general IDOR finding was raised solely because links lack per-user ownership. |
| Can anonymous users read or mutate advanced analytics? | not recorded | No issue found | No path was found. Analytics REST reads default to manage_options; mutations always require manage_options. Native controls and CSV export enforce capabilities and nonces. Intentional delegated report access uses a separate filter. |
| Can ordinary settings silently opt a site into advanced analytics? | not recorded | No issue found | The persisted consent gate is absent from defaults, is read independently of generic settings filters, and is excluded from ordinary settings updates. Collection additionally requires active configuration and an unexpired maintenance lease. |
| Do analytics report filters reach SQL or HTML unsafely? | not recorded | No issue found | Date ranges and dimensions are constrained, SQL values are prepared, identifier choices are internal, and HTML report cells are escaped. The collector reduces visitor inputs to bounded fields and configured campaign identifiers. |
| Were SSRF, unsafe deserialization, command injection, or server-side executable uploads established? | not recorded | No issue found | No. Product HTTP calls are administrator-triggered same-site diagnostics with redirects disabled. Deserialization reads an internal WordPress option. No attacker-controlled command-execution sink was found. The import issue establishes public file exposure, not executable-file upload. |
| Were availability concerns excluded from confirmed findings? | not recorded | No issue found | The source permits indefinite negative-cache entries for arbitrary missing slugs when a persistent object cache is present, and regex evaluation lacks a plugin-level workload bound. This pass did not establish deployment-specific resource impact sufficient for a separate confirmed finding. Analytics explicitly describes its storage threshold as soft rather than a hard quota. |
| Privileged build, deployment and fixture boundaries | not recorded | No issue found | Release workflow is maintainer-triggered; fixture scripts require a disposable database marker. Dependency implementation/advisory review was not performed. |

## Open Questions And Follow Up

- Were availability concerns excluded from confirmed findings?
- What are the verification limits?
