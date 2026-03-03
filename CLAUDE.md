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
Push a `v*` tag to trigger `.github/workflows/release.yml` — builds assets, creates zip, publishes GitHub Release.

## Architecture

### Bootstrap flow
`gt-link-manager.php` → `plugins_loaded` → `gt_link_manager_bootstrap()` which:
1. Runs `GT_Link_Activator::maybe_upgrade()` on admin (DB migrations via `dbDelta`)
2. Instantiates `GT_Link_Settings` (singleton), `GT_Link_DB`
3. Initializes services: `GT_Link_Redirect`, `GT_Link_Admin`, `GT_Link_REST_API`, `GT_Link_Block_Editor`

All service classes use a static `init()` factory that takes dependencies, constructs privately, and registers hooks.

### Key classes

| Class | Role |
|-------|------|
| `GT_Link_DB` | Data access layer. All SQL lives here. Object cache per-slug with `gt_links` cache group. |
| `GT_Link_Redirect` | Early redirect on `init` priority 0. Parses REQUEST_URI, looks up slug, sends Location header + exit. |
| `GT_Link_Settings` | Singleton. Reads/writes `gt_link_manager_settings` option. Exposes `prefix()`. |
| `GT_Link_Admin` | Admin menu pages, form handlers, AJAX quick edit. Renders all admin UI inline (no template files). |
| `GT_Link_REST_API` | REST endpoints under `gt-link-manager/v1`. CRUD for links and categories. |
| `GT_Link_Block_Editor` | Enqueues block editor script. Localizes `gtLinkManagerEditor` with REST path and prefix. |
| `GT_Link_Import` | Two-step CSV import (preview → map columns → run) and filtered CSV export. |
| `GT_Link_List_Table` | Extends `WP_List_Table`. Supports views: All, Active, Inactive, Trash. |
| `GT_Link_Activator` | Creates tables via `dbDelta`. Runs `maybe_upgrade()` to migrate schema on version bump. |

### Database tables

**`{prefix}_gt_links`**: id, name, slug (UNIQUE), url, redirect_type, rel, noindex, is_active, category_id, tags, notes, trashed_at, created_at, updated_at

**`{prefix}_gt_link_categories`**: id, name, slug (UNIQUE), description, parent_id, count

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

Permission: `edit_posts` capability, filterable via `gt_link_manager_capabilities`.

### Block editor integration
The link inserter registers a RichText format type (`gt-link-manager/link-inserter`) that adds a toolbar button. Clicking it opens a Popover that searches links via the REST API and inserts them as `core/link` formats. Source uses `createElement` (aliased as `h`), not JSX.

### Developer hooks

| Hook | Type | Purpose |
|------|------|---------|
| `gt_link_manager_before_redirect` | action | Click tracking / logging |
| `gt_link_manager_after_save` | action | Post-save processing |
| `gt_link_manager_after_delete` | action | Post-delete cleanup |
| `gt_link_manager_redirect_url` | filter | Modify target URL |
| `gt_link_manager_redirect_code` | filter | Modify HTTP status code |
| `gt_link_manager_rel_attributes` | filter | Modify rel values |
| `gt_link_manager_headers` | filter | Modify redirect headers |
| `gt_link_manager_settings` | filter | Override settings |
| `gt_link_manager_prefix` | filter | Override URL prefix |
| `gt_link_manager_capabilities` | filter | Override required capability |
| `gt_link_manager_cache_ttl` | filter | Set object cache TTL |

## Conventions

- PHP 8.0+, WordPress 6.4+
- Tabs for indentation in PHP
- All admin UI is rendered inline in PHP (no separate template files)
- Soft delete: `trashed_at` column (NULL = not trashed). Hard delete requires explicit action.
- All SQL in `GT_Link_DB` — other classes call DB methods, never write SQL directly
- Version is maintained in two places: plugin header and `GT_LINK_MANAGER_VERSION` constant in `gt-link-manager.php`
- DB migrations run automatically on admin load when `gt_link_manager_db_version` option < plugin version
