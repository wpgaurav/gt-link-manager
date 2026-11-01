# Analytics integrations

**GT Links > Integrations** sends short link clicks to analytics tools. Every tool is off by default and needs `manage_options` to configure.

## Routes

A tool uses one of three routes, chosen by what it actually offers:

| Route | Tools | How a click arrives |
|---|---|---|
| Plugin | Independent Analytics, Koko Analytics, WP Statistics, Burst Statistics, SlimStat, Statify, Matomo for WordPress | PHP call into the plugin's own tracker on this site. No outbound request from us. |
| Service | GA4, Plausible, Matomo, Umami, Simple Analytics, PostHog, Mixpanel, Amplitude, Rybbit, Clicky | `wp_safe_remote_request()` from this server. |
| Browser | Google Tag Manager, Fathom, Microsoft Clarity, GA4 in browser mode | `assets/js/integrations.js` calls the tag already on the page. |

Fathom and Clarity have no server-side event API, and Fathom's Events API returns "no longer available" for event creation. The older docs snippet that posted to `cdn.usefathom.com/script.js` could not have worked.

Not supported, because neither offers a way to record a custom event: Jetpack Stats (`jetpack-stats` only exposes a page view pixel) and Cloudflare Web Analytics (its FAQ says custom events are not supported). Post Views Counter and WP-PostViews only count posts, so a short link has nothing to attach to. Google Site Kit, MonsterInsights, ExactMetrics, Analytify and GA Google Analytics are covered through GA4, not as separate tools.

## Request lifecycle

`GTLM_Redirect::maybe_redirect()` runs on `init` priority 0:

1. Send the `Location` header.
2. `finish_response()`: `fastcgi_finish_request()`, then `litespeed_finish_request()`. With neither available and integrations on, it sends `Connection: close` and `Content-Length: 0` and flushes, for mod_php and `php -S`.
3. Lifetime counter, then Advanced Analytics, then `GTLM_Integrations::dispatch()`.

`dispatch()` applies the same `GTLM_Analytics_Collector::is_eligible_visit()` check as Advanced Analytics: GET only, no prefetch, no recognized bot, no empty user agent, no logged-in link manager. Plugin tools run first because they are local. Each service gets a two-second timeout, and a thrown error or `WP_Error` from one tool never stops the next.

Measured on the local fixture with PHP's built-in server, with all 10 services pointed at a mock that sleeps 3 seconds: redirect TTFB stayed at 15 to 19 ms.

## Cost when off

`gtlm_settings` holds an `enabled_integrations` list, which the redirect and bootstrap already read. With an empty list, no integration class loads and no extra query runs. Config and credentials live in the separate `gtlm_integrations` option, not autoloaded, read only after a redirect or when the listener loads. `uninstall.php` removes it.

Don't build `GTLM_Integrations::tools()` during bootstrap. It calls `__()`, and `plugins_loaded` is too early for translations; use the `BROWSER_TOOLS` constant.

## Visitor data

Off by default, sharing is the `share_visitor_data` setting.

