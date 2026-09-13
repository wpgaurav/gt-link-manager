# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

GT Link Manager is a WordPress plugin for branded short links (Pretty Links alternative). It uses custom database tables (not CPTs) for fast redirect resolution. Links are resolved early on `init` via direct DB lookup on a UNIQUE-indexed slug column.

Default URL pattern: `/{prefix}/{slug}` where prefix defaults to `go` (e.g., `/go/my-link`).

## Build & Development

### Block editor assets (link inserter toolbar button)
```bash
cd blocks/link-inserter && npm install && npm run build
cd blocks/link-inserter && npm run start  # watch mode
```

Uses `@wordpress/scripts` — source is `blocks/link-inserter/src/index.js`, output goes to `blocks/link-inserter/build/`.

### Build release zip
```bash
./build.sh
```
Compiles block assets, copies plugin files to `/tmp`, strips source/dev artifacts, produces `gt-link-manager-{version}.zip`.

### Releasing
Publishing a GitHub release triggers `.github/workflows/release.yml`, which builds artifacts and then deploys to WordPress.org. A test build is not authorization to publish a tag/release or deploy to WordPress.org.

## Architecture

### Bootstrap flow
`gt-link-manager.php` → `plugins_loaded` → `gtlm_bootstrap()` which:
1. Runs `GTLM_Activator::maybe_upgrade()` on admin (DB migrations via `dbDelta`)
2. Instantiates `GTLM_Settings` (singleton), `GTLM_DB`
3. Initializes the redirect service immediately; admin/editor classes load only for admin requests and REST classes load on `rest_api_init`. Analytics collection/storage remain gated by persisted explicit opt-in.

All service classes use a static `init()` factory that takes dependencies, constructs privately, and registers hooks.

### Key classes

| Class | Role |
|-------|------|
| `GTLM_DB` | Data access layer. Core link SQL lives here; the optional GTLM_Analytics_DB subclass owns analytics schema, aggregation and reporting. Object cache per-slug with `gtlm_links` cache group. |
| `GTLM_Redirect` | Early redirect on `init` priority 0. Parses REQUEST_URI, looks up slug, sends Location header + exit. |
| `GTLM_Geo` | Country detection and geo rule resolution. Header-based only — reads `$_SERVER` keys the CDN/web server already set (no DB file, no HTTP call). Memoizes detection and its settings-derived config per request. Owns rule validation (`normalize_rules`/`encode_rules`/`decode_rules`) shared by REST, admin, and CSV. |
| `GTLM_Settings` | Singleton. Reads/writes `gtlm_settings` option. Exposes `prefix()`. |
| `GTLM_Admin` | Admin menu pages, form handlers, AJAX quick edit. Renders all admin UI inline (no template files). |
| `GTLM_REST_API` | REST endpoints under `gt-link-manager/v1`. CRUD for links and categories. |
| `GTLM_Block_Editor` | Enqueues block editor script. Localizes `gtLinkManagerEditor` with REST path and prefix. |
| `GTLM_Import` | Two-step CSV import (preview → map columns → run) and filtered CSV export. |
| `GTLM_List_Table` | Extends `WP_List_Table`. Supports views: All, Active, Inactive, Trash. |
| `GTLM_Activator` | Creates tables via `dbDelta`. Runs `maybe_upgrade()` to migrate schema on version bump. |

### Database tables

**`{prefix}_gtlm_links`**: id, name, slug (UNIQUE), url, redirect_type, rel, noindex, is_active, link_mode, regex_replacement, priority, geo_mode, geo_rules, category_id, tags, notes, trashed_at, created_at, updated_at

`geo_rules` holds JSON (`{version, rules[{countries, url, redirect_type}], fallback}`) and is deliberately **not** decoded in `normalize_link_row()` — decoding on every link read would cost the redirect hot path and list tables for a field only geo links use. `GTLM_Geo` decodes lazily; REST decodes explicitly for responses.

Insert/update placeholder arrays are derived from the payload via `GTLM_DB::COLUMN_FORMATS` + `formats_for()`. Do not reintroduce hardcoded positional `$format` arrays — they silently corrupt writes when a column is added.

`total_clicks` is a counter, not part of a link's configuration. It is written only by `increment_clicks()` (a single atomic `UPDATE ... SET total_clicks = total_clicks + 1`, never read-modify-write) and `reset_clicks()`. It is exposed read-only in REST and CSV export, and is deliberately absent from the REST write args so it cannot be set by a client. It *is* in `COLUMN_FORMATS` because bulk actions round-trip whole rows through `update_link( array_merge( $link, ... ) )`.

