# Advanced analytics

Version 1.9.0 adds independent owner opt-in under **GT Links > Analytics**. Installing or updating does not create analytics storage. Basic click counts retain their separate setting and semantics.

Dates, filters, daily/hourly grouping, status timestamps and exports follow `wp_timezone()`. Internal event timestamps and minute aggregate buckets use UTC to preserve stable instants. Reporting converts and groups using WordPress's timezone rules in PHP-generated SQL expressions, without requiring MySQL timezone tables. Fractional offsets, repeated/skipped DST hours and subsequent site-timezone changes are supported. Repeated hours include an explicit offset. This supersedes the original design's proposed UTC-only UI.

The redirect collector loads only after a matched redirect has persisted owner consent. It normalizes bounded fields, checks eligibility and the maintenance health lease, and makes one prepared insert. A cold request may also read the small non-autoloaded analytics configuration. It does not inspect table metadata or initialize storage. The existing atomic counter remains separate. No visitor assets, cookies, beacons, identifiers, IP storage, external requests, or mandatory cache/queue service are added.

Two tables hold short-lived events and aggregate buckets. The internal `gtlm_analytics_hourly` table name predates the timezone adjustment; its buckets have minute precision so reports can honor any WordPress offset. Default event retention is seven days; aggregate retention is ninety days. A single opted-in five-minute job aggregates transactionally and prunes bounded batches. Late lower IDs are processed through per-row flags rather than a high-water cursor. Retired generations are excluded. A fifteen-minute health lease and a soft storage threshold pause unhealthy collection. These limits do not cancel a blocked database operation or enforce a hard disk quota.

Pausing keeps reports under retention; deleting analytics removes its tables, option, gate and jobs while preserving links and basic counts. Reactivation restores retention maintenance for a previously consented installation but leaves collection paused. Multisite enablement is currently rejected before provisioning, pending a dedicated network lifecycle implementation.

Report and export access defaults to `manage_options`; `gtlm_analytics_capability` allows intentional delegation. Mutations always require administrator capability and nonce/authentication. REST controls live below `gt-link-manager/v1/analytics/`; enable/resume requires `consent=true`, deletion requires `confirm=DELETE_ANALYTICS`. Date filters are inclusive local calendar dates. Responses label storage boundaries with `_utc`; user-facing dates and timestamps use WordPress time. `gtlm_analytics_should_record` can suppress collection for a site's consent integration. No legal consent exemption is claimed.

Campaigns are exact configured UTM combinations; identifiers cannot be reassigned to different combinations. The admin form has native campaign rows and link exclusions. Unknown source/medium/campaign values are not retained. CSV export uses a consistent snapshot and keyset batches, protects formula-like cells, and requires a smaller selection above its row limit. There is no automatic polling.

## Operations

On a WordPress installation where normal WP-CLI loading is available:

```sh
wp gt-link-manager analytics status
wp gt-link-manager analytics enable --yes
wp gt-link-manager analytics process
wp gt-link-manager analytics prune
wp gt-link-manager analytics pause
wp gt-link-manager analytics delete --yes
```

`enable --config=/path/to/settings.json --yes` accepts retention, trusted country-header settings, allowlisted campaigns and exclusions. Run `process` from an existing system scheduler where WordPress cron is disabled. Do not create a per-click cron job or run load tests against production.

## Verification

The guarded scripts under `tests/` require an isolated WordPress fixture defining `GTLM_TEST_FIXTURE=true` and a database name starting with `gtlm_test_`. They create synthetic links and modify fixture settings; never run them on a live site. `tests/run.sh` runs the integration, timezone, failure, security/settings, referring-page, drilldown, chart, pagination, and retention/diagnostics suites. The CI workflow covers minimum/current WordPress and PHP combinations. Release verification records the runs for the published commit.

A GitHub release triggers the WordPress.org deployment workflow. Release only after checking the exact candidate commit and distribution; production testing alone does not replace compatibility and package checks.

The simplified admin separates Overview from Settings. Overview provides date presets, daily/hourly grouping, top links, referring sites and device/country/campaign breakdowns. Settings keeps the explicit enable checkbox, report retention and optional countries visible; campaigns and advanced controls are shown as visible sections. Saving paused preferences does not resume collection.

