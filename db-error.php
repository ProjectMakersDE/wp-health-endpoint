<?php
/**
 * OPTIONAL drop-in - copy to wp-content/db-error.php
 *
 * Why: when WordPress cannot reach the database at bootstrap, core calls dead_db()
 * and renders this file (if present) INSTEAD of dying with a generic HTTP 500 page -
 * and this happens BEFORE any plugin loads. Without it, the Health Endpoint plugin
 * never runs during a full DB outage, so the health URL would return WordPress's
 * own 500 error page rather than the plugin's 503 JSON.
 *
 * What this does: for health-check requests it returns the same 503 JSON the plugin
 * emits, so a DB outage is reported consistently to your monitor. Every other visitor
 * gets a minimal, friendly "service unavailable" page.
 *
 * This file is NOT loaded automatically from the plugin folder - copy it to
 * wp-content/db-error.php to activate it. It is plain PHP with no dependencies.
 *
 * @package HealthEndpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! headers_sent() ) {
	http_response_code( 503 );
	header( 'Status: 503 Service Unavailable' );
	header( 'Retry-After: 30' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
}

$health_endpoint_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
$health_endpoint_uri = is_string( $health_endpoint_uri ) ? $health_endpoint_uri : '';

$health_endpoint_is_health = (
	filter_has_var( INPUT_GET, 'health_check' )
	|| preg_match( '#/health/?(\?|$)#', $health_endpoint_uri )
	|| false !== strpos( $health_endpoint_uri, '/wp-json/health/' )
	|| false !== strpos( $health_endpoint_uri, 'rest_route=/health/' )
);

if ( $health_endpoint_is_health ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'status' => 'error',
			'db'     => 'down',
			'time'   => gmdate( 'c' ),
		)
	);
	die();
}

header( 'Content-Type: text/html; charset=utf-8' );
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex">
	<title>Service temporarily unavailable</title>
	<style>
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; max-width: 32rem; margin: 12vh auto; padding: 0 1.25rem; color: #222; }
		h1 { font-size: 1.4rem; }
		p { line-height: 1.5; color: #555; }
	</style>
</head>
<body>
	<h1>Service temporarily unavailable</h1>
	<p>The site is undergoing brief maintenance. Please try again in a few minutes.</p>
</body>
</html>
<?php
die();
