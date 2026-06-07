<?php
/**
 * Plugin Name: Health Endpoint
 * Plugin URI:  https://github.com/ProjectMakersDE/wp-health-endpoint
 * Description: Lightweight public health/uptime endpoint plus internal server monitoring (DB, disk, CPU, RAM) with email alerts, token-protected diagnostics, and GitHub-based auto-updates.
 * Version:     2.1.1
 * Author:      ProjectMakers
 * Author URI:  https://projectmakers.de
 * License:     GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: health-endpoint
 * Domain Path: /languages
 * Requires PHP: 7.2
 * Requires at least: 5.3
 * Update URI:  https://github.com/ProjectMakersDE/wp-health-endpoint
 *
 * Public endpoints (all return JSON unless noted):
 *   GET /health                  -> pretty permalink (needs permalinks enabled)
 *   GET /?health_check=1         -> query fallback (works without permalinks)
 *   GET /wp-json/health/v1/check -> REST route (works with plain permalinks)
 *   add &format=plain for a bare "OK" / "ERROR" body (ultra-terse monitors)
 *
 * Token-protected diagnostics (optional): define( 'HEALTH_ENDPOINT_TOKEN', '...' )
 * in wp-config.php (or set it on the admin page), then pass ?token=... or header
 * X-Health-Token: ...
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers\HealthEndpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HEALTH_ENDPOINT_VERSION', '2.1.1' );
define( 'HEALTH_ENDPOINT_FILE', __FILE__ );
define( 'HEALTH_ENDPOINT_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEALTH_ENDPOINT_URL', plugin_dir_url( __FILE__ ) );
define( 'HEALTH_ENDPOINT_BASENAME', plugin_basename( __FILE__ ) );

// GitHub repository used by the self-updater (owner/repo). Override in wp-config.php.
if ( ! defined( 'HEALTH_ENDPOINT_GITHUB' ) ) {
	define( 'HEALTH_ENDPOINT_GITHUB', 'ProjectMakersDE/wp-health-endpoint' );
}

require_once HEALTH_ENDPOINT_DIR . 'includes/helpers.php';
require_once HEALTH_ENDPOINT_DIR . 'includes/class-settings.php';
require_once HEALTH_ENDPOINT_DIR . 'includes/class-endpoint.php';
require_once HEALTH_ENDPOINT_DIR . 'includes/class-monitor.php';
require_once HEALTH_ENDPOINT_DIR . 'includes/class-updater.php';

/**
 * Load translation files from the plugin languages directory.
 */
function load_textdomain() {
	load_plugin_textdomain( 'health-endpoint', false, dirname( HEALTH_ENDPOINT_BASENAME ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Wire everything up once WordPress is loaded.
 */
function bootstrap() {
	Endpoint::instance();
	Monitor::instance();

	// Register the updater on every request: WP-Cron and WP-CLI (where is_admin()
	// is false) drive the background update checks. Its filters self-gate by
	// slug/URL and the GitHub lookup is transient-cached, so this is cheap.
	new Updater( HEALTH_ENDPOINT_FILE, HEALTH_ENDPOINT_GITHUB );

	if ( is_admin() ) {
		Settings::instance();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Activation: register rewrite rule, flush, and (re)schedule monitoring cron.
 */
function activate() {
	Endpoint::instance()->register_rewrite_rules();
	flush_rewrite_rules();
	Monitor::instance()->maybe_schedule();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: clean up rewrite rules and the scheduled cron.
 */
function deactivate() {
	flush_rewrite_rules();
	Monitor::unschedule();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
