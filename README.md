# ProjectMakers Health Endpoint

Lightweight WordPress health and uptime endpoint with optional internal server monitoring.

ProjectMakers Health Endpoint exposes a small public endpoint that confirms WordPress and the database are responding. It can also run internal checks for database connectivity, disk usage, CPU load, and RAM usage, then send email alerts when configured thresholds are breached.

The plugin is designed for public open-source use: public responses stay minimal, diagnostics require a token, and repository documentation uses generic examples only.

The WordPress.org-facing plugin name starts with `ProjectMakers` to avoid a generic directory name. Submit the plugin from a verified ProjectMakers account/email domain.

## Contents

- [Features](#features)
- [Installation](#installation)
- [Admin Page](#admin-page)
- [Endpoints](#endpoints)
- [Internal Monitoring and Email Alerts](#internal-monitoring-and-email-alerts)
- [Real Cron Setup](#real-cron-setup)
- [Token-Protected Diagnostics](#token-protected-diagnostics)
- [HTTP Status Codes](#http-status-codes)
- [Configuration](#configuration)
- [Security](#security)
- [Caching Notes](#caching-notes)
- [Translation](#translation)
- [Public Repository Checklist](#public-repository-checklist)
- [Changelog](#changelog)

## Features

- Public `/health` endpoint for uptime monitors such as Uptime Kuma or UptimeRobot.
- Query-string and REST fallbacks for hosts where pretty permalinks are unavailable.
- Minimal public JSON payload: `status`, `db`, and `time`.
- Optional token-protected diagnostics payload with plugin, WordPress, PHP, database latency, disk, CPU, and RAM details.
- Optional internal WP-Cron monitoring for database, disk, CPU, and RAM with a configurable check interval.
- Email alerts with sustained-threshold detection, cooldown, and recovery notifications.
- Admin page for live status, endpoint URLs, monitoring settings, diagnostics token, and token generation.
- Optional `db-error.php` drop-in for consistent health responses when the database is unavailable during WordPress bootstrap.
- Translation-ready WordPress strings through the `health-endpoint` text domain.

## Installation

This plugin is a normal WordPress plugin directory named `health-endpoint`.

### Option A: Install a Release ZIP

Download `health-endpoint.zip` from the [GitHub Releases](https://github.com/ProjectMakersDE/wp-health-endpoint/releases), then upload it in WordPress under **Plugins > Add New > Upload Plugin**.

### Option B: Install by SFTP or SSH

Copy the plugin directory to `wp-content/plugins/health-endpoint/`, then activate it in WordPress.

```bash
rsync -a health-endpoint/ user@server:/var/www/example-site/wp-content/plugins/health-endpoint/
```

### Option C: Install with WP-CLI

```bash
wp plugin install https://github.com/ProjectMakersDE/wp-health-endpoint/releases/latest/download/health-endpoint.zip --activate
```

Activation flushes WordPress rewrite rules so `/health` works immediately. If `/health` returns `404`, save **Settings > Permalinks** once, or use the query-string or REST endpoint variants.

GitHub release ZIPs are installable packages only. The plugin does not include a custom updater; WordPress.org-hosted installs update through the normal WordPress.org plugin update flow after directory submission.

## Admin Page

After activation, WordPress shows a **Health** menu item in the admin area. The page includes:

- Live status for database, disk usage, CPU load, and RAM usage.
- Endpoint URLs for the current site.
- Monitoring settings, alert recipients, check interval, thresholds, cooldown, diagnostics token, and token generation.
- Manual **Run check now** and **Send test email** actions.

## Endpoints

All variants return the same JSON unless `format=plain` is used.

| Variant | URL | Requirement |
|---|---|---|
| Pretty | `https://example.com/health` | Pretty permalinks enabled |
| Query fallback | `https://example.com/?health_check=1` | Always available |
| REST | `https://example.com/wp-json/health/v1/check` | Always available |
| REST with plain permalinks | `https://example.com/?rest_route=/health/v1/check` | Always available |
| Plain text | `https://example.com/?health_check=1&format=plain` | Returns `OK` or `ERROR` |

```bash
curl -i "https://example.com/?health_check=1"
curl -s "https://example.com/?health_check=1&format=plain"
curl -i "https://example.com/wp-json/health/v1/check"
curl -I "https://example.com/health"
```

Healthy response, HTTP `200`:

```json
{ "status": "ok", "db": "connected", "time": "2026-06-04T09:12:00+00:00" }
```

Database unavailable during the request, HTTP `503`:

```json
{ "status": "error", "db": "down", "time": "2026-06-04T09:12:00+00:00" }
```

### Uptime Kuma

Recommended settings:

1. Monitor type: `HTTP(s)` or `HTTP(s) - Keyword`.
2. URL: `https://example.com/?health_check=1` or `https://example.com/wp-json/health/v1/check`.
3. Request timeout: at least `10` seconds.
4. Optional keyword: `"status":"ok"` or `"status": "ok"`.
5. Accepted status codes: `200` only.

## Internal Monitoring and Email Alerts

Enable **Internal monitoring** on the admin page and configure one or more alert email addresses. The plugin then samples the server at the configured interval and alerts when a problem is detected. The default interval is one minute.

| Check | Trigger | Configurable |
|---|---|---|
| Database | Unreachable | Immediate after debounce |
| Disk | Used space at or above threshold | 60, 70, 80, 85, 90, or 95 percent |
| CPU | Load per core at or above threshold for the configured duration | Threshold and duration |
| RAM | Used memory at or above threshold for the configured duration | Threshold and duration |

Notes:

- Sustained checks keep a short rolling history, so a single CPU or RAM spike does not trigger an alert.
- The check interval can be set to 1, 2, 5, 10, 15, 30, or 60 minutes.
- CPU percentage is the 1-minute load average divided by detected CPU cores.
- Some shared hosts disable `sys_getloadavg` or `/proc`; unsupported CPU/RAM metrics are shown as `n/a` and skipped.
- Cooldown controls repeat alerts for the same problem.
- Cooldown `0` sends one alert per incident and no reminders until recovery.
- Recovery emails can be sent when a problem clears.
- Database and disk alerts require consecutive failed samples to reduce noisy alerts.

Alert delivery uses `wp_mail()`. For production sites, configure a reliable SMTP plugin or transactional email provider.

## Real Cron Setup

WP-Cron only runs when WordPress receives traffic. For reliable checks on low-traffic sites, disable the built-in pseudo cron and trigger WP-Cron from the server. The server cron should run at least as often as the shortest interval you plan to use.

In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

In the server crontab:

```cron
* * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Alternative with WP-CLI:

```cron
* * * * * cd /var/www/example-site && wp cron event run --due-now >/dev/null 2>&1
```

## Token-Protected Diagnostics

Detailed diagnostics are disabled unless a token is configured. Without a valid token, the public endpoint never returns versions, paths, or system metrics.

Set a token in `wp-config.php`:

```php
define( 'HEALTH_ENDPOINT_TOKEN', 'replace-with-a-long-random-secret' );
```

Or set it on the plugin admin page. The admin page also has a **Generate token** button. The constant takes precedence over the stored option.

Query diagnostics with the `X-Health-Token` header:

```bash
curl -s -H "X-Health-Token: SECRET" "https://example.com/health"
```

Query-string tokens also work, but headers are safer because URLs are often stored in access logs, proxy logs, CDN logs, and browser history.

```bash
curl -s "https://example.com/health?token=SECRET"
```

Example diagnostics payload:

```json
{
  "status": "ok",
  "db": "connected",
  "time": "2026-06-04T09:12:00+00:00",
  "detail": {
    "plugin_version": "2.1.3",
    "php_version": "8.4.0",
    "wp_version": "6.7",
    "object_cache": "external",
    "db_latency_ms": 2,
    "disk_used_pct": 41.0,
    "disk_free_mb": 18342,
    "cpu_pct": 38.0,
    "cpu_load_1m": 1.52,
    "cpu_cores": 4,
    "ram_used_pct": 63.0,
    "ram_avail_mb": 5921,
    "https": "yes",
    "memory_limit": "512M",
    "woocommerce": "9.4.2"
  }
}
```

## HTTP Status Codes

| Code | Meaning |
|---|---|
| `200` | WordPress and the database are reachable (`status: ok`) |
| `503` | Database connection failed during the endpoint request (`status: error`) |
| `500` | The database failed during WordPress bootstrap before plugins could load |

For uptime monitoring, accept only `200`. Treat `500` and `503` as down.

### Optional Bootstrap Database Failure Handling

If the database is already unavailable while WordPress boots, WordPress cannot load normal plugins. Instead, it loads `wp-content/db-error.php` when that drop-in exists.

Copy the included drop-in when you want the health endpoint to return consistent `503` JSON during bootstrap-level database failures:

```bash
cp db-error.php /var/www/example-site/wp-content/db-error.php
```

Normal visitors receive a simple "Service temporarily unavailable" page.

## Configuration

### Constants

| Constant | Purpose |
|---|---|
| `HEALTH_ENDPOINT_TOKEN` | Diagnostics token. Takes precedence over the admin setting. |
| `HEALTH_ENDPOINT_SLUG` | Pretty endpoint slug. Default: `health`. |
| `DISABLE_WP_CRON` | Disable WP-Cron when using real server cron. |

### Filters

| Filter | Purpose |
|---|---|
| `health_endpoint_payload` | Customize the full response payload. |
| `health_endpoint_detail` | Add custom diagnostics fields or checks. |
| `health_endpoint_status_code` | Override the HTTP status code. |
| `health_endpoint_token` | Provide the diagnostics token programmatically. |

Example:

```php
add_filter( 'health_endpoint_detail', function ( $detail ) {
	$detail['queue_pending'] = (int) get_option( 'my_queue_pending', 0 );
	return $detail;
} );
```

## Security

- The public endpoint only exposes `status`, `db`, and `time`.
- Diagnostics require a configured token and a valid provided token.
- Token comparison uses `hash_equals()`.
- Empty or whitespace-only tokens disable diagnostics.
- Prefer the `X-Health-Token` header over `?token=` in production.
- Rotate a token if it was ever placed in a URL, log file, ticket, chat, or screenshot.
- Use a long random token, for example `openssl rand -hex 24`.
- Never commit production tokens, credentials, customer names, internal hostnames, or real customer URLs to this repository.

## Caching Notes

Full-page caches can produce false positives for the pretty `/health` URL. A cache may serve an old `200 {"status":"ok"}` response before PHP and the plugin run.

For sites behind WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket, Varnish, Cloudflare, or a similar full-page cache:

- Monitor `?health_check=1` or the REST route instead of `/health`.
- Add `/health`, `/?health_check=1`, and `/wp-json/health/*` to the cache bypass list.
- Add equivalent bypass rules in reverse proxies or CDN configuration.

Without full-page caching, `/health` is fine.

## Translation

The plugin uses WordPress internationalization functions with the `health-endpoint` text domain. English is the source language. Translation files can be added under `languages/`.

## Public Repository Checklist

Before making a fork public, verify:

- The README and public descriptions use English.
- Examples use `example.com`, placeholders, or generic paths only.
- No production secrets, API keys, personal access tokens, passwords, private SSH keys, or customer data are committed.
- No real customer names, internal project names, private domains, or client-specific setup notes are committed.
- GitHub Actions do not print secrets.
- Release ZIPs exclude `.git`, `.github`, local build output, and development-only files.

## Changelog

### 2.1.3

- Renamed the WordPress-facing plugin name to ProjectMakers Health Endpoint for Plugin Directory naming compliance.
- Added a WordPress.org `readme.txt`.
- Added a GitHub Actions workflow for WordPress Plugin Check and made it fail on Plugin Check errors.
- Removed the custom GitHub self-updater and `Update URI` header for WordPress.org plugin compliance.

### 2.1.2

- Removed GitHub token settings.
- Added a configurable internal monitoring interval.
- Added last-check duration reporting in the admin live status panel.
- Added server-side diagnostics token generation.
- Updated the admin footer to the ProjectMakers "Made with love" style.

### 2.1.1

- Prepared the repository for public open-source release.
- Rewrote public documentation in English with generic examples.
- Added WordPress translation metadata and a POT template.
- Cleaned public-facing descriptions and security guidance.

### 2.1.0

- Added admin page with live status, endpoint list, and settings.
- Added internal cron monitoring for database, disk, CPU, and RAM.
- Added configurable thresholds, sustained checks, cooldown, and optional recovery emails.
- Added **Run check now** and **Send test email** actions.
- Added `format=plain` for minimal `OK` or `ERROR` monitor responses.
- Added GitHub release ZIP build workflow.
- Added admin-configurable diagnostics token while keeping the constant as the highest-priority source.
- Split implementation into `includes/` classes and added uninstall cleanup.

### 2.0.0

- Added REST route, query-string fallback, and token-protected diagnostics.
- Added fail-fast database checks, HEAD support, and optional `db-error.php` drop-in.

### 1.0.3

- Initial public `/health` endpoint with database connection check.
