<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! defined( 'FAT_REMOVE_DATA_ON_UNINSTALL' ) || ! FAT_REMOVE_DATA_ON_UNINSTALL ) {
    return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fat_tools" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fat_runs" );

delete_option( 'fat_settings' );
delete_option( 'fat_db_version' );
