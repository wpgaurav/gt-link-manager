=== GT Link Manager ===
Contributors: gauravtiwari
Tags: links, redirects, affiliate links, pretty links, marketing
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, lightweight Pretty Links alternative with custom tables, early redirects, CSV import/export, and block editor integration.

== Description ==

GT Link Manager helps you create branded short links on your WordPress site without CPT overhead.

Key features:

- Direct table lookup for redirect slugs
- Early redirect execution on `init`
- 301/302/307 redirect support
- `rel` controls: `nofollow`, `sponsored`, `ugc`
- Noindex header support (`X-Robots-Tag`)
- Category and tag organization
- Full admin list table with search, filters, sorting, bulk actions
- Quick Edit without page reload
- CSV import/export with LinkCentral-compatible preset
- Block editor toolbar button to search links and insert them quickly
- Extensible actions and filters for developers

== Source Code ==

The block editor assets (blocks/link-inserter/build/) are compiled from the source at blocks/link-inserter/src/ using @wordpress/scripts. The full source code is available in this plugin and on GitHub at https://github.com/wpgaurav/gt-link-manager.

To build from source:
`cd blocks/link-inserter && npm install && npm run build`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **GT Links** in wp-admin.
4. Create your first link and test it using your prefix (default: `/go/slug`).

== Frequently Asked Questions ==

= Is this a Pretty Links replacement? =

Yes. The focus is speed and simplicity for branded redirects.

= Does it track clicks? =

Not in core yet. Use the `gt_link_manager_before_redirect` action to hook your own tracking.

= Can I import from LinkCentral? =

Yes. Use **GT Links -> Import / Export**, choose the LinkCentral preset, preview, map columns, and import.

= How are redirects resolved? =

The plugin checks request URI early and loads the matching slug from a unique indexed column in a custom table.

== Screenshots ==

1. All Links admin list with filters and bulk actions
2. Add/Edit Link form with branded URL preview
3. Categories manager
4. Import/Export with preview and mapping
5. Settings with diagnostics

== Changelog ==

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

= 0.1.0 =
* Initial release
* Custom DB schema with links and categories
* Fast redirect handler with cache invalidation
* Admin CRUD for links/categories/settings
* Block editor link inserter format button
* REST API endpoint for editor search
* CSV import/export with preview, mapping, and duplicate handling
* LinkCentral-compatible CSV preset

== Upgrade Notice ==

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

= 1.1.5 =
Maintenance release.

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

= 0.1.0 =
Initial public release.