Adding a column means updating **three** places, not one: the `CREATE TABLE` in `GTLM_Activator`, the `LINK_COLUMNS` select list, and `$allowed_orderby` in `list_links()` if it should be sortable. Missing the second silently returns 0/empty; missing the third silently falls back to sorting by `id`.

**`{prefix}_gtlm_categories`**: id, name, slug (UNIQUE), description, parent_id, count

### REST API (`gt-link-manager/v1`)

| Endpoint | Methods |
|----------|---------|
| `/links` | GET (paginated, filterable), POST |
| `/links/{id}` | GET, PUT/PATCH, DELETE (trash by default, `?force=true` for permanent) |
| `/links/{id}/restore` | PUT/PATCH |
| `/links/{id}/toggle-active` | PUT/PATCH |
| `/links/bulk-category` | POST (move or copy links to category) |
| `/categories` | GET, POST |
| `/categories/{id}` | PUT/PATCH, DELETE |

Permission: `edit_posts` capability, filterable via `gtlm_capabilities`.

**Write args must not declare `default`.** `WP_REST_Request` materialises a schema default into the request, so `get_param()` returns it for an omitted field and `sanitize_link_payload()` can no longer distinguish "not supplied" from "explicitly set" — which made every PATCH reset the fields it omitted. Per-field fallbacks belong in `sanitize_link_payload()` (existing row on update, documented default on create).

### Block editor integration
The link inserter registers a RichText format type (`gt-link-manager/link-inserter`) that adds a toolbar button. Clicking it opens a Popover that searches links via the REST API and inserts them as `core/link` formats. Because `core/button` stores its destination in block attributes rather than an inline format, an `editor.BlockEdit` extension adds the same search control and writes the selected branded URL and relation tokens to the button's native `url` and `rel` attributes. Source uses `createElement` (aliased as `h`), not JSX.

### Developer hooks

| Hook | Type | Purpose |
|------|------|---------|
| `gtlm_before_redirect` | action | Click tracking / logging |
| `gtlm_after_save` | action | Post-save processing |
| `gtlm_after_delete` | action | Post-delete cleanup |
| `gtlm_redirect_url` | filter | Modify target URL |
| `gtlm_redirect_code` | filter | Modify HTTP status code |
| `gtlm_rel_attributes` | filter | Modify rel values |
| `gtlm_headers` | filter | Modify redirect headers |
| `gtlm_settings` | filter | Override settings |
| `gtlm_prefix` | filter | Override URL prefix |
| `gtlm_capabilities` | filter | Override required capability |
| `gtlm_cache_ttl` | filter | Set object cache TTL |
| `gtlm_geo_country` | filter | Override the detected country (any custom detection method) |
| `gtlm_geo_sources` | filter | Add/remove `$_SERVER` keys probed for a country |
| `gtlm_geo_country_groups` | filter | Define country groups usable in rules (ships with `EU`) |
| `gtlm_geo_matched_rule` | filter | Override the resolved geo rule; return `null` to block |
| `gtlm_geo_blocked` | action | Fires when a geo 404 fallback blocks a request |
| `gtlm_link_not_found` | action | Fires when a prefixed link cannot be resolved |
| `gtlm_404_on_missing_link` | filter | Return false to fall through to WP instead of 404ing an unresolved prefixed link |
| `gtlm_trash_purged` | action | Fires after the retention cron purges trashed links |
| `gtlm_count_click` | filter | Return false to skip counting a click |
| `gtlm_click_recorded` | action | Fires after a click has been counted |

### Geolocation notes

- Detection is header-only by design: zero dependencies, zero latency, nothing to update. `GTLM_Geo::sources()` lists the probed `$_SERVER` keys in priority order (Cloudflare first).
- Country headers are forgeable unless traffic passes through the CDN that sets them. Behind Cloudflare, `CF-IPCountry` is rewritten at the edge — verified — so the `cloudflare` detection method is the hardened choice. The 404 fallback is not a security control.
- Geo links should use 302. A 301 is cached by the browser and pins a visitor to their first detected country; the editor warns on 301.
- The redirect path resolves the link *first*, then checks `geo_mode` — detection must never run for links that don't opt in.

### Click counting

Opt-in via the `enable_click_tracking` setting, off by default. That default is a privacy commitment, not a UX choice: with it off, the plugin's registered privacy-policy content states that it does not log requests, and that statement has to stay true. `GTLM_Admin::register_privacy_content()` swaps the redirect paragraph based on the setting -- if you change what is recorded, change that text in the same commit.

