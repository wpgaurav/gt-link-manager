# Analytics controls and two-site deployment verification

Candidate: 1.9.0-rc.10, installed on gauravtiwari.org and gatilab.com under the user's test-deployment authorization. No public release/tag was created; the WordPress.org stable tag remains 1.8.1.

## Changes

- Clicked from supports 10, 20, 50 or 100 rows per page. Resizing returns to the first page and retains filters; pagination is centered and wraps on smaller screens. Active collection has a light green badge, while inactive states remain neutral.
- Individual click records accept positive whole days without a product cap. The default remains 7. An accessible warning appears only above 60 days; it does not prevent saving. Cutoff calculations safely handle very long periods. Aggregate report retention and existing storage-health safeguards remain separate.
- Main Settings diagnostics displays current estimated data and index bytes across all tables owned by this site's GTLM prefix, plus the enabled/disabled states of advanced analytics and advanced redirects. The database metadata read is restricted to Settings and does not initialize analytics. The existing runtime diagnostic now uses HEAD rather than creating a tracked GET request.

## Verification

The exact ZIP passed the analytics integration, timezone, failure, regression, page, drilldown, chart, pagination and retention/diagnostics suites. New tests cover full result reconciliation at different page sizes, retention beyond 30 days, expiry boundaries, integer-overflow safety, owned-table prefix isolation, analytics tables after opt-in, no analytics footprint from diagnostics before opt-in, current feature states, and HEAD diagnostics. PHP coding standards, JavaScript syntax, source/ZIP parity and clipboard regression checks passed.

Browser checks verified switching result sizes and resetting page navigation, the centered controls, green badge colors, and no horizontal overflow at 390px. A 90-day retention value was saved and read back from the disposable fixture. The warning was hidden at 60 and visible at 61; diagnostics were visually reviewed with both features enabled.

Both live sites were backed up before updating. Installed hashes match the tested ZIP. Live read-back confirmed catalog, category records, legacy counts, saved settings and analytics preferences were preserved; uncapped retention, warning state, metadata size and feature-state rendering matched the site configuration. Gatilab's setup prompt uses its own site URL. Analytics was disabled at the initial Gatilab deployment, then enabled during the user's subsequent settings session; the final update preserves that enabled state and its selected retention settings.

Three existing Gatilab redirects returned their configured status and destination when tested directly at the origin. Some public edge responses differed from stored destinations/statuses; those route behaviors were not modified. The source/data verification and origin checks distinguish plugin behavior from the public edge responses.

Private operational evidence is in ignored build-release/qa. Wider hosting/version/capacity testing remains a stable-release gate.

Suite checks: integration: 63, timezones: 7, failures: 12, regressions: 33, pages: 26, drilldown: 24, chart: 4, pagination: 25, retention-diagnostics: 17.

ZIP SHA-256: `930c7f9318ad0c4a0577ec35009fd20b53cf5327115dd0599da6755c14e88311`.
