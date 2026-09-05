<?php
/*
    URLDetector

    Detects URLs from WordPress DB, filesystem and user input

    Users can control detection levels

    Saves URLs to CrawlQueue

*/

namespace WP2Static;

class URLDetector {

    public static function countURLs() : int {
        return count( static::detectURLs( $quiet = true ) );
    }

    /**
     * Detect URLs within site
     *
     * @return array<string>
     */
    public static function detectURLs( bool $quiet = false ) : array {
        if ( ! $quiet ) {
            WsLog::l( 'Starting to detect WordPress site URLs.' );
        }

        do_action(
            'wp2static_detect'
        );

        $arrays_to_merge = [];

        // TODO: detect robots.txt, etc before adding
        $arrays_to_merge[] = [
            '/',
            '/robots.txt',
            '/favicon.ico',
            '/sitemap.xml',
        ];

        /*
            TODO: reimplement detection for URLs:
                'detectCommentPagination',
                'detectComments',
                'detectFeedURLs',

        // other options:

         - robots
         - favicon
         - sitemaps

        */

        if ( CoreOptions::getValue( 'detectPosts' ) ) {
            $arrays_to_merge[] = DetectPostURLs::detect();
        }

        if ( CoreOptions::getValue( 'detectPages' ) ) {
            $arrays_to_merge[] = DetectPageURLs::detect();
        }

        if ( CoreOptions::getValue( 'detectCustomPostTypes' ) ) {
            $arrays_to_merge[] = DetectCustomPostTypeURLs::detect();
        }

        if ( CoreOptions::getValue( 'detectUploads' ) ) {
            $filenames_to_ignore = CoreOptions::getLineDelimitedBlobValue( 'filenamesToIgnore' );

            $filenames_to_ignore =
                apply_filters(
                    'wp2static_filenames_to_ignore',
                    $filenames_to_ignore
                );

            $file_extensions_to_ignore = CoreOptions::getLineDelimitedBlobValue(
                'fileExtensionsToIgnore'
            );

            $file_extensions_to_ignore =
                apply_filters(
                    'wp2static_file_extensions_to_ignore',
                    $file_extensions_to_ignore
                );

            $arrays_to_merge[] =
                FilesHelper::getListOfLocalFilesByDir(
                    SiteInfo::getPath( 'uploads' ),
                    $filenames_to_ignore,
                    $file_extensions_to_ignore
                );
        }

        $detect_sitemaps = apply_filters( 'wp2static_detect_sitemaps', 1 );

        if ( $detect_sitemaps ) {
            $arrays_to_merge[] = DetectSitemapsURLs::detect( SiteInfo::getURL( 'site' ) );
        }

        $detect_parent_theme = apply_filters( 'wp2static_detect_parent_theme', 1 );

        if ( $detect_parent_theme ) {
            $arrays_to_merge[] = DetectThemeAssets::detect( 'parent' );
        }

        $detect_child_theme = apply_filters( 'wp2static_detect_child_theme', 1 );

        if ( $detect_child_theme ) {
            $arrays_to_merge[] = DetectThemeAssets::detect( 'child' );
        }

        $detect_plugin_assets = apply_filters( 'wp2static_detect_plugin_assets', 1 );

        if ( $detect_plugin_assets ) {
            $arrays_to_merge[] = DetectPluginAssets::detect();
        }

        $detect_wpinc_assets = apply_filters( 'wp2static_detect_wpinc_assets', 1 );

        if ( $detect_wpinc_assets ) {
            $arrays_to_merge[] = DetectWPIncludesAssets::detect();
        }

        $detect_vendor_cache = apply_filters( 'wp2static_detect_vendor_cache', 1 );

        if ( $detect_vendor_cache ) {
            $arrays_to_merge[] = DetectVendorFiles::detect( SiteInfo::getURL( 'site' ) );
        }

        $detect_posts_pagination = apply_filters( 'wp2static_detect_posts_pagination', 1 );

        if ( $detect_posts_pagination ) {
            $arrays_to_merge[] = DetectPostsPaginationURLs::detect( SiteInfo::getURL( 'site' ) );
        }

        $detect_archives = apply_filters( 'wp2static_detect_archives', 1 );

        if ( $detect_archives ) {
            $arrays_to_merge[] = DetectArchiveURLs::detect();
        }

        $detect_categories = apply_filters( 'wp2static_detect_categories', 1 );

        if ( $detect_categories ) {
            $arrays_to_merge[] = DetectCategoryURLs::detect();
        }

        $detect_category_pagination = apply_filters( 'wp2static_detect_category_pagination', 1 );

        if ( $detect_category_pagination ) {
            $arrays_to_merge[] = DetectCategoryPaginationURLs::detect();
        }

        $detect_authors = apply_filters( 'wp2static_detect_authors', 1 );

        if ( $detect_authors ) {
            $arrays_to_merge[] = DetectAuthorsURLs::detect();
        }

        $detect_authors_pagination = apply_filters( 'wp2static_detect_authors_pagination', 1 );

        if ( $detect_authors_pagination ) {
            $arrays_to_merge[] = DetectAuthorPaginationURLs::detect( SiteInfo::getUrl( 'site' ) );
        }

        /**
         * @var string[] $url_queue
         */
        $url_queue = call_user_func_array( 'array_merge', $arrays_to_merge );

        $url_queue = FilesHelper::cleanDetectedURLs( $url_queue );

        $url_queue = apply_filters(
            'wp2static_modify_initial_crawl_list',
            $url_queue
        );

        $unique_urls = array_unique( $url_queue );

        $total_detected = (string) count( $unique_urls );

        if ( ! $quiet ) {
            WsLog::l(
                "Detection complete. $total_detected URLs added to Crawl Queue."
            );
        }

        return $unique_urls;
    }

