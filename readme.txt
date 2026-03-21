=== GT Link Manager ===
Contributors: gauravtiwari
Tags: links, redirects, affiliate links, pretty links, marketing
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, lightweight Pretty Links alternative with custom database tables, early redirects, CSV import/export, and block editor integration.

== Description ==

**GT Link Manager** is a high-performance branded link manager for WordPress. It stores links in **custom database tables** (not custom post types), resolves redirects early on `init`, and keeps your site fast — even with thousands of links.

Your links follow a clean URL pattern: **yoursite.com/go/your-slug** (the prefix is configurable).

**[Official Page & Documentation](https://gauravtiwari.org/product/gt-link-manager/)**

= Why GT Link Manager? =

Most link management plugins use custom post types, which means every redirect loads the full WordPress template stack. GT Link Manager takes a different approach — it intercepts the request early, looks up the slug in a **UNIQUE-indexed database column**, sends the redirect header, and exits. No theme loading, no unnecessary queries.

= Key Features =

* **Fast direct redirects** — resolves links on `init` (priority 0) via direct DB lookup, no CPT overhead
* **301, 302, and 307 redirects** — choose the right redirect type for SEO, temporary, or method-preserving redirects
* **Rel attribute controls** — set `nofollow`, `sponsored`, and `ugc` per link for proper SEO attribution
* **Noindex support** — sends `X-Robots-Tag: noindex` header to prevent search engines from indexing redirect URLs
* **Categories and tags** — organize links into categories with parent/child hierarchy and free-form tags
* **Full admin list table** — search, filter by category/status, sort by any column, and perform bulk actions
* **Quick Edit** — update URL, slug, redirect type, rel, category, and status inline without leaving the list
* **Activate / Deactivate** — disable a link without deleting it; inactive links stop redirecting but stay in the database
* **Trash and restore** — soft-delete links to trash with the option to restore or permanently delete
* **CSV import and export** — import links from CSV with column mapping preview, or export filtered links; includes **LinkCentral** and **Pretty Links** compatible presets
* **Block editor integration** — a toolbar button lets you search your links and insert them directly into post content
* **Branded URL preview** — see the full branded URL as you type, with one-click copy
* **Click stats** — can be activated to track link clicks
* **Developer-friendly** — actions and filters for redirect interception, URL modification, capability control, cache TTL, and more

= Developer Hooks =

GT Link Manager provides a comprehensive set of hooks for customization:

* `gtlm_before_redirect` — action fired before redirect (use for click tracking or logging)
* `gtlm_redirect_url` — filter to modify the destination URL
* `gtlm_redirect_code` — filter to modify the HTTP status code
* `gtlm_rel_attributes` — filter to modify rel attribute values
* `gtlm_headers` — filter to modify redirect response headers
* `gtlm_prefix` — filter to override the URL prefix
* `gtlm_capabilities` — filter to override the required user capability
* `gtlm_cache_ttl` — filter to set object cache TTL for link lookups

== Source Code ==

The block editor assets (blocks/link-inserter/build/) are compiled from the source at blocks/link-inserter/src/ using @wordpress/scripts. The full source code is available in this plugin and on GitHub at https://github.com/wpgaurav/gt-link-manager.

To build from source:
`cd blocks/link-inserter && npm install && npm run build`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **GT Links** in your wp-admin sidebar.
4. Create your first link and test it using your prefix (default: **yoursite.com/go/your-slug**).

You can change the prefix from **GT Links > Settings** at any time.

== Frequently Asked Questions ==

= Is this a Pretty Links replacement? =

Yes. GT Link Manager is built for **speed and simplicity**. It uses custom database tables instead of custom post types, which means redirects resolve faster and don't pollute your posts table.

= Does it track clicks? =

Click stats can be activated from settings. You can also use the `gtlm_before_redirect` action hook to integrate your own tracking or analytics.

= Can I import from Pretty Links or LinkCentral? =

Yes. Go to **GT Links > Import / Export**, choose the **Pretty Links** or **LinkCentral** preset, upload your CSV, preview the column mapping, and import. You can also use the **Generic** preset for custom CSV formats.

= How are redirects resolved? =

The plugin hooks into WordPress `init` at **priority 0** (before most plugins load). It parses the request URI, checks for your configured prefix, and looks up the slug in a **UNIQUE-indexed column** in a custom database table. If a match is found, it sends the redirect header and exits immediately — no theme or template loading.

= Can I customize which users can manage links? =

Yes. By default, any user with the `edit_posts` capability can manage links. Use the `gtlm_capabilities` filter to change this per context (e.g., require `manage_options` for settings but allow `edit_posts` for link creation).

= What happens when I uninstall? =

Uninstalling the plugin (deleting it from **Plugins**) will **remove all data** — both database tables and plugin options. Deactivating the plugin preserves all data.

== Screenshots ==

1. **All Links** — admin list with search, filters, status views, and bulk actions
2. **Add/Edit Link** — form with branded URL preview, redirect type, rel attributes, and categories
3. **Categories** — manage link categories with parent/child hierarchy
4. **Settings** — configure prefix, defaults, flush permalinks, and run diagnostics
5. **Import/Export** — CSV import with column mapping preview and preset support

== Changelog ==

= 1.5.1 =
* Fixed CSS custom properties not resolving on some admin pages — all values are now hardcoded.
* Fixed form-table double-card styling when rendered inside a card container.
* Improved edit form submit buttons layout — buttons now display in a single horizontal row.
* Improved branded URL preview styling with distinct blue tint.
* Added subtle row separators inside card form tables.
* Added WordPress.org SVN deploy to release workflow.
* Added plugin banner and icon assets for WordPress.org listing.

= 1.4.0 =
* Renamed internal code prefix from `gt_` to `gtlm_` (4+ characters) per WordPress.org guidelines.
* Fixed nonce verification order in CSV import handler — nonce is now checked before reading POST data.
* Fixed SQL injection vector — `$orderby` now uses `%i` identifier placeholder in prepared queries.
* Added Source Code section to readme for compiled block editor assets.
* Added card-based UI styling to all admin pages (edit link, categories, settings, import/export).
* Settings page reorganized into General, Tools, and Diagnostics cards.
* Clean uninstall now removes both old (`gt_`) and new (`gtlm_`) prefix options and tables.

= 1.3.1 =
* Block editor: aligned GT Link popover anchoring with core rich text behavior using selection-based anchor.

= 1.3.0 =
* Refactored admin into separate actions and rendering classes for maintainability.
* Added Pretty Links CSV import preset alongside Generic and LinkCentral.
* Improved input sanitization on redirect URI parsing.
* Added PHPCS configuration and Composer dev tooling.
* Added uninstall.php for clean plugin removal (drops tables and options).
* Improved build.sh with .distignore support and critical file verification.
* Improved release workflow with version verification, checksums, and distribution validation.
* Block editor popover anchor now uses bounding rect snapshot for reliable positioning.
* Button primary color now follows WordPress admin theme color.
* Lowered PHP requirement from 8.2 to 8.0.
* Tested up to WordPress 6.9.

= 1.2.3 =
* Fixed release packaging bug that accidentally removed `blocks/link-inserter/build/*` from zip assets.
* GitHub release workflow now compiles block editor assets before zipping and verifies build files exist.

= 1.2.2 =
* Fixed block editor link inserter not appearing on WordPress 6.8+.
* Removed deprecated useAnchor hook that crashed the toolbar button on render.
* Eliminated react-jsx-runtime dependency that prevented the script from loading on some WordPress versions.
* Popover now anchors to the toolbar button for reliable positioning.

= 1.2.0 =
* Fixed critical bug: links disappeared after 1.1.9 update because new DB columns were not added on plugin update (only on fresh activation).
* Added automatic DB migration that runs on update to add missing columns and backfill existing rows.
* Fixed WordPress admin sidebar menu getting unintended card styles on the Settings page.
* Improved Settings page: Flush Permalinks and Run Diagnostics buttons are now inline, diagnostics output uses a clean table layout with status badges.

= 1.1.9 =
* Added link activate/deactivate toggle. Inactive links stop redirecting but remain in the database.
* Delete now moves links to trash instead of permanent deletion. Links can be restored from trash.
* Trash view with restore and permanent delete actions.
* New bulk actions: Activate, Deactivate, Move to Trash, Restore, Delete Permanently.
* REST API: DELETE defaults to trash (use `?force=true` for permanent). New `/restore` and `/toggle-active` endpoints.
* Status column and views (All / Active / Inactive / Trash) in the links list table.
* New `is_active` and `trashed_at` columns added to the links table on upgrade.

= 1.1.8 =
* Maintenance release.

= 1.1.7 =
* Block editor: Fixed editor scroll jump when opening GT Link popover from the toolbar.
* Block editor: Improved search input focus behavior so opening popover does not move viewport.

= 1.1.6 =
* REST API: Added full pagination (page, per_page, category_id, orderby, order) to GET /links endpoint.
* REST API: Added args schema validation to all write endpoints (links, categories, bulk-category).
* Security: Replaced innerHTML with DOM methods in admin quick edit to prevent XSS.
* DB: Added rel whitelist validation on filter queries.
* Build: build.sh now compiles block editor assets before packaging.

= 1.1.5 =
* Maintenance release.

= 1.1.4 =
* Anchor popover to selected text using useAnchor from @wordpress/rich-text.

= 1.1.3 =
* Fixed format registration conflict with core/underline on WP 6.9+ (both used bare span tag).
* Added unique className to avoid tagName collision.

= 1.1.2 =
* Switch to RichTextToolbarButton for standard format toolbar integration.

= 1.1.1 =
* Force-inject format into RichText allowedFormats for reliable toolbar display.

= 1.1.0 =
* Rebuilt block editor link inserter with @wordpress/scripts build pipeline.
* Fixed toolbar button not appearing in block editor.
* Proper dependency resolution via index.asset.php.

= 1.0.4 =
* Fixed toolbar button registration in block editor for GT Link inserter.
* Added selected-text autofill in GT Link inserter search field.
* Improved redirect detection for WordPress installs in subdirectories.

= 1.0.3 =
* Enhance block editor integration with additional dependencies and improved format registration

= 1.0.2 =
* Minor internal hardening and cleanup.

= 1.0.1 =
* Fixed uninstall to preserve links and settings data across reinstalls.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.1 =
Fixes admin CSS rendering issues (double card borders, stacked buttons) and adds WordPress.org plugin assets.

= 1.4.0 =
Renamed internal prefix to `gtlm_` for wp.org compliance, fixed nonce and SQL safety issues, added card UI to admin pages.

= 1.3.1 =
Fixes GT Link toolbar popover alignment in the block editor to match core behavior.

= 1.3.0 =
Admin refactor, Pretty Links import preset, improved sanitization, build tooling, and PHP 8.0 support.

= 1.2.3 =
Fixes release packaging so the GT Link block editor toolbar assets are included in update zips.

= 1.2.2 =
Fixes block editor GT Link toolbar button not showing on WordPress 6.8+.

= 1.2.0 =
Critical fix: restores links that disappeared after 1.1.9 update. Adds automatic DB migration on update.

= 1.1.9 =
Links can now be activated/deactivated and deleted links go to trash first with restore support.

= 1.1.7 =
Fixes editor scroll jump when opening GT Link popover from the toolbar.

= 1.1.6 =
Full REST API pagination, args validation on all write endpoints, XSS fix in admin quick edit.

= 1.1.4 =
Positions link search popover near selected text instead of top-left corner.

= 1.1.3 =
Fixes format not registering on WP 6.9+ due to tagName conflict with core/underline.

= 1.1.2 =
Uses standard RichTextToolbarButton for reliable format toolbar placement.

= 1.1.1 =
Ensures GT Link toolbar button appears on all RichText instances.

= 1.1.0 =
Rebuilt block editor link inserter with proper WordPress scripts build. Fixes toolbar button not showing.

= 1.0.4 =
Improves block editor toolbar behavior and redirect reliability.

= 1.0.0 =
Initial release.