| | Sharing off | Sharing on |
|---|---|---|
| Short link, destination, category, status | sent | sent |
| Referrer, without query string, fragment or credentials | sent | sent |
| Browser user agent | sent | sent |
| Visitor identifier | new random value per click | HMAC of date, IP and user agent, rotates daily, never stored |
| IP address | never read for this | first public address of `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `REMOTE_ADDR` |
| Country | not sent | sent only when a geo link detected one |

Some services are worse off with sharing off, because they use the sending server's address instead: Plausible and Rybbit attribute clicks to one server-side visitor and location, and Amplitude geolocates the server. PostHog and Mixpanel are told not to geolocate (`$geoip_disable`, `ip=0`). Matomo ignores an IP without a token. All of this is why sharing exists as an option.

GA4 reuses the visitor's `_ga` and `_ga_<stream>` cookies when present, in both GS1 and GS2 formats, so the event joins their session. No other cookie is read, apart from Burst's and Koko's own.

`GTLM_Admin::register_privacy_content()` must keep matching this. If you change what is sent, change that text in the same commit.

## Plugin adapters

These call other plugins' code, and some of it is marked internal, so `tests/integration-plugins.php` exercises each one against the real plugin. Rerun it after those plugins update.

| Tool | Entry point | Public API? | Result |
|---|---|---|---|
| WP Statistics | `wp_statistics_event()` plus the `wp_statistics_custom_events` filter | Yes | `gtlm_link_click` row in `statistics_events`. Reports need its Data Plus add-on. |
| Burst Statistics | `do_action( 'burst_event', ... )` | Yes | `link_click_{slug}` event. Needs `enable_custom_events` and the `burst_uid` cookie. |
| SlimStat | `wp_slimstat::slimtrack_server()` | Yes, documented for redirect handlers | Pageview of the short link path. |
| Koko Analytics | `koko_analytics_track_pageview()` | Yes | Pageview of the path. Custom events are Pro. |
| Matomo for WordPress | `WpMatomo\AjaxTracker::doTrackEvent()` | Used by its own ecommerce code | Event under Behaviour > Events. Skipped when Matomo's tracking is off. |
| Independent Analytics | `IAWP\Views\View` with a `virtual_page` resource | Internal class. `iawp_override_virtual_page_details` is the public filter that names the page. | View of a GT Link page. Its click tracking is Pro and browser-side. |
| Statify | Protected `Statify::track()`, through an anonymous subclass | Internal | View of the path. |

Independent Analytics and Koko count clicks as views, so a site's view total rises with clicks. The screen says so.

Burst's cookieless mode can't be supported: it needs a PHP session, and that can't start once redirect headers have gone. The adapter returns early without the cookie, because calling it logs `session_save_path()` warnings.

## Detection

`detected_values()` reads settings other plugins already hold, and `sanitize_config()` fills blank fields from it when a tool is enabled. A value is copied at save time, so redirects never call into another plugin for it.

| Tool | Source |
|---|---|
| GA4 ID and secret | `monsterinsights_get_v4_id()` and `auth->get_measurement_protocol_secret()`; the ExactMetrics equivalents; Analytify's `analytify_ua_code` and `measurement_protocol_secret` |
| GA4 ID only | Site Kit's `googlesitekit_analytics-4_settings`; GA Google Analytics' `gap_options` |
| Plausible | `plausible_analytics_settings` |
| Matomo | Connect Matomo's `wp-piwik_global-piwik_url` and `wp-piwik-site_id` |
| Clicky | `clicky` option |
| Google Tag Manager | GTM4WP's `gtm4wp-options` data layer name |

## Hooks

| Hook | Type | Use |
|---|---|---|
| `gtlm_integrations_should_send` | filter | Return false to withhold a click, for example until consent |
| `gtlm_integration_event` | filter | Change the normalized click event |
| `gtlm_integration_request` | filter | Change or cancel one service's request |
| `gtlm_integration_visitor_ip` | filter | Supply the IP shared when sharing is on |
| `gtlm_integration_sent` | action | React to a service's response |
| `gtlm_integrations_load_bridge` | filter | Stop the browser listener on a page |

A self-hosted service on a private address needs the core `http_request_host_is_external` filter, and on an unusual port `http_allowed_safe_ports`, because delivery goes through `wp_safe_remote_request()`.

A site still running a `gtlm_before_redirect` snippet from the old docs will send each click twice once it turns on the same tool here. Remove the snippet.

## Limits

* Browser tags see clicks on this site's pages only, not links opened from email or social posts, and not visitors who block the tag. They recognize links under the prefix, so prefix-free links are not seen.
* The test button is real for every service except GA4, which uses Google's validator. Google's validator does not check the API secret.
* Plausible's Events API returns 202 even for a dropped event, and PostHog returns 200 for an invalid one, so a passed test proves the request was accepted, not that it appears in a report.
* Clicky limits server-side logging to its Pro plans.

## Novashare

The same screen holds Novashare under **Social sharing**. It isn't an analytics tool, because Novashare asks this plugin for a short link rather than receiving clicks, so it stays out of the tool registry and keeps its `novashare_short_links` flag in `gtlm_settings`. `GTLM_Settings::update()` leaves that flag alone, so saving the main settings form can't turn it off.

## Testing

```bash
GTLM_TEST_WP_ROOT=/path/to/fixture tests/run.sh
```

`tests/integrations.php` covers each service's exact request with `pre_http_request`, the sanitizer, eligibility, sharing, the listener, the screen and the privacy text. `tests/integration-plugins.php` skips any plugin that isn't active, so to run it for real:

```bash
wp plugin install independent-analytics koko-analytics wp-statistics burst-statistics wp-slimstat statify matomo --activate
```

```bash
wp matomo install
```

```bash
wp matomo globalSetting set track_mode default
```

Both suites require `GTLM_TEST_FIXTURE` and a database name starting `gtlm_test_`.
