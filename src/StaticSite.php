<?php
/*
    StaticSite

    The resulting output of crawling the WordPress site

    Site URLs are all made absolute for easier rewriting during deployment
*/

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class StaticSite {

    /**
     * Add crawled resource to static site
     */
    public static function add( string $path, string $contents ) : void {
        // simple file save, Crawler holds logic for what/where to save
        // Crawler has already processed links, etc
        $full_path = self::getPath() . "$path";

        $directory = dirname( $full_path );

        if ( ! is_dir( $directory ) ) {
            if ( ! wp_mkdir_p( $directory ) ) {
                WsLog::l( 'Couldn\t make directory: ' . $directory );
            }
        }

        file_put_contents( $full_path, $contents );
    }

    public static function getPath() : string {
        $default = SiteInfo::getPath( 'uploads' ) . 'wp2static-crawled-site';

        /*
         * A filter can return anything, and this method is declared to return a
         * string: a plugin filtering `wp2static_crawled_site_path` and forgetting
         * the return — the commonest filter mistake there is — would make this
         * a TypeError, and the export would stop with a message about types
         * rather than about the filter.
         */
        $path = apply_filters( 'wp2static_crawled_site_path', $default );

        if ( ! is_string( $path ) || '' === $path ) {
            WsLog::l(
                'A wp2static_crawled_site_path filter returned ' . gettype( $path ) .
                ' instead of a path; using the default.'
            );

            return $default;
        }

        return $path;
    }

    /**
     * Delete StaticSite files
     */
    public static function delete() : void {
        WsLog::l( 'Deleting StaticSite files' );

        if ( is_dir( self::getPath() ) ) {
            FilesHelper::deleteDirWithFiles( self::getPath() );

            // CrawlCache not useful without StaticSite files
            CrawlCache::truncate();
        }
    }

    /**
     * Remove from the crawled site the files that no longer have a URL to
     * explain them.
     *
     * The list comes from the caller and is not built here: it is the Crawler
     * that knows when the question "are these all the URLs there are?" has an
     * answer, and this class stays the file-saving layer it has always been.
     *
     * The filter is for anyone writing into the directory without going through
     * the queue — an add-on dropping a file of its own there after the crawl.
     * Without it, that file would be an orphan and would vanish on the next run.
     *
     * @param string[] $expected_paths Paths that must stay.
     * @return string[] Paths removed.
     */
    public static function prune( array $expected_paths ) : array {
        /** @var string[] $expected_paths */
        $expected_paths = apply_filters(
            'wp2static_crawled_site_paths_to_keep',
            $expected_paths
        );

        return FilesHelper::removePathsNotIn( self::getPath(), $expected_paths );
    }

    /**
     *  Get all paths in StaticSite
     *
     *  @return string[] StaticSite paths
     */
    public static function getPaths() : array {
        $static_site_dir = self::getPath();

        if ( ! is_dir( $static_site_dir ) ) {
            return [];
        }

        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $static_site_dir,
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
                    $paths[] = str_replace( $static_site_dir, '', $real_filepath );
                }
            }
        }

        sort( $paths );

        return $paths;
    }
}