The write happens in `GTLM_Redirect::record_click()`, called *after* `header( 'Location: ... )`, and calls `fastcgi_finish_request()` first where available so the counter never sits on the visitor's clock. Measured on the synchronous fallback path (no FPM): 9.0ms median vs 9.1ms baseline.

## Deploying to gauravtiwari.org

Production runs on an xCloud-managed server. **The WordPress root is `/var/www/gauravtiwari.org`** — the docroot itself, not a `public_html` or `htdocs` subdirectory. There is no `~/domains`; searching the filesystem for `wp-load.php` finds only backups. The server's own `~/.bashrc` cds there on login.

- SSH with `GA_SSH_COMMAND` from `~/.env`. Values in that file are single-quoted, so `set -a; . ~/.env; set +a` works.
- WP-CLI lives at `/usr/local/bin/wp` and needs `--skip-plugins --skip-themes` on this install.
- Back the plugin up before overwriting, matching the convention already on the box:
  `~/gtlm-backups/gt-link-manager-<version>-<YYYYmmdd-HHMMSS>.tar.gz`
- The live install is large (~1,300 links, a few hundred geo-targeted). Check trashed/inactive counts before shipping any change to redirect resolution, because those are the rows whose HTTP behaviour changes.

## Conventions

- PHP 8.0+, WordPress 6.4+
- Tabs for indentation in PHP
- All admin UI is rendered inline in PHP (no separate template files)
- The links table is wrapped in `.gtlm-table-scroll` and carries a `gtlm-links-table` class. Core ships list tables as `widefat fixed` (`table-layout: fixed`), where per-cell `min-width` is ignored and leftover width is divided until narrow columns collapse to a few pixels and wrap one character per line. This table overrides to `table-layout: auto` with a per-column floor and a table `min-width`, so it scrolls sideways instead. The wrapper needs `clear: both` (core's search box is floated) and `contain: paint` (without it the clipped table still widens the whole admin page).
- Admin CSS defers to the WordPress admin surface: no page-background override, no restyled core inputs or buttons. Accents use `var(--wp-admin-theme-color, #2271b1)` so custom admin colour schemes keep working, and surfaces use core's palette (`#f0f0f1`, `#c3c4c7`, `#1d2327`, `#50575e`).
- Soft delete: `trashed_at` column (NULL = not trashed). Hard delete requires explicit action.
- All SQL in `GTLM_DB` — other classes call DB methods, never write SQL directly
- Version is maintained in two places: plugin header and `GTLM_VERSION` constant in `gt-link-manager.php`
- DB migrations run automatically on admin load when `gtlm_db_version` option < plugin version. Activation records the version itself, so a fresh install does not re-run `dbDelta` on first admin load.
- Bulk actions are handled in `GTLM_Admin::handle_bulk_actions()` on `admin_init`, never in the list table's `prepare_items()`. Acting during render meant no notice and a re-run on refresh. They act, then redirect with a result and an undo payload.
- Reversible actions carry an undo payload through `redirect_with_notice()`; `render_undo_link()` renders it. Permanent delete is never undoable.
- Trashed links are purged by the daily `gtlm_purge_trash` cron using the `trash_retention_days` setting (0 = keep forever). Fresh installs default to 30 days; `maybe_upgrade()` pins **existing** installs to 0, because retroactively applying a new retention policy to a years-old trash is data loss the site owner never agreed to.
- Unresolved links under the configured prefix must return a real 404. The rewrite rule claims the whole prefix namespace, so falling through renders the front page at HTTP 200 -- a soft 404 across every dead link. Only prefix matches 404; direct and regex mode inspect arbitrary paths that may be real pages.
- `rel` input accepts commas, whitespace, or arrays. The plugin emits space-separated rel, so a comma-only parser silently drops values it produced itself.
- Code prefix is `gtlm` (4+ chars) per wp.org plugin directory requirements

### Advanced analytics (1.9.0 candidate)

See `docs/analytics.md` for the consent lifecycle, query budget, WordPress timezone reporting, retention, and validation commands. Analytics is separate from lifetime totals. Internal instants remain UTC, while UI dates, grouping, timestamps and exports use WordPress time. Test builds do not authorize public releases. CSV exports use format 3 with spreadsheet-safe text cells; private staged imports expire through the `gtlm_import_expire` job.
