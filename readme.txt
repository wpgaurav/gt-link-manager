=== GT Link Manager ===
Contributors: gauravtiwari
Tags: links, redirects, affiliate links, pretty links, marketing
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast branded redirects with opt-in click analytics, country targeting, CSV migration, a REST API, and block editor link tools.

== Description ==

**GT Link Manager** is a **free WordPress plugin** for branded short links, affiliate link management, country-based redirects, and opt-in click analytics. Create reusable links, find out where they are clicked, and manage them from the WordPress admin, block editor, or REST API. There are no premium tiers or upsells.

Redirects use indexed custom database tables and resolve before theme templates load. Advanced Analytics adds no visitor tracking scripts, cookies, or beacon requests.

Your links follow a clean URL pattern: **yoursite.com/go/your-slug** (the prefix is configurable and can even be removed on individual links).

**[Official Page & Documentation](https://gauravtiwari.org/product/gt-link-manager/)** | **[Free Training Course](https://gauravtiwari.org/course/gt-link-manager-training/)** | **[REST API & AI Tools Guide](https://gauravtiwari.org/gt-link-manager-rest-api-guide-ai-tools/)**

= Advanced Analytics: See Where Your Links Get Clicked =

Available in the **1.9.0 test candidate**, Advanced Analytics adds reports alongside the existing lifetime click counter. It stays off until you explicitly enable it under **GT Links > Analytics > Settings**. No analytics tables, options, records or maintenance jobs are created before opt-in.

* **Find your strongest links.** View click totals, daily or hourly trends, and the links used within a selected period or category.
* **See the referring post or URL.** The separate **Clicked from** report lists every matching recorded entry with pagination and resolves published WordPress post titles where possible. Open a page report to see which links were clicked there, then inspect an individual link within that same page.
* **Switch breakdowns instantly.** Sources, countries, devices, browsers, operating systems and configured campaigns are loaded with the report. Switching tabs does not reload the page or fetch another report.
* **Read the chart clearly.** The chart fits the recorded activity period while preserving real time spacing. **View click totals** opens a scrollable popup with exact values.
* **Use WordPress time.** Dates, hourly/day grouping and CSV timestamps follow the timezone configured in WordPress, including fractional offsets and daylight-saving changes.
* **Keep the context when exporting.** Date, category, link and referring-page selections carry into CSV exports. Configured UTM combinations can be tracked as campaigns.
* **Choose what to retain.** Individual click records default to seven days and reports to ninety days. Choose **Forever** to keep reports until you delete them; individual records still expire separately.
* **Pause or remove analytics independently.** Turning collection off keeps existing reports under their retention policy. Deleting analytics removes its records and jobs while preserving links and basic counts.

Collection uses a compact server-side write on eligible GET redirects. PHP-FPM can finish the redirect response before recording the event; other hosts complete that write synchronously. No visitor JavaScript, cookies, beacons, IP storage, fingerprinting or external analytics service is added. Referring URLs omit credentials, query strings and fragments; raw user agents are not retained.

Recognized bots, prefetches and signed-in link managers are excluded. These reports count eligible redirect requests, not unique people or conversions. Browsers may report an external website, only its origin, or no referrer. Earlier clicks without page information remain **Page unavailable**, with a short explanation. External referrers are kept and explained rather than automatically treated as spam.

Reports and page-specific breakdowns are summarized by a bounded maintenance worker. Keep WordPress cron or a system scheduler running. Storage and processing safeguards can pause collection while preserving existing reports. Historical data can be viewed in date windows of up to ninety days. Advanced Analytics currently supports single-site installations.

The **Privacy and data** section links to WordPress's suggested Privacy Policy text. Administrators control analytics by default; developers can use `gtlm_analytics_capability` for report access and `gtlm_analytics_should_record` to suppress events, including consent integrations. Site-owner opt-in is not a legal consent exemption.

This is a test build, not a published stable WordPress.org release.

= Why GT Link Manager? =

A branded link is a redirect with a job: it has to resolve fast, survive a thousand siblings, and never become the slowest thing on the page. GT Link Manager stores links in **custom database tables**, not custom post types. It intercepts the request on `init` at priority 0, looks up the slug in a UNIQUE-indexed column, sends the redirect header, and exits.

No theme loading. No template stack. No post query.

Storing links as custom post types means a redirect wakes up more of WordPress than a redirect needs. Custom tables keep the resolution path short, and it stays short whether you have 20 links or 2,000.

= Key Features =

* **Advanced Analytics.** Optional click trends, referring-page drilldowns, instant breakdown tabs, WordPress-time reports and CSV export, with no visitor scripts or cookies.
* **Fast direct redirects.** Resolves links on `init` (priority 0) via direct DB lookup, no CPT overhead
* **301, 302, and 307 redirects.** Choose the right redirect type for SEO, temporary, or method-preserving redirects
* **Rel attribute controls.** Set `nofollow`, `sponsored`, and `ugc` per link for proper SEO attribution
* **Noindex support.** Sends `X-Robots-Tag: noindex` header to prevent search engines from indexing redirect URLs
* **Categories and tags.** Organize links into categories with parent/child hierarchy and free-form tags
* **Full admin list table.** Search, filter by category/status, sort by any column, and perform bulk actions
* **Quick Edit.** Update URL, slug, redirect type, rel, category, and status inline without leaving the list
* **Activate / Deactivate.** Disable a link without deleting it; inactive links stop redirecting but stay in the database
* **Trash and restore.** Soft-delete links to trash with the option to restore or permanently delete
* **CSV import and export.** Import links from CSV with column mapping preview, or export filtered links; includes **LinkCentral** and **Pretty Links** compatible presets. Manual column mapping also supports compatible CSV exports from **ThirstyAffiliates, ClickWhale, Lasso, BetterLinks, GeniusLink**, and others. Map the name and destination URL fields, review the preview, and choose how to handle duplicates.
* **Block editor integration.** Search and insert managed links into rich text and the core Button block without leaving the editor
* **Branded URL preview.** See the full branded URL as you type, with one-click copy
* **Prefix-free and regex redirects.** Route ordinary paths without `/go/`, or use regular expressions and capture groups for more complex rules. Protected WordPress endpoints cannot be claimed by redirect rules.
* **Geolocation targeting.** Send visitors from different countries to different destinations on a per-link basis (e.g. India to amazon.in, the US to amazon.com). The country comes from a header your CDN already sends, whether that is Cloudflare, CloudFront, Vercel, App Engine, or an nginx/Apache GeoIP module, so there is **no GeoIP database to install and no external lookup**. Links without country rules skip country detection.
* **Independent lifetime counts.** Optional running totals remain separate from Advanced Analytics. When analytics is enabled, click a count in All Links to open that link's report.
* **Click tracking integrations.** For per-visit analytics, hook `gtlm_before_redirect` and send events to GA4, Plausible, Fathom, Matomo, or Simple Analytics
* **AI and REST API workflows.** Create, update, categorize, trash and restore links through authenticated endpoints. The settings page links directly to the AI-tools guide.
* **Safer CSV handling.** Preview and map columns before import. Temporary files stay outside public uploads, and spreadsheet-style formulas are neutralized on export.
* **Data cleanup controls.** Restore trashed links, configure trash retention, and choose whether uninstall removes plugin data.
* **Developer-friendly.** Actions and filters for redirect interception, URL modification, capability control, cache TTL, and more

= Developer Hooks =

GT Link Manager provides a comprehensive set of hooks for customization:

* `gtlm_before_redirect`, action fired before redirect (use for click tracking or logging)
* `gtlm_redirect_url`, filter to modify the destination URL
* `gtlm_redirect_code`, filter to modify the HTTP status code
* `gtlm_rel_attributes`, filter to modify rel attribute values
* `gtlm_headers`, filter to modify redirect response headers
* `gtlm_prefix`, filter to override the URL prefix
* `gtlm_capabilities`, filter to override the required user capability
* `gtlm_cache_ttl`, filter to set object cache TTL for link lookups
* `gtlm_geo_country`, filter the detected country code (plug in any detection method you like)
* `gtlm_geo_sources`, filter the list of request variables checked for a country
* `gtlm_geo_country_groups`, filter country groups usable in rules (ships with `EU`)
* `gtlm_geo_matched_rule`, filter the resolved geo rule before the redirect is sent
* `gtlm_geo_blocked`, action fired when a visitor is blocked by a geo rule's 404 fallback
* `gtlm_link_not_found`, action fired when a prefixed link cannot be resolved and a 404 is sent
* `gtlm_404_on_missing_link`, filter to disable the 404 for unresolved prefixed links and fall through to WordPress instead
* `gtlm_trash_purged`, action fired after trashed links are automatically purged, with the count and retention window
* `gtlm_count_click`, filter to skip counting a particular click (exclude logged-in editors, add bot filtering)
* `gtlm_click_recorded`, action fired after a click has been counted
* `gtlm_analytics_should_record`, filter to suppress an advanced analytics event
* `gtlm_analytics_capability`, filter the capability required to view analytics reports

= Click Counting =

Click counting is off when you install the plugin, and that default is deliberate. With basic counts and advanced analytics both off, GT Link Manager stores no request analytics. Its privacy-policy guidance reflects the enabled features.

Turn it on from **GT Links > Settings** and each link starts keeping one number: how many times it has been followed. That is the whole record. What it stores per click:

* nothing about the visitor
* no IP address
* no user agent
* no referrer
* no timestamp

Basic counts are separate from detailed analytics and do not identify visitors. Choose consent requirements according to your site and integrations.

The count uses an atomic update. Where PHP-FPM supports `fastcgi_finish_request()`, the response can finish first; other hosts perform the update synchronously.

Where the number shows up:

* a sortable **Clicks** column in All Links; it opens analytics when enabled and you have report access
* a **Reset Clicks** row action for a single link
* the `total_clicks` field in the REST API, read-only
* a `total_clicks` column in CSV export

Use `gtlm_count_click` to skip clicks you do not want counted, such as your own logged-in visits.

Basic counting answers "which of my links get used." Enable the separate advanced analytics feature for dated reports, referring websites, countries, and device families. For external integrations, the **[Developer Reference](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/)** has step-by-step guides for wiring `gtlm_before_redirect` into GA4, Plausible, Fathom, Matomo, Simple Analytics, or a click log table of your own.

= Free Training Course =

The **[GT Link Manager Training](https://gauravtiwari.org/course/gt-link-manager-training/)** is a free course covering installation through developer integrations. Most lessons run 3 to 5 minutes, so you can read the 1 you need instead of the whole thing.

Straight to the lesson for a specific feature:

* [Getting Started](https://gauravtiwari.org/course/gt-link-manager-training/getting-started-1771422506/getting-started/) covers the admin screens and your first link
* [Managing Links](https://gauravtiwari.org/course/gt-link-manager-training/getting-started-1771422506/managing-links/) covers editing, quick edit, status, and trash
* [Link Categories](https://gauravtiwari.org/course/gt-link-manager-training/getting-started-1771422506/categories/) covers organizing a large library
* [Settings and Configuration](https://gauravtiwari.org/course/gt-link-manager-training/configuration-features/settings-configuration/) covers the prefix, defaults, and advanced modes
* [The Redirect System](https://gauravtiwari.org/course/gt-link-manager-training/configuration-features/redirect-system/) covers 301, 302, 307, and how resolution works
* [Block Editor Integration](https://gauravtiwari.org/course/gt-link-manager-training/configuration-features/block-editor-integration/) covers the GT Link toolbar button and the Button block
* [Import and Export](https://gauravtiwari.org/course/gt-link-manager-training/configuration-features/import-export/) covers the Pretty Links and LinkCentral presets and column mapping
* [Geolocation Targeting](https://gauravtiwari.org/course/gt-link-manager-training/configuration-features/geolocation-targeting/) covers per-country rules and CDN header detection
* [REST API Reference](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/rest-api-reference/) covers every endpoint and its arguments
* [Hooks and Filters](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/hooks-filters/) covers all the extension points
* [Troubleshooting](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/troubleshooting-1771422633/) covers redirects that do not fire and slugs that collide

= Source and Issue Tracker =

GT Link Manager is developed in the open. The full source, including the block editor code that ships compiled in the plugin, is on GitHub:

* [Repository](https://github.com/wpgaurav/gt-link-manager) for the code and release history
* [Issues](https://github.com/wpgaurav/gt-link-manager/issues) for bugs and feature requests
* [Releases](https://github.com/wpgaurav/gt-link-manager/releases) for changelogs and downloadable builds

A bug report with your WordPress version, PHP version, and the slug that misbehaved is usually enough to reproduce it.

= Support =

Support runs through the **[WordPress.org support forum](https://wordpress.org/support/plugin/gt-link-manager/)** for this plugin. That is the fastest route for every kind of problem, and it is the one to use first.

Post there and the answer stays public, so the next person with the same redirect loop finds it without asking again.

What helps a thread get solved on the first reply:

* your WordPress and PHP versions
* the link slug and the prefix you configured
* what you expected the redirect to do, and what it actually did
* whether a CDN or page cache sits in front of the site

For a suspected bug in the code itself, [GitHub Issues](https://github.com/wpgaurav/gt-link-manager/issues) works too, and a security report should go to the maintainer privately rather than into a public thread.

= What Makes GT Link Manager Stand Out =

Plenty of plugins will shorten a URL for you. These are the things this one does that shaped how it was built.

**Everything is free.** Advanced Analytics, geolocation targeting, the full REST API, CSV import and export, regex and prefix-free redirects, the block editor tools. There is no Pro tier holding a feature back, no upsell notice in the admin, no telemetry, and no account to create. Nothing here is a trial.

**Redirects resolve before theme templates load.** The lookup runs on `init` at priority 0, against a UNIQUE-indexed slug column in a custom table. Match found, header sent, exit. No theme template or post lookup is required to resolve a matched short link.

**Dead links return a real 404.** A trashed or deactivated link stops resolving and says so with the correct status code. It does not quietly serve your front page at HTTP 200, which is what turns a retired affiliate link into duplicate home-page content in a search index.

**Geolocation costs nothing when you do not use it, and no database when you do.** Country detection reads a header your CDN already attaches, so there is no GeoIP file to install or keep updated and no external service in the request path. Links that do not opt in never trigger detection at all.

**The REST API covers the whole link lifecycle.** Create, read, update, trash, restore, and bulk-categorise, with a self-describing schema. That makes the plugin scriptable, and it is why AI tooling can manage links without a browser session.

**Click counting stores a number, not a person.** Turn it on and each link keeps a running total. No IP address, no user agent, no referrer, no timestamp. The write happens after the redirect has already gone out.

**Your links stay yours.** CSV import reads other plugins' exports so you can move in, and CSV export gives you everything back, geo rules included, so you can move out. Uninstall removes only what you tell it to remove.

Honest limits, because they matter more than the list above:

* basic counts show lifetime totals; optional advanced analytics adds dated reports, referring websites, and coarse device families
* country detection is only as trustworthy as the CDN in front of it. A forged header on an origin with nothing proxying it is still a forged header
* there is no automatic keyword linking. Links go where you put them
* it is a young plugin, and a young plugin has seen fewer edge cases than an old one

= Analytics & Advanced Integrations =

The **[Developer Reference](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/)** includes step-by-step integration guides for:

* **Analytics.** Google Analytics 4 (GA4), Plausible Analytics, Fathom Analytics, Matomo, and Simple Analytics
* **Tracking.** Custom click logging to a database table, dashboard widget, and UTM parameter passthrough
* **Automation.** Webhook notifications for Zapier, Make, and n8n
* **Advanced redirects.** Role-based redirects (route by user role) and geo-based redirects (route by location)
* **Customization.** Custom response headers, hooks and filters reference

= REST API & AI Tools =

GT Link Manager has a full REST API that works with AI tools for programmatic link management. The **[REST API & AI Tools Guide](https://gauravtiwari.org/gt-link-manager-rest-api-guide-ai-tools/)** covers authentication setup, all available endpoints, and integration with AI platforms including Claude Code, OpenAI Codex, WP-MCP, Novamira, Claudeus WordPress MCP, and WordPress MCP Adapter.

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

Yes. **Basic click counts** keep independent lifetime totals. **Advanced Analytics** adds date trends, referring posts and websites, page-to-link reports, countries, devices, browsers and campaigns. Both are opt-in and can be enabled separately.

Advanced Analytics adds no visitor script or cookie. It counts eligible redirect requests rather than unique visitors. You can also use `gtlm_before_redirect` for external analytics integrations.

= Can I keep reports forever? =

Yes. Choose **Forever** under **Analytics > Settings > Keep reports for**. Individual click records still use their separate retention period. Storage safeguards remain active, and deleting analytics removes the retained reports.

= Can I import from other link-management plugins? =

Yes. Go to **GT Links > Import / Export**, choose the **Pretty Links** or **LinkCentral** preset, upload your CSV, preview the column mapping, and import. Use **Generic** and map columns manually for compatible CSV exports from **ThirstyAffiliates, ClickWhale, Lasso, BetterLinks, GeniusLink**, or another tool. A link name and destination URL are required; review advanced fields such as country rules before importing. These additional tools use manual mapping, not dedicated presets.

= How are redirects resolved? =

The plugin hooks into WordPress `init` at **priority 0**, before template rendering. It parses the request URI, checks for your configured prefix, and looks up the slug in a **UNIQUE-indexed column** in a custom database table. If a match is found, it sends the redirect header and exits immediately, no theme or template loading.

= Can I customize which users can manage links? =

Yes. By default, any user with the `edit_posts` capability can manage links. Use the `gtlm_capabilities` filter to change this per context (e.g., require `manage_options` for settings but allow `edit_posts` for link creation).

= Is GT Link Manager really free? =

Yes, 100%. There are no premium tiers, upsells, or paywalls. Every feature, including Advanced Analytics, advanced redirects, the REST API, CSV import/export, and block editor integration, is included for free. There is also a free training course at [gauravtiwari.org](https://gauravtiwari.org/course/gt-link-manager-training/).

= Can I manage links with AI tools or the REST API? =

Yes. GT Link Manager includes a full REST API for creating, updating, and deleting links programmatically. This works with AI platforms like Claude Code, OpenAI Codex, and MCP adapters. See the [REST API & AI Tools Guide](https://gauravtiwari.org/gt-link-manager-rest-api-guide-ai-tools/) for setup instructions.

= Can I integrate with Google Analytics, Plausible, or other analytics? =

Yes. The [Developer Reference](https://gauravtiwari.org/course/gt-link-manager-training/developer-reference-1771422601/) has step-by-step guides for GA4, Plausible, Fathom, Matomo, and Simple Analytics. You can also set up webhook notifications for Zapier, Make, and n8n.

= Will it slow my site down? =

No. A branded link resolves with a single indexed lookup on `init` at priority 0, then sends the header and exits before the theme loads. On a test install a redirect measured about 9ms, and turning the click counter on moved that by roughly 0.1ms because the count is written after the redirect has already gone out.

The lookup is against a UNIQUE-indexed column, so 2,000 links resolve as fast as 20.

= Why does a deleted link now return a 404 instead of my home page? =

Because that is the correct answer, and until version 1.8.0 the plugin got it wrong.

A trashed or deactivated link used to fall through to WordPress, which resolved the leftover request to your front page and returned HTTP 200. Search engines read that as your home page living at hundreds of different URLs, and link checkers reported dead links as healthy. Unresolved links under your prefix now return a real 404 rendered by your theme.

If you depended on the old behaviour, `gtlm_404_on_missing_link` turns it off.

= Can I change the prefix after I have created links? =

Yes. Change it under **GT Links > Settings** and every existing link moves to the new prefix immediately, because the prefix is not stored per link. Rewrite rules are flushed for you.

Anything already published pointing at the old prefix will stop working, so treat a prefix change on an established site the way you would treat any permalink change.

= Does it work with a page cache or CDN? =

Yes. Redirect responses send `Cache-Control: no-store, no-cache, must-revalidate` along with the standard WordPress no-cache headers, so a caching layer will not hold on to a redirect and serve it after you have changed the destination.

Geolocation is the one thing to watch. Behind a CDN, a cached 301 pins a visitor to whichever country they were in on their first visit, which is why the link editor warns you when a geo-targeted link is set to 301. Use 302 for those.

= Does it work on multisite? =

It works, per site. Tables are created with the site's own database prefix, so each site in a network keeps its own links, categories, and settings. Links are not shared across the network and there is no network-level admin screen.

= Which redirect types can I use? =

301, 302, and 307.

* **301** is permanent and is what you want for most affiliate and outbound links
* **302** is temporary, and the right choice for anything geo-targeted, because browsers cache a 301
* **307** preserves the request method, which matters when something POSTs to the URL

= I trashed a link by mistake. Can I get it back? =

Yes, and you do not have to go hunting. The notice that appears after trashing, restoring, activating, or deactivating a link carries an **Undo** link, and it works for bulk selections as well as single links.

Trashed links also stay in the **Trash** view until you delete them. Automatic cleanup after a set number of days is available under Settings, and it is switched off on existing sites so an update never removes anything you had sitting there.

= Can I use links without the /go/ prefix? =

Yes. Turn on **Advanced Redirects** under Settings and a link can use direct mode, which serves it from the site root as `yoursite.com/your-slug`.

WordPress paths such as `wp-admin`, `wp-json`, `wp-login.php`, and `feed` are blocked as slugs, because claiming one of those would break the site rather than shorten a link.

= My geo rule is not matching. What should I check? =

Country detection reads a header your CDN or server adds to the request, so the first question is whether anything is adding one. Open **GT Links > Settings** and use **Check Detection**. It lists every country header present on the current request with its raw value, and runs a loopback test so you can confirm detection works even on a local install with no CDN in front of it.

If no header is present, nothing is proxying the site and there is no country to read. That is expected rather than a fault, and the plugin says so instead of failing quietly.

= Does it collect any personal data? =

The plugin does not add visitor tracking scripts or cookies. Request data is recorded only when the site owner explicitly enables advanced analytics.

Basic click counting stores a running total independently. Advanced analytics can retain a timestamp, referring hostname and page URL, coarse client families, configured campaign ID and optional country, under the configured retention policy. It does not retain IP addresses, raw user agents, or referring URL credentials, query strings or fragments. The suggested guidance under **Settings > Privacy** reflects the features and retention you enable.

= What happens when I uninstall? =

Uninstalling removes data only when **Delete Data on Uninstall** is enabled. Otherwise links and retained reports stay in place. Deactivation pauses advanced collection and preserves data; reactivation does not silently resume collection.

== Screenshots ==

1. **All Links**, admin list with search, filters, status views, and bulk actions
2. **Add/Edit Link**, form with branded URL preview, redirect type, rel attributes, and categories
3. **Categories**, manage link categories with parent/child hierarchy
4. **Settings**, configure prefix, defaults, flush permalinks, and run diagnostics
5. **Import/Export**, CSV import with column mapping preview and preset support

== Changelog ==

= 1.9.0-rc.10 =
* Individual click record retention has no upper day limit; a warning appears above 60 days.
* Settings diagnostics shows total plugin database size and whether advanced analytics and advanced redirects are enabled.

= 1.9.0-rc.9 =
* Choose 10, 20, 50 or 100 referring pages per result page, with centered pagination controls.
* Show active click collection in a light green status badge.

= 1.9.0-rc.8 =
* Move Clicked from to its own report page with a complete entry count and pagination.
* Make every retained referring-page entry accessible while keeping each page lightweight.
* Add a copyable AI setup prompt that reuses supplied information and asks only for missing details.

= 1.9.0-rc.7 =
* Use button tabs to avoid speculative page fetches.
* Explain external referring websites with a brief tooltip.
* Fit click charts to recorded activity and open exact totals in a scrollable popup.
* Link settings to the REST API guide for AI tools and expand feature and CSV-import documentation.

= 1.9.0-rc.6 =
* Preload all analytics breakdowns and switch tabs instantly without page refreshes or extra requests.
* Explain Page unavailable with a short accessible tooltip.

= 1.9.0-rc.5 =
* Link Privacy and data to the WordPress Privacy Policy Guide.
* Style and align campaign removal buttons with the other form controls.

= 1.9.0-rc.4 =
* Open referring-page reports with clicked links, trends, and accurate page-specific breakdowns.
* Add native breakdown tabs and clickable analytics counts in All Links.
* Show all settings, improve input accessibility, and add Forever report retention.
* Add a muted N/A geo badge and a WordPress.org review link.

= 1.9.0-rc.3 =
* Show the referring post or URL in a Clicked from table, with no visitor scripts or per-click post lookup.
* Upgrade existing analytics safely and keep unavailable historical page details explicit.

= 1.9.0-rc.2 =
* Simplify link settings and separate the analytics overview from collection settings.
* Protect WordPress endpoints from direct and regex rules across every write path.
* Protect exported spreadsheet cells and stage CSV imports outside public web directories.
* Add separately opted-in advanced analytics with no analytics storage or jobs before enablement.
* Add bounded collection, transactional summaries, retention, health backpressure, admin reports and CSV export.
* Use the WordPress site timezone for report dates, hourly/daily periods and exports, including DST and fractional offsets.
* Preserve direct/regex slugs through REST and advanced configuration through CSV imports.
* Keep click writes atomic while preserving configuration timestamps and emitting success hooks only after successful writes.
* Validate redirect URLs without DNS and reject unexpected standard-link path suffixes.
* Load admin/editor and REST services only in their relevant contexts.


= 1.8.1 =
* New: a copy button on the Branded URL column. It appears when you hover the row or focus it with the keyboard, copies the full branded link, and confirms with an inline Copied badge that clears itself.
* New: the plugin's own icon now appears in the admin menu, replacing the generic WordPress link dashicon. It is painted as a mask filled with the menu's own text colour, so it matches the icons beside it in every state and every admin colour scheme, staying light on dark sidebars and dark on light ones.
* Updated: refreshed the WordPress.org plugin icon.

= 1.8.0 =
* Fixed: the links table collapsed narrow columns when many were shown at once. With every column visible, Mode and Clicks were squeezed to a few pixels and their values wrapped one character per line. Every column now has a minimum width, the wide ones wrap instead of being clipped, and the table scrolls sideways on its own rather than stretching the admin page.
* New: Optional click counting, off by default. Turn it on under Settings and each link gets a running total of how many times it has been followed, shown as a sortable Clicks column and included in CSV export. It stores one number per link and nothing else -- no IP address, user agent, referrer, or timestamp -- and the count is written after the redirect has already been sent, so the redirect itself is not slowed down. The plugin's suggested privacy-policy text updates itself to match whichever setting you choose. Use `gtlm_count_click` to skip clicks you do not want counted.
* Fixed: a deleted, trashed, or deactivated short link returned HTTP 200 with the site's front page instead of a 404. The prefix rewrite rule matches the whole namespace, so an unresolved slug fell through to the home page, and search engines could index every dead link as duplicate front-page content. Unresolved prefixed links now return a real 404 rendered by the theme's own template. Direct and regex mode still fall through untouched, and the `gtlm_404_on_missing_link` filter restores the old behaviour.
* Fixed: `rel` values separated by spaces were silently discarded. The plugin writes space-separated rel into Link headers and core Button blocks, but only accepted commas on input, so round-tripping a value emptied it. Commas, spaces, and arrays are all accepted now, and the allowed-token validation is unchanged.
* Fixed: the Active, Inactive, and Trash views were implemented but never rendered, leaving them reachable only by typing the URL by hand. They now appear above the links table.
* Fixed: bulk actions ran while the page rendered, which gave no confirmation message and re-ran the action on a browser refresh. They now run before output and redirect to a clean URL with a result message.
* New: Undo. Trashing, restoring, activating, and deactivating a link now offer a one-click Undo in the success notice, for single links and bulk selections alike.
* New: Trash retention. Links left in the Trash can be permanently deleted after a configurable number of days, set under Settings. New installs start at 30 days. **Existing sites are left at 0 (keep forever) on upgrade**, so nothing already sitting in your Trash is deleted because you updated; switch it on yourself when you want it.
* New: Empty Trash button on the Trash view.
* Improved: the admin screens now inherit the WordPress admin surface instead of painting over it. Custom admin colour schemes, high-contrast mode, and reduced-motion preferences are all respected.
* Improved: accessibility. Row checkboxes and every filter dropdown have proper labels, keyboard focus is visible, and the notice area sits where WordPress expects it.
* Improved: long URLs no longer break across three lines in the links table. The Branded URL column shows the readable path, with the full URL on hover and in the Copy URL action.
* Improved: a fresh install now records its schema version during activation instead of re-running the migration on the first admin page load.
* Compatibility: Tested against WordPress 7.1. Verified on a WordPress 7.1 and PHP 8.4 install: redirects, geolocation targeting, the REST API, CSV import and export, the links list table, and both block editor inserters all behave as before, with no deprecation notices raised.
* Compatibility: Confirmed the GT Link format and the core Button control still work inside the iframed block editor canvas, including search, insertion, and rel handling.

= 1.7.1 =
* Fixed: The GT Link toolbar now works with the core Button block by updating the Button's native URL and rel attributes.
* Improved: Rich text and Button controls now share the same GT Link search popover, normalize rel attributes, and preserve `noopener` for buttons that open in a new tab.

= 1.7.0 =
* New: Geolocation targeting. Any link can route visitors to a different destination based on their country, with rules evaluated in order and the first country match winning.
* New: Country detection with zero dependencies. The country is read from request variables your CDN or web server already provides — Cloudflare (`CF-IPCountry`), CloudFront, Vercel, Google App Engine, nginx GeoIP2, Apache mod_geoip, mod_maxminddb, or a custom header you name. No GeoIP database file, no third-party API, no outbound request.
* New: `EU` country group expands to all 27 member states in a single rule; extendable via `gtlm_geo_country_groups`.
* New: Per-rule status codes, and a "show a 404" fallback for visitors matching no rule.
* New: Geolocation settings section with a live "Detected Now" readout showing which country and source resolved for the current request — the fastest way to confirm your CDN is forwarding a country header.
* New: "Check Detection" button. Lists every country header present on the request with its raw value, and runs a loopback self-test — it sends the site a request carrying a country header and reports what the plugin detected at the other end. That proves detection works even on a local or staging install with no CDN in front, where "no country on this request" is correct rather than a fault. Also validates a country code you type before you use it in a rule.
* New: The detection readout distinguishes "nothing is proxying this site, so no country is expected" from "your CDN is in front but sent no country header" — only the second is a misconfiguration, and it now says how to fix it.
* New: Rule builder in the link editor. Numbered rows show match precedence, rules can be reordered, one-click picks cover common markets (US + CA, EU, UK, India, AU + NZ), the long country list is filterable, selections show as removable chips, and the "Everyone else" fallback reads as the final row. It warns when a country is listed twice (only the highest rule can ever match) or when a rule has countries but no URL.
* New: Rule preview. Pick a country and see exactly which rule wins and where it sends — evaluated in the browser against your unsaved edits, with no request made.
* New: Privacy disclosure. The plugin registers a suggested privacy-policy section under Settings → Privacy → Policy Guide, and states inline that country detection reads only a CDN-provided header — never the visitor's IP address, never an external service, and the country is never stored or logged.
* Fixed: a partial REST update (PATCH) of one field silently reset every field that was not included, because each write argument declared a schema default that WP_REST_Request materialises into the request. Sending only `geo_rules` would reset `redirect_type` to 301 — which quietly breaks geo targeting — and blank `tags`, `notes`, and `rel`. Omitted fields now keep their stored values. Create defaults are unchanged.
* New: Optional `X-GTLM-Country` debug response header showing the detected country, its source, and whether a rule matched.
* New: Geo rules are exposed in the REST API on `/links` (create, read, update) with a self-describing schema, so links can be geo-targeted programmatically. Posting `geo_rules` without `geo_mode` opts the link in automatically.
* New: Geo rules round-trip through CSV import and export; a malformed rules cell is dropped without failing the row.
* New: Geo column in the links list table, and geolocation status in Diagnostics.
* Performance: geolocation costs nothing on links that do not use it — the check is a single array read, and country detection is never invoked unless a matched link opts in. Detection and settings are resolved once per request.
* Note: geo-targeted links should use 302, not 301. A 301 is cached by the browser permanently, which pins a visitor to whichever country they were in on their first click. The link editor warns when a geo link is set to 301.
* Note: a country header can be forged on requests that reach your site without passing through your CDN, so the 404 fallback is not a security control. Behind Cloudflare, `CF-IPCountry` is rewritten at the edge and cannot be spoofed; the "Cloudflare only" detection method is the strictest setting.

= 1.6.1 =
* Fixed nonce mismatch bug — trash, restore, and permanent delete actions were failing due to inconsistent nonce prefixes.
* Fixed potential ReDoS vulnerability — removed error suppression on regex pattern matching, added pattern length validation.
* Standardized all nonce prefixes to `gtlm_` across admin actions and list table.
* Added REST API route descriptions to all endpoints for better discoverability.
* Added object caching for admin category dropdown with proper invalidation on create, update, and delete.
* Updated readme with free training course, developer reference, REST API guide, and new FAQ entries.

= 1.6.0 =
* Added advanced redirects: Direct (prefix-free) and Regex (pattern-based) link modes.
* Direct links redirect without the prefix — e.g., `yoursite.com/my-page` instead of `yoursite.com/go/my-page`.
* Regex links match request paths against patterns with capture group substitution in destination URLs.
* New "Enable Advanced Redirects" toggle in Settings (off by default) to opt in.
* Link edit form shows mode selector (Standard / Direct / Regex) with contextual fields.
* Conflict detection warns when direct link paths match existing WordPress posts or pages.
* Regex patterns are validated on save; invalid patterns are rejected.
* List table shows link mode with filter support.
* REST API accepts and returns `link_mode`, `regex_replacement`, and `priority` fields.
* New database columns: `link_mode`, `regex_replacement`, `priority` — fully backwards compatible.

= 1.5.3 =
* Fixed plugin zip size bloat — excluded .wordpress-org assets directory from distribution package.

= 1.5.2 =
* Added "Delete Data on Uninstall" setting — data is now preserved by default when the plugin is deleted.
* Uninstall only removes tables and options if the user explicitly opts in via Settings.

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

= 1.8.1 =
Adds a hover-and-click copy button to the Branded URL column and the plugin's own icon in the admin menu, plus an expanded readme and FAQ. No database changes.

= 1.8.0 =
Tested with WordPress 7.1. Fixes a soft 404 that made every dead, trashed, or deactivated short link return the site's front page at HTTP 200, and fixes space-separated rel values being silently dropped. Adds optional click counting (off by default), Undo for trash and status changes, optional automatic Trash cleanup (off for existing sites, so nothing in your Trash is deleted by updating), and an accessibility and native-UI pass on the admin screens. Adds one column to the links table on upgrade; existing links are untouched.

= 1.7.1 =
Fixes the GT Link inserter for the core Button block while preserving the Button block's native link settings.

= 1.7.0 =
Adds geolocation targeting: route each link by visitor country using the header your CDN already sends — no GeoIP database, no external API, no added latency. Two columns are added to the links table automatically on upgrade; existing links are untouched and keep redirecting exactly as before. Enable it under GT Links → Settings → Geolocation Targeting.

= 1.6.1 =
Fixes nonce mismatch bug affecting trash/restore/delete actions, hardens regex pattern validation, adds REST API descriptions and category caching.

= 1.6.0 =
Adds direct (prefix-free) and regex (pattern-based) redirect modes. Enable in Settings > Advanced Redirects. Fully backwards compatible.

= 1.5.2 =
Data is now preserved on uninstall by default. Enable "Delete Data on Uninstall" in Settings to remove all data.

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