    /**
     * Gli URL in coda che la rilevazione non nomina piu`.
     *
     * Il confronto passa da `rawurldecode()` perche` le due liste non hanno la
     * stessa forma: `CrawlQueueRepository::addUrls()` salva l'URL decodificato
     * nella colonna `url`, mentre la rilevazione li produce codificati.
     * Confrontarli cosi` come sono farebbe leggere come «sparito» ogni URL con
     * uno spazio o un accento — cioe` ogni file caricato con un nome italiano.
     *
     * @param array<int,string> $queued   Righe della coda, id => URL.
     * @param string[]          $detected URL appena rilevati.
     * @return array<int,string> id => URL da dimenticare.
     */
    public static function staleQueueEntries( array $queued, array $detected ) : array {
        $known = [];

        foreach ( $detected as $url ) {
            $known[ rawurldecode( $url ) ] = true;
        }

        $stale = [];

        foreach ( $queued as $id => $url ) {
            if ( ! isset( $known[ $url ] ) ) {
                $stale[ $id ] = $url;
            }
        }

        return $stale;
    }

    /**
     * Allinea la coda a quello che la rilevazione ha appena visto.
     *
     * Prima la coda era solo additiva — «No longer truncate before adding»,
     * diceva il commento — e un URL che smetteva di essere rilevato ci restava
     * per sempre: veniva ricrawlato, riprocessato e ripubblicato a ogni giro,
     * anche quando la ragione per cui esisteva non c'era piu`. E` questo il
     * pezzo che mancava perche` un sito possa rimpicciolire.
     *
     * Additiva pero` non era un capriccio: la coda si svuotava e si riempiva,
     * e fra i due momenti un crawl avrebbe visto un sito vuoto. Qui non si
     * svuota niente — si toglie riga per riga, e solo quelle che la rilevazione
     * non ha nominato.
     *
     * La riga della CrawlCache se ne va insieme, e non e` un di piu`: se l'URL
     * tornasse con lo stesso contenuto, l'hash ancora in cache farebbe saltare
     * la scrittura del file e l'URL resterebbe rilevato, crawlato e assente
     * dal deploy — un buco peggiore di quello che si sta chiudendo.
     *
     * @param string[] $detected URL appena rilevati.
     * @return int Quanti URL sono stati dimenticati.
     */
    public static function pruneCrawlQueue( array $detected ) : int {
        if ( ! FilesHelper::pruningEnabled() ) {
            return 0;
        }

        if ( ! $detected ) {
            // Una rilevazione che non trova niente non e` un sito vuoto: e`
            // una rilevazione andata male.
            return 0;
        }

        $queued = CrawlQueue::getCrawlablePaths();
        $stale = self::staleQueueEntries( $queued, $detected );

        if ( ! $stale ) {
            return 0;
        }

        if ( ! FilesHelper::shrinkIsPlausible( count( $stale ), count( $queued ) ) ) {
            WsLog::l(
                sprintf(
                    'Detection claims %d of %d queued URLs are gone. That is more than ' .
                    'this step will remove on its own, so nothing was removed: it looks ' .
                    'more like a failed detector than a smaller site. If the site really ' .
                    'did shrink that much, use Delete All Caches and run a full workflow.',
                    count( $stale ),
                    count( $queued )
                )
            );

            return 0;
        }

        /*
         * Per id, non per URL: `CrawlQueue::rmUrl()` cerca `md5($url)` sulla
         * colonna `hashed_url`, che pero` contiene l'md5 dell'URL *codificato*
         * mentre la colonna `url` tiene quello decodificato. Sugli URL con
         * caratteri da codificare i due non coincidono e la riga non se ne
         * andrebbe. L'id non ha questo problema.
         */
        CrawlQueue::rmUrlsById( array_map( 'strval', array_keys( $stale ) ) );
        CrawlCache::rmUrls( array_values( $stale ) );

        WsLog::l(
            sprintf(
                'Pruned Crawl Queue: %d URL(s) no longer detected.',
                count( $stale )
            )
        );

        return count( $stale );
    }

    public static function enqueueURLs() : string {
        $unique_urls = static::detectURLs();

        // addUrls does an INSERT IGNORE on the URL hash, so re-adding a URL
        // that is already queued is free and does not error on duplicates.
        CrawlQueue::addUrls( $unique_urls );

        // Toglie quelle che la rilevazione non nomina piu`. Dopo l'aggiunta e
        // non prima: fra le due la coda non e` mai vuota, quindi un crawl che
        // partisse in mezzo non vedrebbe mai un sito vuoto.
        static::pruneCrawlQueue( $unique_urls );

        return (string) count( $unique_urls );
    }
}

