<?php
/**
 * Uninstall: drop the add-on's options table.
 *
 * @package WP2StaticSFTP
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/** @var \wpdb $wpdb */
global $wpdb;

// %i for the identifier: the table name does not come from outside, but
// interpolating it by hand is the habit the core's SQL injection grew out of.
$wpdb->query(
    $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp2static_addon_sftp_options' )
);
