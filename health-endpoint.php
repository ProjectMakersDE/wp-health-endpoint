<?php
/**
 * Plugin Name: Health Endpoint
 * Plugin URI:  https://github.com/ProjectMakersDE/wp-health-endpoint
 * Description: Lightweight public health/uptime endpoint for WordPress with an optional token-protected diagnostics mode (DB latency, object cache, disk, PHP/WP versions). Built for Uptime Kuma & co.
 * Version:     2.0.0
 * Author:      ProjectMakers
 * Author URI:  https://projectmakers.de
 * License:     GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2
 *
 * Public endpoints (all return JSON):
 *   GET /health                  -> pretty permalink (needs permalinks enabled)
 *   GET /?health_check=1         -> query fallback (works without permalinks)
 *   GET /wp-json/health/v1/check -> REST route (works with plain permalinks)
 *
 * Token-protected diagnostics (optional). Add to wp-config.php:
 *   define( 'HEALTH_ENDPOINT_TOKEN', 'a-long-random-secret' );
 * then call any endpoint with  ?token=a-long-random-secret  or header  X-Health-Token: ...
 *
 * @package HealthEndpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HEALTH_ENDPOINT_VERSION', '2.0.0' );

/**
 * Resolve the rewrite slug for the pretty endpoint (default "health").
 * Override in wp-config.php with: define( 'HEALTH_ENDPOINT_SLUG', 'status' );
 *
 * @return string
 */
function health_endpoint_slug() {
	$slug = defined( 'HEALTH_ENDPOINT_SLUG' ) ? (string) HEALTH_ENDPOINT_SLUG : 'health';
	$slug = trim( $slug, '/' );

	return '' !== $slug ? $slug : 'health';
}

/**
 * Register the custom query var used by the query-string fallback.
 *
 * @param array $vars Registered public query vars.
 * @return array
 */
function health_endpoint_query_vars( $vars ) {
	$vars[] = 'health_check';

	return $vars;
}
add_filter( 'query_vars', 'health_endpoint_query_vars' );

/**
 * Register the pretty rewrite rule for /health.
 */
function health_endpoint_rewrite_rules() {
	add_rewrite_rule( '^' . health_endpoint_slug() . '/?$', 'index.php?health_check=1', 'top' );
}
add_action( 'init', 'health_endpoint_rewrite_rules' );

/**
 * Register the permalink-independent REST route.
 */
function health_endpoint_register_rest() {
	register_rest_route(
		'health/v1',
		'/check',
		array(
			'methods'             => WP_REST_Server::READABLE, // GET (HEAD handled automatically).
			'permission_callback' => '__return_true',
			'callback'            => 'health_endpoint_rest_callback',
		)
	);
}
add_action( 'rest_api_init', 'health_endpoint_register_rest' );

/**
 * REST callback.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function health_endpoint_rest_callback( $request ) {
	nocache_headers();

	$detailed = health_endpoint_token_ok( $request->get_param( 'token' ) );

	list( $payload, $code ) = health_endpoint_build_payload( $detailed );

	return new WP_REST_Response( $payload, $code );
}

/**
 * Constant-time verification of the diagnostics token.
 *
 * Detailed mode is disabled entirely unless HEALTH_ENDPOINT_TOKEN (or the
 * `health_endpoint_token` filter) provides a non-empty secret.
 *
 * @param string|null $provided Token supplied via query/body, if any.
 * @return bool
 */
function health_endpoint_token_ok( $provided = null ) {
	$configured = defined( 'HEALTH_ENDPOINT_TOKEN' ) ? (string) HEALTH_ENDPOINT_TOKEN : '';
	$configured = (string) apply_filters( 'health_endpoint_token', $configured );

	// A blank or whitespace-only secret counts as "not configured" (fail closed).
	if ( '' === trim( $configured ) ) {
		return false;
	}

	if ( null === $provided || '' === $provided ) {
		$provided = isset( $_SERVER['HTTP_X_HEALTH_TOKEN'] ) ? wp_unslash( $_SERVER['HTTP_X_HEALTH_TOKEN'] ) : '';
	}

	$provided = (string) $provided;

	if ( '' === $provided ) {
		return false;
	}

	return hash_equals( $configured, $provided );
}

/**
 * Run the checks and build the JSON payload plus the HTTP status code.
 *
 * Public health (and therefore the HTTP status) is decided solely by database
 * connectivity, so the uptime signal stays predictable. Detailed checks are
 * informational and surfaced under `detail.warnings`.
 *
 * @param bool $detailed Whether to include token-only diagnostics.
 * @return array{0:array,1:int} [ payload, http_status ]
 */
