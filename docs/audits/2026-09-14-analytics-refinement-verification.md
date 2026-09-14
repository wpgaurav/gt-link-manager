# Analytics refinement verification

Candidate: 1.9.0-rc.4. Installed on gauravtiwari.org under the existing test authorization. No public release or review submission was performed.

## Requested behavior

- Referring-page links open scoped analytics with clicked links, trends and page-specific breakdowns. Clicking a link keeps the source-page filter. Date/category controls, native WordPress breakdown tabs and CSV exports preserve the selection. Open page remains available separately. Encoded URLs survive navigation without being decoded into a different URL.
- Analytics and general settings are visible sections, with no settings accordions. Text fields have explicit types, labels, 44px minimum height, stronger boundaries and visible keyboard-focus styling. Campaign controls remain native fields.
- Forever report retention is available without changing the existing site choice. It disables time-based summary deletion; raw events retain their separate expiry, and storage safety limits remain. Historical queries use bounded windows of up to 90 days.
- All Links is the list heading. Lifetime counts link to corresponding analytics when collection is opted in and the current user can view reports. When basic counting is off, authorized users get View analytics instead of an invented lifetime total. Geo-off rows have a muted N/A badge.
- The plugin footer adds a WordPress.org review link scoped to GTLM screens. The five-star review listing was verified through the plugin directory's own rating link: https://wordpress.org/support/plugin/gt-link-manager/reviews/?filter=5. The new-post fragment points toward the review form; submission remains the user's action.

## Data correctness and retention

Schema 3 extends the existing two-table design with page cohort keys and an idempotent event processing flag. Global totals stay separate from page-specific summaries. Migration preserves the original generation and history. Retained eligible events from the current generation backfill page breakdowns transactionally within the maintenance time budget. Events skipped by aggregation cannot enter page cohorts; generation changes wait for unfinished current-generation page processing. Missing historical detail is explicitly labelled while earlier page totals and trends remain available. No frontend script, cookie, request, or extra collector query was added.

The exact ZIP passed integration, timezone, failure, security/settings, referring-page and drilldown suites: 63, 7, 12, 33, 26 and 21 checks. New checks cover schema-2 migration/backfill, page-plus-link isolation, discarded generations, historical gaps, filtered export isolation, unsafe filter input, Forever versus raw expiry, older bounded ranges, URL encoding, native tabs, visible settings, count-link permissions and N/A badges. PHP syntax, coding standards, package-source matching and browser checks passed.

Browser QA verified the page-to-link flow, page-scoped country/device results, labelled 44px inputs, persisted Forever in the disposable fixture, no settings disclosures, and no document overflow at 390px. Live read-back confirmed Forever is available while the existing 90-day choice remains selected.

## Live verification

The prior RC3 plugin, settings and analytics tables were backed up before installation. The installed RC4 files match the tested ZIP. Migration and backfill completed; settings, retention choices, collection generation, original catalog and existing counters were preserved. A real referring-page report's total matched its existing page summaries, its link totals reconciled, and its country breakdown matched the retained click records. This live pass used existing records and did not insert synthetic traffic.

Private operational evidence is stored under ignored build-release/qa, excluded from the plugin package. Wider hosting/version/capacity testing remains a stable-release gate.

ZIP SHA-256: `09a8872c9c59b800b1427f97347317ea092fa9c82367349a68b60f33ce8c6071`.