`tests/http_regressions.py` exercises authenticated multipart imports and real CSV export/import using a local HTTP fixture. Set `GTLM_TEST_WP_ROOT`, `GTLM_TEST_USER`, and `GTLM_TEST_PASSWORD` for that disposable installation. It verifies private staging, cancellation/error cleanup, rejected uploads, spreadsheet protection and lossless format-3 roundtrips.

## Referring posts and pages

The **Clicked from** table shows the URL that supplied the browser referrer and resolves published, unprotected WordPress post titles on the report screen. URL capture strips credentials, query strings and fragments, rejects IP hosts and administrative paths, encodes unsafe path bytes, and limits a URL to 1,024 ASCII bytes. Missing/refused referrers remain unavailable; browsers may supply only an external origin. Previous clicks cannot be reconstructed from the older hostname-only records. Query-only WordPress URLs (such as `?p=123`) remain unavailable rather than being mislabeled as the homepage.

The collector still makes one insert (plus a cold configuration read), with no post lookup, HTTP request, JavaScript or beacon. The separate Clicked from report makes every matching retained entry available through pagination, with a choice of 10, 20, 50 or 100 rows and at most that many post-title lookups per request. The worker caps distinct pages at 100 per link per UTC day across batches. More pages aggregate into Other pages. Configured event/report retention and the existing storage health limits also apply to page URLs.

Schema 2 adds the event page field and expands the aggregate value field. Migration runs only for already-initialized analytics from admin or maintenance, under its existing control lock. It preserves the original collection generation, history, settings and paused/active state. Schema-1 in-flight appends remain compatible during migration. The report includes historical clicks under Page unavailable, without fabricating URLs. CSV exports include the new page dimension after collection begins.

## Page reports and retention

Referring-page rows open a report containing the links clicked from that page, its click trend, and page-specific breakdowns. Link rows narrow the same page report. Date/category selections, tabs and exports preserve the page filter; All referring pages clears it. The source URL remains available through Open page. All six breakdowns are preloaded with the report. Accessible tabs switch panels immediately without navigation or extra requests; normal links remain as a no-JavaScript fallback. No visitor script or application framework is added.

Schema 3 adds a page cohort key to the existing summary table and a processing flag/index to events. Global totals remain in the empty cohort; page details are stored under a hash of the recorded page URL. The maintenance worker builds page details once from retained eligible records of the current collection generation, under the existing transaction/lock and time budget. Earlier totals/trends remain available from the original page dimension, even when detailed records have expired; missing breakdown details are labelled. No raw-event reads occur while serving reports.

Forever is `summary_days=0`: it disables time-based aggregate deletion only. Raw click records still expire under event_days, and storage health safeguards remain active. Historical reports can be queried in windows of up to 90 days. Adding the option does not change an existing site's retention choice. All settings use visible sections and consistently sized, labelled fields.

The tab control supports arrow keys, Home/End, Enter and Space, and keeps its active dimension in navigation links and forms. One combined, bounded summary query loads the six datasets, including page-specific detail gaps. The Privacy and data section links to the WordPress Privacy Policy Guide. Campaign removal buttons use a consistent 44px control, and Page unavailable has brief hover/focus help.

External referrers remain part of analytics. Separate help buttons beside their links explain that another website can be reported as the source of a short-link click; the referrer is not a verified endorsement or a guarantee of human traffic.

The chart scales its horizontal axis to the first and last recorded bucket, keeping real time intervals proportional; a single bucket is centered. Its axis labels show the actual displayed period in WordPress time. Exact totals open in a labelled, scrollable modal containing the already-loaded table, with Escape/Close handling and focus restoration.

The Clicked from tab has its own URL (`view=pages`), a complete entry count, Previous/Next controls and a page jump. Filters reset pagination when applied. The summary API exposes `pages_pagination` and accepts `sources_page` and `sources_per_page` (10, 20, 50 or 100; default 20); overview rendering skips the unused referring-page query. Main Settings also provides a selectable, copyable AI setup prompt containing the site and guide URLs, instructions to reuse supplied details, and requests for missing setup/access/task information.

Individual click record retention accepts positive whole days without a product cap. Values over 60 show a warning but can be saved; the default remains 7. Cutoff calculation avoids overflow for very long periods. Existing storage health safeguards remain. Settings diagnostics reads estimated data plus index bytes across all tables with this site's GTLM prefix and shows the current analytics/advanced-redirect switches. This metadata query runs only on the settings screen and does not initialize analytics. Runtime diagnostics uses HEAD to avoid generating a tracked click.
