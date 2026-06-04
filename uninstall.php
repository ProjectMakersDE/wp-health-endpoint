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

// Drop any cached GitHub release lookups (key is hashed per repo).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_health\\_endpoint\\_update\\_%' OR option_name LIKE '\\_transient\\_timeout\\_health\\_endpoint\\_update\\_%'" );

// Clear the monitoring cron event if still scheduled.
$timestamp = wp_next_scheduled( 'health_endpoint_cron' );
while ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'health_endpoint_cron' );
	$timestamp = wp_next_scheduled( 'health_endpoint_cron' );
}
