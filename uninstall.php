<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete custom tables
$table_timeline = $wpdb->prefix . 'order_timeline';
$table_meta     = $wpdb->prefix . 'order_timeline_meta';
$table_presets  = $wpdb->prefix . 'order_timeline_presets';

$wpdb->query( "DROP TABLE IF EXISTS {$table_timeline}" );
$wpdb->query( "DROP TABLE IF EXISTS {$table_meta}" );
$wpdb->query( "DROP TABLE IF EXISTS {$table_presets}" );

// Delete plugin options
delete_option( 'wcotl_db_version' );
delete_option( 'wcotl_17track_api_key' );
delete_option( 'wcotl_sync_interval' );
delete_option( 'wcotl_inactivity_days' );

// Clear scheduled cron hook
$timestamp = wp_next_scheduled( 'wcotl_auto_sync' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'wcotl_auto_sync' );
}
