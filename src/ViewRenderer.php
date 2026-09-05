<?php

namespace WP2Static;

class ViewRenderer {

    /**
     * Handles bulk removal on the Crawl Queue and Crawl Cache pages.
     *
     * The action used to arrive over GET, with no nonce and no capability
     * check: `is_admin()` was enough, which only says "we are inside wp-admin"
     * and which any authenticated user satisfies. On top of that it never
     * worked — filter_input() without FILTER_REQUIRE_ARRAY returns null on an
     * array input, so the branch never fired. Fixing only the authorisation
     * would have secured a dead branch and left it dead.
     *
     * @param callable(int[]):void $remove The removal itself.
     */
    private static function handleBulkRemoval( string $nonce_action, callable $remove ) : void {
        // This first read only decides whether there is anything to
        // authorise: on an ordinary visit to the page there is no nonce, and
        // demanding authorize() on every load would make the page
        // unreachable. It touches nothing, and the line after it verifies.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

        if ( $action !== 'remove' ) {
            return;
        }

        Controller::authorize( $nonce_action );

        // The nonce is verified by the line above. phpcs does not recognise
        // it because WPCS discards calls preceded by :: — see
        // has_object_operator_before() in NonceVerificationSniff — so no guard
        // that is a static method can ever be declared to it.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $ids = isset( $_POST['id'] )
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? array_map( 'absint', (array) wp_unslash( $_POST['id'] ) )
            : [];
        $ids = array_values( array_filter( $ids ) );

        if ( ! $ids ) {
            return;
        }

        $remove( $ids );
    }

    /**
     * The search term and the page arrive over GET from the pagination links
     * and over POST from the form, which is POST now because of the nonce.
     */
    private static function requestValue( string $key ) : string {
        // Read only: it filters and paginates a list, changing nothing. The
        // nonce is verified by handleBulkRemoval(), which is the only route to
        // a write.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        }

        if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

        return '';
    }

    public static function renderOptionsPage() : void {
        CoreOptions::init();

        $view = [
            'coreOptions' => CoreOptions::getAll(),
            'nonce_action' => 'wp2static-ui-options',
        ];

        require_once VIBESTATIC_PATH . 'views/options-page.php';
    }

    public static function renderAdvancedOptionsPage() : void {
        CoreOptions::init();

        $view = [
            'coreOptions' => CoreOptions::getAll(),
            'nonce_action' => 'wp2static-ui-advanced-options',
        ];

        require_once VIBESTATIC_PATH . 'views/advanced-options-page.php';
    }

    public static function renderDiagnosticsPage() : void {
        $view = [];
        /*
         * Converted HERE, not in the view.
         *
         * `ini_get()` always returns a string: an unlimited
         * `max_execution_time` arrives as `"0"`, not as `0`. The view declared
         * it `@var int` and compared it with `===`, so `0 === "0"` was false and
         * the Diagnostics page reported "0 seconds — Needs attention" for a
         * configuration that is in fact the right one. The comparison used to be
         * `==`, which digested the string; tightening it to `===` without fixing
         * the type at the source made the defect visible — and PHPStan could not
         * see it, because it believed the docblock.
         *
         * The lesson: a type is established where the data is born, not
         * declared where it is read.
         */
        $view['memoryLimit'] = (string) ini_get( 'memory_limit' );
        $view['coreOptions'] = array_values( CoreOptions::getAll() );
        $view['site_info'] = SiteInfo::getAllInfo();
        // 8.2, which is what the plugin requires and what this same page
        // states two lines further down. It had been left at 7.4: on PHP 8.0
        // the tick was green next to text telling the user to upgrade.
        $view['phpOutOfDate'] = version_compare( PHP_VERSION, '8.2', '<' );
        $view['uploadsWritable'] = SiteInfo::isUploadsWritable();
        $view['maxExecutionTime'] = (int) ini_get( 'max_execution_time' );
        $view['curlSupported'] = SiteInfo::hasCURLSupport();
        $view['permalinksAreCompatible'] = SiteInfo::permalinksAreCompatible();
        $view['domDocumentAvailable'] = class_exists( 'DOMDocument' );
        $view['extensions'] = get_loaded_extensions();

        require_once VIBESTATIC_PATH . 'views/diagnostics-page.php';
    }

    public static function renderLogsPage() : void {
        $view = [];
        $view['nonce_action'] = 'wp2static-log-page';
        $view['logs'] = WsLog::getAll();

        require_once VIBESTATIC_PATH . 'views/logs-page.php';
    }

    public static function renderAddonsPage() : void {
        $view = [];
        $view['nonce_action'] = 'wp2static-addons-page';
        $view['addons'] = Addons::getAll();

        require_once VIBESTATIC_PATH . 'views/addons-page.php';
    }

