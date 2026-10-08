---
name: gt-link-manager
description: "Create, find, update and organize GT Link Manager short links (branded redirects such as /go/product) on a WordPress site through the Site Agent plugin. Use when asked to add an affiliate or short link, change where a link goes, check whether a link exists, set country-based destinations, sort links into categories, deactivate or trash a link, or read link click analytics."
compatibility: "GT Link Manager 1.10+ and Site Agent 0.4+ with PHP execution on. Without Site Agent, the same operations work through the gt-link-manager/v1 REST API."
---

# GT Link Manager through Site Agent

GT Link Manager turns branded paths such as `example.com/go/hosting` into redirects. Each link
has the following:

- a slug
- a destination URL
- a redirect type (301, 302 or 307)
- link attributes (`nofollow`, `sponsored`, `ugc`)
- an optional noindex flag, category, tags and notes
- optional country rules

The site's prefix (often `go`) and its defaults come from its settings.

Every command here runs `gtlm_agent()` on the site as the WordPress user the agent is connected
as, through the plugin's own REST routes. Duplicate slugs, reserved paths and permissions are
checked exactly as in wp-admin.

## Running a command

1. Call Site Agent's site-context tool once. Check that `site_url` is the site the user means and
   that `enabled_tools` includes `execute-php`.
2. Call the tool whose name ends in `execute-php`. Put the input in a nowdoc:

```php
return gtlm_agent('links.list', json_decode(<<<'JSON'
{"search": "hosting", "per_page": 20}
JSON, true));
```

Keep the closing `JSON` at the start of its own line. The tool's `return_value` is the result.
Every result has `ok`, and `error` explains a refusal. Pass that message to the user as written.

| Command | Input | Result |
| --- | --- | --- |
| `context` | none | Version, link base URL, default redirect type, attributes and noindex, whether click tracking and country rules are on, link counts, category count |
| `links.list` | `search`, `page`, `per_page` (up to 200), `category_id`, `status` (`active`, `inactive`, `trash`), `orderby`, `order` | Links and `total` |
| `links.get` | `id`, or `slug` (with or without the prefix) | One link |
| `links.create` | `link` | The new link. The request fails if the slug exists |
| `links.update` | `id` or `slug`, `patch` | The updated link |
| `links.set_active` | `id` or `slug`, `is_active` | Turns the redirect on or off without deleting it |
| `links.trash` / `links.restore` | `id` or `slug` | Moves the link to the trash, or back |
| `links.bulk_category` | `link_ids`, `category_id`, `mode` (`move` or `copy`) | Result |
| `categories.list` / `categories.create` | `search` / `category` with `name`, `slug`, `description`, `parent_id` | Categories |
| `analytics.status`, `analytics.summary`, `analytics.breakdown` | The REST API's analytics parameters | Analytics, read-only. These need an administrator |

A `link` takes these fields:

- `name` and `url` (required)
- `slug`
- `redirect_type`
- `rel` (a list or a comma-separated string)
- `noindex`, `is_active`
- `category_id`, `tags`, `notes`
- `link_mode` (`standard`, `direct` or `regex`), with `regex_replacement`
- `priority`
- `geo_mode` (`off` or `targeted`) and `geo_rules`: `{"rules": [{"countries": ["IN"], "url": "https://...", "redirect_type": 302}], "fallback": "default"}`

Leave out fields you don't need, and the site's defaults apply. In results, `url` is the branded short link and `target_url`
is the destination. When you create or update a link, send the destination as `url`.

## Workflows

**Add a link.**
1. Run `context`.
2. Run `links.get` with the slug you plan to use, and `links.list` with a `search` for the brand.
   This catches existing links under another slug.
3. If nothing matches, run `links.create`. Affiliate links usually need `nofollow` and
   `sponsored`. Country-based links should use 302.
4. Give the user the branded URL: `link_base` plus the slug.
5. If you can make HTTP requests, request it without following redirects and check the
   `Location` header.

**Change a destination.** Run `links.get` first and show the user the current URL and click
count. Then run `links.update` with only the changed fields, and read the link back.

**Organize.** Run `categories.list`, create a missing category if needed, then move links with
`links.bulk_category`.

## Ask before

- Changing the destination, redirect type or country rules of a link that is already live. It
  changes where real visitors go.
- Deactivating or trashing a link. Show its clicks first.
- Changing more than a few links in one pass.

Creating new links and reading links, categories and analytics need no approval. Permanent
deletion and analytics settings stay in wp-admin.

## Without Site Agent

Use the REST API at `/wp-json/gt-link-manager/v1/` with a WordPress Application Password over
HTTPS. The guide is at https://gauravtiwari.org/gt-link-manager-rest-api-guide-ai-tools/.
