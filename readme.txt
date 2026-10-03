=== Brocode Utility Endpoints ===
Contributors: brosenberger
Tags: rest-api, utilities, cache, rewrite, wp-cli
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Admin-authenticated REST endpoints and WP Abilities for site management — flush rewrites, clear cache, scan links, manage plugins, set Yoast SEO meta.

== Description ==

Brocode Utility Endpoints exposes a small set of admin-level REST API endpoints for programmatic WordPress site management. All endpoints require WordPress authentication and the appropriate user capabilities — no unauthenticated access is possible.

**Endpoints:**

* `POST /brocode/v1/flush-rewrites` — flush WordPress rewrite rules
* `POST /brocode/v1/clear-cache` — clear the active page cache (Cache Enabler, W3 Total Cache, WP Super Cache)
* `GET /brocode/v1/scan-links?pattern=…` — find all posts whose content contains a URL pattern
* `GET /brocode/v1/indexnow-status` — read the outcome of the last IndexNow submission
* `POST /brocode/v1/manage-plugin` — activate, deactivate, or delete a plugin
* `POST /brocode/v1/seo-meta/{id}` — write Yoast SEO focus keyword, title, and meta description

All endpoints are also registered as **WP Abilities** (WordPress 6.9+) for use with the WordPress MCP adapter or other Ability consumers.

A **Settings admin page** (`Settings → Brocode Utilities`) provides a browser-based interface for the three most common operations.

**WP-CLI commands** are available for scripting and automation:

    wp brocode-utility flush-rewrites
    wp brocode-utility clear-cache
    wp brocode-utility scan-links [--pattern=<pattern>]

== Installation ==

1. Upload the `brocode-utility-endpoints` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings → Brocode Utilities** to use the admin UI.
4. To call the REST endpoints programmatically, authenticate with an Application Password (`Users → Profile → Application Passwords`).

== Frequently Asked Questions ==

= Which cache plugins are supported? =

Cache Enabler, W3 Total Cache, and WP Super Cache. The `clear-cache` endpoint silently succeeds (returning `"none": true`) if none of these are active.

= Is Yoast SEO required? =

Only for the `/seo-meta/{id}` endpoint. All other endpoints work independently of Yoast SEO. If Yoast is not active, the endpoint returns a `503` with a descriptive error message.

= What WordPress capability is required per endpoint? =

* `manage_options` — flush rewrites, clear cache, scan links (administrator by default)
* `activate_plugins` — manage-plugin activate/deactivate
* `delete_plugins` — manage-plugin delete
* `edit_post` on the target post — set SEO meta

= Is it safe to expose these endpoints publicly? =

Yes. Every endpoint verifies the required WordPress capability before executing. Use Application Passwords or cookie authentication; never embed credentials in client-side code.

= Can I use this with the WordPress MCP adapter? =

Yes. All five endpoints are registered as WP Abilities on the `wp_abilities_api_init` hook (WordPress 6.9+), which is the hook the MCP adapter reads.

== Screenshots ==

== Changelog ==

= 1.3.0 =
* Added content sync endpoints for a two-way git ↔ WordPress workflow: `GET content-export` (posts in an environment-neutral form — `{{home}}` URLs, attachment IDs with a path map, post references as slugs), `POST content-import` (one post by type + slug; translates media IDs to the target site, refuses with 409 when the post changed since the last sync), `POST media-ensure` (registers or uploads media at its original uploads path).
* Meta fields that hold post IDs are declared per site through the `brocode_content_sync_post_refs` filter.

= 1.2.0 =
* Refactored into `includes/` — core logic, REST callbacks, Abilities, admin UI, and WP-CLI are now in separate files for maintainability.
* Added `readme.txt` (WordPress.org format).
* Added `uninstall.php` — removes all plugin transients on uninstall.
* Added `Domain Path: /languages` header.
* Plugin constants renamed to `BUE_*` prefix and moved to the main bootstrap file.
* Bumped `Tested up to` to 6.8.

= 1.1.0 =
* Added admin UI (`Settings → Brocode Utilities`) with flush, clear-cache, and scan-links forms.
* Added WP-CLI commands (`wp brocode-utility flush-rewrites / clear-cache / scan-links`).
* Added i18n support with `load_plugin_textdomain()`.
* Fixed: WP Abilities must be registered on `wp_abilities_api_init`, not `init`.

= 1.0.0 =
* Initial release: five REST endpoints under `brocode/v1` with dual-registration as WP Abilities.

== Upgrade Notice ==

= 1.3.0 =
Adds three REST routes (content-export, content-import, media-ensure); no database changes. They require Application Passwords (Wordfence's "disable application passwords" option must be off).

= 1.2.0 =
Code reorganization only — no functional changes, no database changes, no migration needed.
