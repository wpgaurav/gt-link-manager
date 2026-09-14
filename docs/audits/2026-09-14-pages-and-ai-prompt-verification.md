# Separate referring pages and AI setup prompt verification

Candidate: 1.9.0-rc.8. Test update installed on gauravtiwari.org under the existing authorization. Stable tag remains 1.8.1.

## Behavior

Clicked from has its own analytics view (`view=pages`), with the complete matching entry count, Previous/Next controls and a page-number jump. Each request renders at most 20 entries. Date, category and selected-link filters are preserved. Applying filters resets pagination; out-of-range requests clamp before computing an offset. Referring-page links open scoped analytics, and All referring pages returns to the listing. Unknown and pre-attribution history form one entry. External referrers remain available with independent help buttons.

Overview no longer embeds the truncated referring-page table or runs its queries. The listing skips chart and device-breakdown queries. Its count and rows are read in a consistent read-only snapshot. The summary API preserves the pages field and adds pages_pagination, controlled by sources_page. CSV exports retain their full-selection behavior. No collector, visitor script, schema or analytics setting changed.

Main Settings has a labelled, readonly setup prompt next to the AI/REST API guide. It includes the current site's URL, asks the AI to read the guide, reuse supplied configuration and request only missing details, then verify access with read-only requests. The Copy setup prompt action uses existing clipboard handling, reports success accessibly and selects the text for manual copying on failure. No credentials are embedded in the prompt.

## Checks

The exact ZIP passed the existing integration, timezone, failure, regression, page, drilldown and chart suites plus the new pagination suite. The pagination fixture spans several result pages and verifies complete unique rows, reconciled click totals, category isolation, malformed input, empty results, separate-view navigation and setup prompt content. Clipboard tests execute the production handler with mocked browser APIs and cover modern success, fallback success and manual-copy failure. PHP coding standards, JavaScript syntax, diff whitespace and source/ZIP parity passed.

Local browser checks verified all result pages, page drilldown and return, the setup field's accessible name, desktop rendering and no document overflow at 390px. Live browser checks reached every results page and confirmed the separate report has no chart and tooltip buttons are outside links.

The live plugin and data were backed up before installation. Installed file hashes match the tested package. A consistent-snapshot traversal of all live pages matched independently grouped stored referrers, contained no duplicates and reconciled to the report total. Live checks confirmed catalog, basic counts, analytics preferences and generation were preserved, collection remains enabled, WordPress time is +05:30, and external referrers remain. The setup prompt contains the correct live site URL and guide.

Private evidence remains in ignored build-release/qa. Wider hosting/version/capacity testing remains a stable-release gate.

Suite checks: integration: 63, timezones: 7, failures: 12, regressions: 33, pages: 26, drilldown: 24, chart: 4, pagination: 20.

ZIP SHA-256: `fc70abe35315a9c805071a3219ecee3940627dbe134a615a1c66622860f1602c`.
