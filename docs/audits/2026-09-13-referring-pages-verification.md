# Referring-page attribution verification

Implemented in 1.9.0-rc.3 and installed for the existing authorized test on gauravtiwari.org. The Clicked from table shows referring URLs and published, unprotected WordPress post titles, with click totals. External sites and unresolved posts use their supplied URLs. Missing referrers, rejected URLs and historical clicks remain unavailable. Query-only post identities are not misreported as the homepage.

## Collection and privacy

No visitor script, cookie, beacon, external HTTP request or per-click post lookup was added. The cold collector remains within one configuration read plus one prepared insert. Referrer URLs omit credentials, query strings and fragments; IP hosts, protected WordPress paths, invalid values and oversized pages are rejected. International path bytes are safely percent-encoded before PHP URL parsing. URL storage is capped at 1,024 ASCII bytes and aggregate cardinality at 100 pages per link per UTC day.

The report adds one bounded aggregate query, returns the leading 20 rows and resolves local titles only in the admin table. Referring links open in a new tab with noopener/noreferrer. WordPress privacy guidance and the plugin documentation describe the additional page field.

## Upgrade and tests

Schema 2 adds the page column and expands the aggregate value width. It upgrades only previously initialized analytics, under the existing lifecycle lock, from admin or maintenance. Existing generation, consent, timestamps, settings and report data are preserved; old in-flight appends remain compatible. Failed migrations retain the old schema marker, and retry is safe.

The exact ZIP passed the integration, timezone, failure, security/settings regression and referring-page suites (63, 7, 12, 33 and 26 checks respectively) on the isolated WordPress 7.1 / PHP 8.5.10 / MariaDB fixture. The new suite exercises schema-1 migration and failure/retry, active and paused state preservation, absent opt-in, URL normalization, encoded-path database roundtrip, query budget, page cardinality across batches, link filtering, historical unknowns, CSV data and published/private title handling. PHP syntax, coding standards and package/source checks passed. Browser QA verified post/URL rendering and no document overflow at 390px. The broader remote compatibility/capacity matrix remains a stable-release gate.

## Live proof

The RC2 installation, its settings and analytics tables were backed up before replacement. Installed RC3 files matched the tested ZIP; migration preserved the live settings, generation, start time and catalog.

One controlled synthetic short-link request used the published Bing Webmaster Tools article as its referrer. It returned 302 without cookies, stored one event with the clean referring URL, and produced the matching post title and one-click row in the authenticated live report. This was a controlled verification request, not a claim of organic traffic from that article. The original user's link-filtered analytics tab was also refreshed and the new table was verified there.

The temporary link and its event/aggregate rows were deleted afterward. Read-back checks confirmed the original catalog, existing counters, enabled collection and maintenance schedule were preserved.

ZIP SHA-256: `a54930edc48726b40677901d2cada4cdd273e8640e35ab2eaca230ece3ec5e19`. Private operational evidence remains in the ignored build-release/qa directory and is excluded from distribution.
