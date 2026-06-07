=== ProjectMakers Health Endpoint ===
Contributors: projectmakersde
Tags: health check, uptime, monitoring, diagnostics, cron
Requires at least: 5.3
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 2.1.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Lightweight public health endpoint and optional internal server monitoring for WordPress.

== Description ==

Health Endpoint exposes a small public endpoint for uptime monitors and confirms that WordPress and the database are responding. The public payload is intentionally minimal and only returns status, database state, and time.

The plugin can also run internal server checks for database connectivity, disk usage, CPU load, and RAM usage. It can send email alerts when configured thresholds are breached.

Key features:

* Public health endpoint for uptime monitors.
* Pretty URL, query-string fallback, and REST endpoint.
* Optional plain-text OK/ERROR response.
* Optional token-protected diagnostics payload.
* Internal database, disk, CPU, and RAM monitoring.
* Configurable internal check interval.
* Email alerts with cooldown and optional recovery notifications.
* Admin page for live status, endpoint URLs, monitoring settings, and token generation.

The public endpoint does not expose sensitive diagnostics. Extended diagnostics require a configured token.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/health-endpoint/` directory, or install the plugin ZIP through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Health** in the WordPress admin menu.
4. Copy one of the listed endpoint URLs into your uptime monitor.
5. Optionally enable internal monitoring and configure alert recipients and thresholds.

== Frequently Asked Questions ==

= Which endpoint should I monitor? =

For most sites, use `https://example.com/?health_check=1` or `https://example.com/wp-json/health/v1/check`. These variants are less likely to be affected by full-page caching than the pretty `/health` URL.

= Does the public endpoint expose sensitive data? =

No. The public endpoint only exposes `status`, `db`, and `time`. Detailed diagnostics require a valid token.

= Can I generate a diagnostics token in the admin? =

Yes. The settings page includes a **Generate token** button unless the `HEALTH_ENDPOINT_TOKEN` constant is defined in `wp-config.php`.

= How often does internal monitoring run? =

The interval is configurable. Available intervals are 1, 2, 5, 10, 15, 30, and 60 minutes. WP-Cron depends on site traffic, so low-traffic sites should use a real server cron.

= Does this replace an external uptime monitor? =

No. The internal monitor is useful for local alerts, but an external uptime monitor is still recommended to detect complete outages.

== Changelog ==

= 2.1.3 =

* Renamed the WordPress-facing plugin name to ProjectMakers Health Endpoint for Plugin Directory naming compliance.
* Added a WordPress.org readme.txt.
* Added a GitHub Actions workflow for WordPress Plugin Check.

= 2.1.2 =

* Removed GitHub token settings.
* Added configurable internal monitoring interval.
* Added last-check duration reporting.
* Added diagnostics token generation.
* Updated the admin footer.

= 2.1.1 =

* Prepared the repository for public open-source release.
* Rewrote public documentation in English with generic examples.
* Added WordPress translation metadata and a POT template.
* Cleaned public-facing descriptions and security guidance.

= 2.1.0 =

* Added admin page with live status, endpoint list, and settings.
* Added internal cron monitoring for database, disk, CPU, and RAM.
* Added configurable thresholds, sustained checks, cooldown, and optional recovery emails.
* Added manual check and test email actions.
* Added plain-text OK/ERROR monitor responses.
* Added GitHub Releases based auto-updates and release ZIP build workflow.

= 2.0.0 =

* Added REST route, query-string fallback, and token-protected diagnostics.
* Added fail-fast database checks, HEAD support, and optional `db-error.php` drop-in.

= 1.0.3 =

* Initial public health endpoint with database connection check.
