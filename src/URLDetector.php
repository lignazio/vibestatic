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

        if ( CoreOptions::getValue( 'detectRedirectionPluginURLs' ) ) {
            $arrays_to_merge[] = DetectRedirectionPluginURLs::detect( SiteInfo::getUrl( 'site' ) );
        }

        /*
         * Paths named by hand, and they belong *here* rather than being pushed
         * into the queue somewhere later. `pruneCrawlQueue()` removes anything
         * detection does not name, so a path added after this point would be
         * crawled once and dropped on the next run. Being part of detection is
         * what makes it stay.
         */
        $arrays_to_merge = [ $url_queue, self::additionalPaths() ];

        /** @var string[] $url_queue */
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
     * The paths the user listed by hand, one per line.
     *
     * For what nothing enumerates and nothing links to: a file dropped on the
     * server, a route a plugin answers without registering a post, an address
     * that has to stay published for a while longer.
     *
     * @return string[]
     */
    public static function additionalPaths() : array {
        $configured = CoreOptions::getLineDelimitedBlobValue( 'additionalPathsToCrawl' );

        $site_url = untrailingslashit( SiteInfo::getUrl( 'site' ) );
        $paths = [];

        foreach ( $configured as $line ) {
            $path = URLHelper::pathOnThisSite( $line, $site_url );

            if ( null === $path ) {
                continue;
            }

            $paths[] = $site_url . $path;
        }

        return $paths;
    }

    /**
     * The queued URLs that detection no longer names.
     *
     * The comparison goes through `rawurldecode()` because the two lists are
     * not in the same shape: `CrawlQueueRepository::addUrls()` stores the
     * decoded URL in the `url` column, while detection produces them encoded.
     * Comparing them as they are would read every URL containing a space or an
     * accent as "gone" — that is, every file uploaded under a non-ASCII name.
     *
     * @param array<int,string> $queued   Queue rows, id => URL.
     * @param string[]          $detected URLs just detected.
     * @return array<int,string> id => URL to forget.
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
     * Reconcile the queue with what detection has just seen.
     *
     * The queue used to be additive only — "No longer truncate before adding",
     * said the comment — and a URL that stopped being detected stayed in it
     * forever: recrawled, reprocessed and republished on every run, long after
     * the reason it existed had gone. This is the piece that was missing for a
     * site to be able to shrink.
     *
     * Additive was not a whim, though: the queue used to be emptied and
     * refilled, and between those two moments a crawl would have seen an empty
     * site. Nothing is emptied here — rows are removed one by one, and only the
     * ones detection did not name.
     *
     * The CrawlCache row goes with it, and that is not an extra: if the URL came
     * back with the same content, the hash still in cache would skip writing the
     * file, and the URL would stay detected, crawled, and absent from the
     * deploy — a worse hole than the one being closed.
     *
     * @param string[] $detected URLs just detected.
     * @return int How many URLs were forgotten.
     */
    public static function pruneCrawlQueue( array $detected ) : int {
        if ( ! FilesHelper::pruningEnabled() ) {
            return 0;
        }

        if ( ! $detected ) {
            // A detection run that finds nothing is not an empty site: it is
            // a detection run that went wrong.
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
         * By id, not by URL: `CrawlQueue::rmUrl()` looks up `md5($url)` against
         * the `hashed_url` column, which holds the md5 of the *encoded* URL
         * while the `url` column holds the decoded one. For URLs with characters
         * that need encoding the two do not match and the row would not go. The
         * id does not have that problem.
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

        // Remove the ones detection no longer names. After the add and not
        // before: between the two the queue is never empty, so a crawl starting
        // in between would never see an empty site.
        static::pruneCrawlQueue( $unique_urls );

        return (string) count( $unique_urls );
    }
}

