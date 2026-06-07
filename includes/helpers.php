<?php
/**
 * Stateless system-metric helpers. All return null when a metric is unavailable
 * on the host (restrictive open_basedir, non-Linux, disabled functions, etc.) so
 * callers can degrade gracefully.
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers_Health_Endpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database connectivity + round-trip latency.
 *
 * Fail-fast: core's reconnect loop (up to 5x sleep(1)) is disabled and errors
 * are suppressed so a dead DB cannot inject HTML into a JSON response.
 *
 * @return array{ok:bool,latency_ms:?int}
 */
function check_db() {
	global $wpdb;

	$ok      = false;
	$latency = null;

	if ( isset( $wpdb ) && is_object( $wpdb ) ) {
		$start = microtime( true );

		$prev_retries = null;
		if ( property_exists( $wpdb, 'reconnect_retries' ) ) {
			$prev_retries            = $wpdb->reconnect_retries;
			$wpdb->reconnect_retries = 0;
		}

		$prev_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$ok = (bool) $wpdb->check_connection( false );
		} else {
			$ok = true;
		}

		if ( $ok ) {
			$ok = ( '1' === (string) $wpdb->get_var( 'SELECT 1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( null !== $prev_suppress ) {
			$wpdb->suppress_errors( $prev_suppress );
		}
		if ( null !== $prev_retries ) {
			$wpdb->reconnect_retries = $prev_retries;
		}

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	return array(
		'ok'         => $ok,
		'latency_ms' => $latency,
	);
}

/**
 * Disk usage for the partition holding $path (defaults to ABSPATH).
 *
 * @param string|null $path Filesystem path to inspect.
 * @return array{free_mb:int,total_mb:int,used_pct:float}|null
 */
function disk_usage( $path = null ) {
	if ( ! function_exists( 'disk_free_space' ) || ! function_exists( 'disk_total_space' ) ) {
		return null;
	}

	if ( null === $path || '' === $path ) {
		$path = defined( 'ABSPATH' ) ? ABSPATH : '/';
	}

	$free  = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$total = @disk_total_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	if ( false === $free || false === $total || $total <= 0 ) {
		return null;
	}

	return array(
		'free_mb'  => (int) round( $free / 1048576 ),
		'total_mb' => (int) round( $total / 1048576 ),
		'used_pct' => round( ( 1 - ( $free / $total ) ) * 100, 1 ),
	);
}

/**
 * Number of CPU cores. Returns 0 when it genuinely cannot be determined, so
 * callers can degrade rather than over-report load as a percentage.
 *
 * @return int
 */
function cpu_cores() {
	static $cores = null;

	if ( null !== $cores ) {
		return $cores;
	}

	$cores = 0;

	if ( @is_readable( '/proc/cpuinfo' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$info = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
		if ( is_string( $info ) && '' !== $info ) {
			$cores = (int) preg_match_all( '/^processor\s*:/m', $info );
		}
	}

	if ( $cores < 1 && @is_readable( '/sys/devices/system/cpu/online' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$online = @file_get_contents( '/sys/devices/system/cpu/online' ); // phpcs:ignore
		if ( is_string( $online ) && preg_match( '/-(\d+)\s*$/', trim( $online ), $m ) ) {
			$cores = (int) $m[1] + 1;
		}
	}

	if ( $cores < 1 && function_exists( 'shell_exec' ) ) {
		$n = @shell_exec( 'nproc 2>/dev/null' ); // phpcs:ignore
		if ( is_string( $n ) && (int) $n > 0 ) {
			$cores = (int) $n;
		}
	}

	$cores = $cores > 0 ? (int) $cores : 0;

	return $cores;
}

/**
 * CPU load. `pct` is the 1-minute load average expressed as a percentage of the
 * available cores (load 1.0 per core == 100%). Returns null when load OR the core
 * count cannot be measured, so the monitor skips CPU instead of over-reporting.
 *
 * @return array{load_1m:float,cores:int,pct:float}|null
 */
function cpu_load() {
	if ( ! function_exists( 'sys_getloadavg' ) ) {
		return null;
	}

	$la = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	if ( ! is_array( $la ) || ! isset( $la[0] ) ) {
		return null;
	}

	$cores = cpu_cores();

	if ( $cores < 1 ) {
		return null; // Cannot reliably turn load into a percentage.
	}

	return array(
		'load_1m' => round( (float) $la[0], 2 ),
		'cores'   => $cores,
		'pct'     => round( ( (float) $la[0] / $cores ) * 100, 1 ),
	);
}

/**
 * System RAM usage from /proc/meminfo (Linux only).
 *
 * @return array{total_mb:int,avail_mb:int,used_pct:float}|null
 */
function ram_usage() {
	if ( ! @is_readable( '/proc/meminfo' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return null;
	}

	$data = @file_get_contents( '/proc/meminfo' ); // phpcs:ignore

	if ( ! is_string( $data ) || '' === $data ) {
		return null;
	}

	if ( ! preg_match( '/MemTotal:\s+(\d+)/', $data, $t ) ) {
		return null;
	}

	$total = (int) $t[1]; // kB.
	$avail = null;

	if ( preg_match( '/MemAvailable:\s+(\d+)/', $data, $a ) ) {
		$avail = (int) $a[1];
	} elseif ( preg_match( '/MemFree:\s+(\d+)/', $data, $f ) ) {
		$avail = (int) $f[1];
	}

	if ( $total <= 0 || null === $avail ) {
		return null;
	}

	return array(
		'total_mb' => (int) round( $total / 1024 ),
		'avail_mb' => (int) round( $avail / 1024 ),
		'used_pct' => round( ( 1 - ( $avail / $total ) ) * 100, 1 ),
	);
}