    public static function renderCrawlQueue() : void {
        if ( ! is_admin() ) {
            http_response_code( 403 );
            die( 'Forbidden' );
        }

        $nonce_action = 'wp2static-crawl-queue-page';

        self::handleBulkRemoval(
            $nonce_action,
            function ( array $ids ) : void {
                CrawlQueue::rmUrlsById( $ids );
            }
        );

        $urls = CrawlQueue::getCrawlablePaths();
        // Apply search
        $search_term = self::requestValue( 's' );
        if ( $search_term !== '' ) {
            $urls = array_filter(
                $urls,
                function ( $url ) use ( $search_term ) {
                    return stripos( $url, $search_term ) !== false;
                }
            );
        }

        $page_size = 200;
        $page = max( 1, intval( self::requestValue( 'paged' ) ) );
        $paginator = new Paginator( $urls, $page_size, $page );
        $view = [
            'nonce_action' => $nonce_action,
            'paginatorFirstPage' => $paginator->firstPage(),
            'paginatorLastPage' => $paginator->lastPage(),
            'paginatorPage' => $paginator->page(),
            'paginatorRecords' => $paginator->records(),
            'paginatorTotalRecords' => $paginator->totalRecords(),
        ];

        require_once VIBESTATIC_PATH . 'views/crawl-queue-page.php';
    }

    public static function renderCrawlCache() : void {
        if ( ! is_admin() ) {
            http_response_code( 403 );
            die( 'Forbidden' );
        }

        $nonce_action = 'wp2static-crawl-cache-page';

        self::handleBulkRemoval(
            $nonce_action,
            function ( array $ids ) : void {
                CrawlCache::rmUrlsById( $ids );
            }
        );

        $urls = CrawlCache::getURLs();
        // Apply search
        $search_term = self::requestValue( 's' );
        if ( $search_term !== '' ) {
            $urls = array_filter(
                $urls,
                function ( $url ) use ( $search_term ) {
                    return stripos( $url->url ?? '', $search_term ) !== false;
                }
            );
        }

        $page_size = 200;
        $page = max( 1, intval( self::requestValue( 'paged' ) ) );
        $paginator = new Paginator( $urls, $page_size, $page );
        $view = [
            'nonce_action' => $nonce_action,
            'paginatorFirstPage' => $paginator->firstPage(),
            'paginatorLastPage' => $paginator->lastPage(),
            'paginatorPage' => $paginator->page(),
            'paginatorRecords' => $paginator->records(),
            'paginatorTotalRecords' => $paginator->totalRecords(),
        ];

        require_once VIBESTATIC_PATH . 'views/crawl-cache-page.php';
    }

    public static function renderPostProcessedSitePaths() : void {
        if ( ! is_admin() ) {
            http_response_code( 403 );
            die( 'Forbidden' );
        }

        $paths = ProcessedSite::getPaths();

        // Apply search
        $search_term = self::requestValue( 's' );
        if ( $search_term !== '' ) {
            $paths = array_filter(
                $paths,
                function ( $path ) use ( $search_term ) {
                    return stripos( $path, $search_term ) !== false;
                }
            );
        }

        $page_size = 200;
        $page = max( 1, intval( self::requestValue( 'paged' ) ) );
        $paginator = new Paginator( $paths, $page_size, $page );
        $view = [
            'paginatorFirstPage' => $paginator->firstPage(),
            'paginatorLastPage' => $paginator->lastPage(),
            'paginatorPage' => $paginator->page(),
            'paginatorRecords' => $paginator->records(),
            'paginatorTotalRecords' => $paginator->totalRecords(),
        ];

        require_once VIBESTATIC_PATH . 'views/post-processed-site-paths-page.php';
    }

    public static function renderStaticSitePaths() : void {
        if ( ! is_admin() ) {
            http_response_code( 403 );
            die( 'Forbidden' );
        }

        $paths = StaticSite::getPaths();

        // Apply search
        $search_term = self::requestValue( 's' );
        if ( $search_term !== '' ) {
            $paths = array_filter(
                $paths,
                function ( $path ) use ( $search_term ) {
                    return stripos( $path, $search_term ) !== false;
                }
            );
        }

        $page_size = 200;
        $page = max( 1, intval( self::requestValue( 'paged' ) ) );
        $paginator = new Paginator( $paths, $page_size, $page );
        $view = [
            'paginatorFirstPage' => $paginator->firstPage(),
            'paginatorLastPage' => $paginator->lastPage(),
            'paginatorPage' => $paginator->page(),
            'paginatorRecords' => $paginator->records(),
            'paginatorTotalRecords' => $paginator->totalRecords(),
        ];

        require_once VIBESTATIC_PATH . 'views/static-site-paths-page.php';
    }

