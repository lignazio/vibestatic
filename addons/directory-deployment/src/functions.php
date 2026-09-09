<?php
/**
 * The add-on's two filesystem functions.
 *
 * They used to sit at the top of Deployer.php, below the namespace declaration
 * but outside the class. Here they have a file of their own, which the
 * autoloader always loads: functions are not autoloaded by name the way classes
 * are.
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

/*
 * A file that runs something when it is included has to be able to refuse being
 * included on its own. This one does — a define(), a function declaration, an
 * spl_autoload_register() — and a file that merely declares a class does not,
 * which is why the guard is here and not in front of all sixty of them.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Delete a directory and everything in it.
 *
 * @param string $path Absolute path.
 */
function rrmdir( string $path ) : void {
    if ( '' === trim( pathinfo( $path, PATHINFO_BASENAME ), '.' ) ) {
        return;
    }

    if ( ! is_dir( $path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink( $path );

        return;
    }

    $entries = glob( $path . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE | GLOB_NOSORT );

    // The function name has to be qualified: without __NAMESPACE__, array_map
    // looks for a global `rrmdir` that does not exist, and the deploy dies
    // exactly while emptying the destination.
    array_map( __NAMESPACE__ . '\\rrmdir', $entries ? $entries : [] );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    @rmdir( $path );
}

/**
 * Recursively copy a directory.
 *
 * @param string $source      Source directory.
 * @param string $destination Destination directory.
 * @param int    $permissions Permissions for directories created.
 */
function xcopy( string $source, string $destination, int $permissions = 0755 ) : bool {
    if ( is_link( $source ) ) {
        return symlink( (string) readlink( $source ), $destination );
    }

    if ( is_file( $source ) ) {
        return copy( $source, $destination );
    }

    if ( ! is_dir( $source ) ) {
        return false;
    }

    /*
     * Copying a directory into itself is infinite recursion. The original got
     * there by computing the md5 of the whole tree for every entry — that is,
     * re-reading every file once per file — and comparing it with the current
     * directory's. The right comparison is between the two paths, and it costs
     * nothing.
     */
    $real_source = realpath( $source );
    $real_destination = realpath( $destination );

    if ( $real_source && $real_destination && 0 === strpos( $real_destination, $real_source ) ) {
        \WP2Static\WsLog::l( "Refusing to copy $source into itself" );

        return false;
    }

    if ( ! is_dir( $destination ) && ! mkdir( $destination, $permissions, true )
        && ! is_dir( $destination ) ) {
        return false;
    }

    $entries = scandir( $source );

    foreach ( $entries ? $entries : [] as $entry ) {
        if ( '.' === $entry || '..' === $entry ) {
            continue;
        }

        xcopy( "$source/$entry", "$destination/$entry", $permissions );
    }

    return true;
}
