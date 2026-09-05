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
        return apply_filters(
            'wp2static_crawled_site_path',
            SiteInfo::getPath( 'uploads' ) . 'wp2static-crawled-site'
        );
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
     * Toglie dal sito crawlato i file che non hanno piu` un URL che li spieghi.
     *
     * L'elenco arriva da chi chiama e non se lo costruisce da se`: e` il
     * Crawler a sapere quando la domanda «sono questi tutti gli URL che ci
     * sono?» ha una risposta, e questa classe resta il salvataggio su file che
     * e` sempre stata.
     *
     * Il filtro serve a chi scrive nella cartella senza passare dalla coda —
     * un addon che ci deposita un file suo dopo il crawl. Senza, quel file
     * sarebbe un orfano e sparirebbe al giro dopo.
     *
     * @param string[] $expected_paths Percorsi che devono restare.
     * @return string[] Percorsi rimossi.
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

