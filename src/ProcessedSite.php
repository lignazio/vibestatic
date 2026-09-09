<?php
/*
    ProcessedSite

    A processed version of a StaticSite, with URLs rewritten, folders renamed
    and other modifications made to prepare it for a Deployer
*/

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class ProcessedSite {

    public static function getPath() : string {
        $default = SiteInfo::getPath( 'uploads' ) . 'wp2static-processed-site';

        /*
         * A filter can return anything, and this method is declared to return a
         * string: a plugin filtering `wp2static_processed_site_path` and forgetting
         * the return — the commonest filter mistake there is — would make this
         * a TypeError, and the export would stop with a message about types
         * rather than about the filter.
         */
        $path = apply_filters( 'wp2static_processed_site_path', $default );

        if ( ! is_string( $path ) || '' === $path ) {
            WsLog::l(
                'A wp2static_processed_site_path filter returned ' . gettype( $path ) .
                ' instead of a path; using the default.'
            );

            return $default;
        }

        return $path;
    }

    /**
     * Add static file to ProcessedSite
     */
    public static function add( string $static_file, string $save_path ) : void {
        $full_path = self::getPath() . "/$save_path";

        $directory = dirname( $full_path );

        if ( ! is_dir( $directory ) ) {
            if ( ! wp_mkdir_p( $directory ) ) {
                WsLog::l( 'Couldn\t make directory: ' . $directory );
            }
        }

        copy( $static_file, $full_path );
    }

    /**
     * Delete processed site files
     */
    public static function delete() : void {
        WsLog::l( 'Deleting ProcessedSite files' );

        if ( is_dir( self::getPath() ) ) {
            FilesHelper::deleteDirWithFiles( self::getPath() );
        }
    }

    /**
     * Remove from the processed site the files post-processing did not rewrite.
     *
     * The list is the files just copied from the crawled site, so after this
     * call the two directories hold the same things — which is the assumption
     * `DeployCache::plan()` relies on when deciding what to unpublish.
     *
     * The filter protects whatever arrives in the directory from outside: an
     * add-on hooking `wp2static_post_process_complete` to add a `CNAME` or a
     * `_headers` rewrites it on every run and needs nothing, but one that
     * writes it once belongs here.
     *
     * @param string[] $expected_paths Paths that must stay.
     * @return string[] Paths removed.
     */
    public static function prune( array $expected_paths ) : array {
        /** @var string[] $expected_paths */
        $expected_paths = apply_filters(
            'wp2static_processed_site_paths_to_keep',
            $expected_paths
        );

        return FilesHelper::removePathsNotIn( self::getPath(), $expected_paths );
    }

    /**
     *  Get all paths in ProcessedSite
     *
     *  @return string[] ProcessedSite paths
     */
    public static function getPaths() : array {
        $processed_site_dir = self::getPath();

        if ( ! is_dir( $processed_site_dir ) ) {
            return [];
        }

        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $processed_site_dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $filename => $file_object ) {

            /**
             * @var string $filename
             */

            $base_name = basename( $filename );
            if ( $base_name != '.' && $base_name != '..' ) {
                $real_filepath = realpath( $filename );

                if ( is_string( $real_filepath ) ) {
                    $paths[] = str_replace( $processed_site_dir, '', $real_filepath );
                }
            }
        }

        sort( $paths );

        return $paths;
    }
}