    public static function renderDeployCache() : void {
        if ( ! is_admin() ) {
            http_response_code( 403 );
            die( 'Forbidden' );
        }

        $deploy_namespace = strval( filter_input( INPUT_GET, 'deploy_namespace' ) );
        $paths = $deploy_namespace !== ''
            ? DeployCache::getPaths( $deploy_namespace )
            : DeployCache::getPaths();

        // Apply search
        $search_term = self::requestValue( 's' );
        if ( $search_term !== '' ) {
            $paths = array_filter(
                $paths,
                function ( $path ) use ( $search_term ) {
                    return stripos( $path, $search_term ) !== false;
                }
            );
        }

        $page_size = 200;
        $page = max( 1, intval( self::requestValue( 'paged' ) ) );
        $paginator = new Paginator( $paths, $page_size, $page );
        $view = [
            'paginatorFirstPage' => $paginator->firstPage(),
            'paginatorLastPage' => $paginator->lastPage(),
            'paginatorPage' => $paginator->page(),
            'paginatorRecords' => $paginator->records(),
            'paginatorTotalRecords' => $paginator->totalRecords(),
        ];

        require_once VIBESTATIC_PATH . 'views/deploy-cache-page.php';
    }

    public static function renderJobsPage() : void {
        CoreOptions::init();
        JobQueue::markFailedJobs();
        JobQueue::squashQueue();

        $view = [];
        $view['nonce_action'] = 'wp2static-ui-job-options';
        $view['jobs'] = JobQueue::getJobs();

        $view['jobOptions'] = [
            'queueJobOnPostSave' => CoreOptions::get( 'queueJobOnPostSave' ),
            'queueJobOnPostDelete' => CoreOptions::get( 'queueJobOnPostDelete' ),
            'processQueueImmediately' => CoreOptions::get( 'processQueueImmediately' ),
            'processQueueInterval' => CoreOptions::get( 'processQueueInterval' ),
            'autoJobQueueDetection' => CoreOptions::get( 'autoJobQueueDetection' ),
            'autoJobQueueCrawling' => CoreOptions::get( 'autoJobQueueCrawling' ),
            'autoJobQueuePostProcessing' => CoreOptions::get( 'autoJobQueuePostProcessing' ),
            'autoJobQueueDeployment' => CoreOptions::get( 'autoJobQueueDeployment' ),
        ];

        $view = apply_filters( 'wp2static_render_jobs_page_vars', $view );

        require_once VIBESTATIC_PATH . 'views/jobs-page.php';
    }

    public static function renderRunPage() : void {
        $view = [];

        require_once VIBESTATIC_PATH . 'views/run-page.php';
    }


    public static function renderCachesPage() : void {
        $view = [];

        // performance check vs map
        $disk_space = 0;

        $exported_site_dir = StaticSite::getPath();
        if ( is_dir( $exported_site_dir ) ) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $exported_site_dir
                )
            );

            foreach ( $files as $file ) {
                /**
                 * @var \SplFileInfo $file
                 */
                $disk_space += $file->getSize();
            }
        }

        $view['exportedSiteDiskSpace'] = sprintf( '%4.2f MB', $disk_space / 1048576 );
        // end check

        if ( is_dir( $exported_site_dir ) ) {
            $view['exportedSiteFileCount'] = iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $exported_site_dir,
                        \FilesystemIterator::SKIP_DOTS
                    )
                )
            );
        } else {
            $view['exportedSiteFileCount'] = 0;
        }

        // performance check vs map
        $disk_space = 0;
        $processed_site_dir = ProcessedSite::getPath();

        if ( is_dir( $processed_site_dir ) ) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $processed_site_dir
                )
            );

            foreach ( $files as $file ) {
                /**
                 * @var \SplFileInfo $file
                 */
                $disk_space += $file->getSize();
            }
        }

        $view['processedSiteDiskSpace'] = sprintf( '%4.2f MB', $disk_space / 1048576 );
        // end check

        if ( is_dir( $processed_site_dir ) ) {
            $view['processedSiteFileCount'] = iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $processed_site_dir,
                        \FilesystemIterator::SKIP_DOTS
                    )
                )
            );
        } else {
            $view['processedSiteFileCount'] = 0;
        }

        $view['crawlQueueTotalURLs'] = CrawlQueue::getTotal();
        $view['crawlCacheTotalURLs'] = CrawlCache::getTotal();
        $view['deployCacheTotalPaths'] = DeployCache::getTotal();
        $view['uploads_path'] = SiteInfo::getPath( 'uploads' );
        $view['nonce_action'] = 'wp2static-caches-page';

        require_once VIBESTATIC_PATH . 'views/caches-page.php';
    }


}
