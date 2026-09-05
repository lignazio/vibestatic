<?php
/**
 * Uninstall: remove everything the plugin put in place.
 *
 * This runs without the plugin's autoloader, so its classes are not available
 * here: the paths are rebuilt with WordPress functions.
 *
 * @package WP2Static
 */

// exit uninstall if not called by WP
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

/** @var \wpdb $wpdb */
global $wpdb;

$tables_to_drop = [
    'wp2static_core_options',
    'wp2static_crawl_cache',
    'wp2static_deploy_cache',
    'wp2static_jobs',
    'wp2static_log',
    'wp2static_urls',
    // This was missing, and it is a core table: after uninstalling, the list
    // of registered add-ons and their enabled states stayed behind.
    'wp2static_addons',
    // No longer created: this held which Strattic notices the user had
    // dismissed. It stays in the list because it has to be removed from
    // installations that already have it.
    'wp2static_notices',
];

foreach ( $tables_to_drop as $table ) {
    $table_name = $wpdb->prefix . $table;

    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
}

delete_option( 'vibestatic_schema_version' );

/*
 * The directories under uploads. This was the "TODO: delete crawl_cache,
 * processed_site and zip if exist" the original authors left: without it, a
 * fifteen-hundred-page site left two complete copies on disk after uninstalling
 * the plugin that wrote them.
 *
 * The names are the ones StaticSite and ProcessedSite produce. Only what we
 * recognise is deleted, by exact name and under uploads: a recursive delete at
 * uninstall time is the worst possible place to be generous with paths.
 */
$uploads = wp_upload_dir();
$uploads_path = trailingslashit( $uploads['basedir'] );

/**
 * Recursively delete a directory.
 *
 * The plugin already has one, `FilesHelper::deleteDirWithFiles()`, and it is
 * deliberately not used here: it writes to the log — that is, to a table
 * dropped three lines above — and throws if the directory is not there. During
 * an uninstall both of those need to behave the other way round.
 *
 * @param string $path Absolute path.
 */
function wp2static_uninstall_rmdir( string $path ) : void {
    if ( ! is_dir( $path ) ) {
        return;
    }

    /** @var iterable<\SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir( $item->getPathname() );

            continue;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $item->getPathname() );
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir( $path );
}

foreach ( [ 'wp2static-crawled-site', 'wp2static-processed-site' ] as $directory ) {
    wp2static_uninstall_rmdir( $uploads_path . $directory );
}