function health_endpoint_build_payload( $detailed = false ) {
	global $wpdb;

	$db_ok         = false;
	$db_latency_ms = null;

	if ( isset( $wpdb ) && is_object( $wpdb ) ) {
		$start = microtime( true );

		// Fail fast: stop core's reconnect loop (up to 5x sleep(1) ≈ 5s) from
		// blocking the worker during the exact outage we are trying to detect.
		$prev_retries = null;
		if ( property_exists( $wpdb, 'reconnect_retries' ) ) {
			$prev_retries            = $wpdb->reconnect_retries;
			$wpdb->reconnect_retries = 0;
		}

		// Suppress errors so a failing query cannot echo wpdb's HTML error
		// markup into our JSON response when the DB is down.
		$prev_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$db_ok = (bool) $wpdb->check_connection( false ); // false = do not bail/die on failure.
		} else {
			$db_ok = true; // Older WP: verified by the query below.
		}

		if ( $db_ok ) {
			$db_ok = ( '1' === (string) $wpdb->get_var( 'SELECT 1' ) );
		}

		if ( null !== $prev_suppress ) {
			$wpdb->suppress_errors( $prev_suppress );
		}
		if ( null !== $prev_retries ) {
			$wpdb->reconnect_retries = $prev_retries;
		}

		$db_latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	$healthy = $db_ok;

	$payload = array(
		'status' => $healthy ? 'ok' : 'error',
		'db'     => $db_ok ? 'connected' : 'down',
		'time'   => gmdate( 'c' ),
	);

	if ( $detailed ) {
		$payload['detail'] = health_endpoint_detail( $db_latency_ms );
	}

	/** Filter the full response payload before it is sent. */
	$payload = apply_filters( 'health_endpoint_payload', $payload, $detailed, $healthy );

	$code = $healthy ? 200 : 503;

	/** Filter the HTTP status code (e.g. to force 200 for a keyword-only monitor). */
	$code = (int) apply_filters( 'health_endpoint_status_code', $code, $healthy, $payload );

	return array( $payload, $code );
}

/**
 * Gather extended diagnostics. Only exposed in token-protected mode.
 *
 * @param int|null $db_latency_ms Measured DB round-trip time.
 * @return array
 */
function health_endpoint_detail( $db_latency_ms ) {
	$warnings = array();

	// Disk space on the uploads directory.
	$disk_free_mb = null;
	$uploads      = wp_get_upload_dir();

	if ( ! empty( $uploads['basedir'] ) && function_exists( 'disk_free_space' ) ) {
		$free = @disk_free_space( $uploads['basedir'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false !== $free ) {
			$disk_free_mb = (int) round( $free / 1048576 );

			if ( $disk_free_mb < 256 ) {
				$warnings[] = 'disk_low';
			}
		}
	}

	// 1-minute load average (Linux only).
	$load_1m = null;

	if ( function_exists( 'sys_getloadavg' ) ) {
		$la = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_array( $la ) && isset( $la[0] ) ) {
			$load_1m = round( (float) $la[0], 2 );
		}
	}

	$detail = array(
		'plugin_version' => HEALTH_ENDPOINT_VERSION,
		'php_version'    => PHP_VERSION,
		'wp_version'     => get_bloginfo( 'version' ),
		'object_cache'   => wp_using_ext_object_cache() ? 'external' : 'internal',
		'db_latency_ms'  => $db_latency_ms,
		'disk_free_mb'   => $disk_free_mb,
		'load_1m'        => $load_1m,
		'https'          => is_ssl() ? 'yes' : 'no',
		'memory_limit'   => ini_get( 'memory_limit' ),
		'server_time'    => gmdate( 'c' ),
	);

	if ( function_exists( 'WC' ) ) {
		$detail['woocommerce'] = defined( 'WC_VERSION' ) ? WC_VERSION : 'active';
	}

	$detail['warnings'] = $warnings;

	/** Filter the diagnostics block (add custom checks here). */
	return apply_filters( 'health_endpoint_detail', $detail );
}

/**
 * Emit the response for the pretty rule and the query-string fallback.
 */
function health_endpoint_render() {
	$is_health_request = get_query_var( 'health_check' );

	if ( '1' !== $is_health_request && 'true' !== $is_health_request ) {
		return;
	}

	// Discourage full-page caching plugins from *storing* this response. Note this
	// cannot stop an already-cached /health page from being served (advanced-cache.php
	// runs before plugins) — see README "Caching-Hinweise"; prefer the query/REST URL.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	$token    = isset( $_GET['token'] ) ? wp_unslash( $_GET['token'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification
	$detailed = health_endpoint_token_ok( $token );

	list( $payload, $code ) = health_endpoint_build_payload( $detailed );

	status_header( $code );
	header( 'Content-Type: application/json; charset=utf-8' );

	// HEAD requests: status + headers only.
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		exit;
	}

	echo wp_json_encode( $payload );
	exit;
}
add_action( 'template_redirect', 'health_endpoint_render' );

/**
 * Flush rewrite rules on activation so /health works immediately.
 */
function health_endpoint_activate() {
	health_endpoint_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'health_endpoint_activate' );

/**
 * Clean up rewrite rules on deactivation.
 */
function health_endpoint_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'health_endpoint_deactivate' );
