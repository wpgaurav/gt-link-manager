# Advanced analytics test candidate

The 1.9.0 candidate adds independent owner opt-in under **GT Links > Analytics**. Installing or updating does not create analytics storage. Basic click counts retain their separate setting and semantics.

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

The guarded scripts under `tests/` require an isolated WordPress fixture defining `GTLM_TEST_FIXTURE=true` and a database name starting with `gtlm_test_`. They create synthetic links and modify fixture settings; never run them on a live site. `tests/run.sh` runs the integration, timezone, failure, and security/settings regression suites. The new CI workflow covers minimum/current WordPress and PHP combinations; authoring it does not mean the remote matrix has already run.

Local testing and the authorized gauravtiwari.org test installation are separate from a stable release. Do not publish a tag, GitHub release, WordPress.org deployment, store update or customer artifact as part of this test.

The simplified admin separates Overview from Settings. Overview provides date presets, daily/hourly grouping, top links, referring sites and device/country/campaign breakdowns. Settings keeps the explicit enable checkbox, finite report retention and optional countries visible; campaigns and advanced controls are collapsed. Saving paused preferences does not resume collection.

`tests/http_regressions.py` exercises authenticated multipart imports and real CSV export/import using a local HTTP fixture. Set `GTLM_TEST_WP_ROOT`, `GTLM_TEST_USER`, and `GTLM_TEST_PASSWORD` for that disposable installation. It verifies private staging, cancellation/error cleanup, rejected uploads, spreadsheet protection and lossless format-3 roundtrips.

## Referring posts and pages (RC3)

The **Clicked from** table shows the URL that supplied the browser referrer and resolves published, unprotected WordPress post titles on the report screen. URL capture strips credentials, query strings and fragments, rejects IP hosts and administrative paths, encodes unsafe path bytes, and limits a URL to 1,024 ASCII bytes. Missing/refused referrers remain unavailable; browsers may supply only an external origin. Previous clicks cannot be reconstructed from the older hostname-only records. Query-only WordPress URLs (such as `?p=123`) remain unavailable rather than being mislabeled as the homepage.

The collector still makes one insert (plus a cold configuration read), with no post lookup, HTTP request, JavaScript or beacon. Reports show at most 20 page rows; post title resolution happens only for those rows in the admin. The worker caps distinct pages at 100 per link per UTC day across batches. More pages aggregate into Other pages. Finite event/report retention and the existing storage health limits also apply to page URLs.

Schema 2 adds the event page field and expands the aggregate value field. Migration runs only for already-initialized analytics from admin or maintenance, under its existing control lock. It preserves the original collection generation, history, settings and paused/active state. Schema-1 in-flight appends remain compatible during migration. The report includes historical clicks under Page unavailable, without fabricating URLs. CSV exports include the new page dimension after collection begins.
