<?php
/**
 * Uninstall cleanup: remove options and the scheduled monitoring event.
 *
 * @package HealthEndpoint
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'health_endpoint_settings' );
delete_option( 'health_endpoint_state' );

// Clear the monitoring cron event if still scheduled.
$health_endpoint_timestamp = wp_next_scheduled( 'health_endpoint_cron' );
while ( $health_endpoint_timestamp ) {
	wp_unschedule_event( $health_endpoint_timestamp, 'health_endpoint_cron' );
	$health_endpoint_timestamp = wp_next_scheduled( 'health_endpoint_cron' );
}
