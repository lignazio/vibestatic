<?php

namespace WP2Static;

use ZipArchive;
use WP_Error;
use WP_CLI;
use WP_Post;

class Controller {
    /**
     * @var string
     */
    public $bootstrap_file;

    /**
     * Main controller of VibeStatic
     *
     * @var \WP2Static\Controller Instance.
     */
    protected static $plugin_instance = null;

    protected function __construct() {}

    /**
     * Returns instance of VibeStatic Controller
     *
     * @return \WP2Static\Controller Instance of self.
     */
    public static function getInstance() : Controller {
        if ( null === self::$plugin_instance ) {
            self::$plugin_instance = new self();
        }

        return self::$plugin_instance;
    }

    public static function init( string $bootstrap_file ) : Controller {
        $plugin_instance = self::getInstance();

        WordPressAdmin::registerHooks( $bootstrap_file );
        WordPressAdmin::addAdminUIElements();

        Utils::set_max_execution_time();

        return $plugin_instance;
    }

    /**
     * Adjusts position of dashboard menu icons
     *
     * @param string[] $menu_order list of menu items
     * @return string[] list of menu items
     */
    public static function setMenuOrder( array $menu_order ) : array {
        $order = [];
        $file  = plugin_basename( __FILE__ );

        foreach ( $menu_order as $index => $item ) {
            if ( $item === 'index.php' ) {
                $order[] = $item;
            }
        }

        $order = [
            'index.php',
            'wp2static',
            'statichtmloutput',
        ];

        return $order;
    }

    public static function deactivateForSingleSite() : void {
        WPCron::clearRecurringEvent();
    }

    public static function deactivate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            $site_ids = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT blog_id FROM %i WHERE site_id = %d',
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                self::deactivateForSingleSite();
            }

            restore_current_blog();
        } else {
            self::deactivateForSingleSite();
        }
    }

    public static function activateForSingleSite() : void {
        // The list of tables lives in Schema, in one place only. It used to
        // live here, where a plugin update never read it.
        Schema::install();
    }

    public static function activate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            $site_ids = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT blog_id FROM %i WHERE site_id = %d',
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                self::activateForSingleSite();
            }

            restore_current_blog();
        } else {
            self::activateForSingleSite();
        }
    }

    /**
     * Create an index if it does not already exist.
     *
     * This never alters an existing index. To change one, give it a new name.
     *
     * WordPress's dbDelta is very unreliable for indexes: it tends to create
     * duplicates, misbehaves when whitespace is not exactly what it expects,
     * and fails silently. Creating the table and its primary key with dbDelta
     * is fine; indexes go through here.
     *
     * This method used to take the CREATE INDEX statement already written by
     * the caller, which meant raw SQL crossing a class boundary. It now takes
     * the pieces and builds the query here, with %i on every identifier, so
     * there is no longer any point where a SQL string travels between classes.
     *
     * @param string   $table_name Table the index belongs to.
     * @param string   $index_name Name of the index.
     * @param string[] $columns    Columns the index covers.
     * @param bool     $unique     Whether the index is unique.
     * @return bool True if the index already existed or was created.
     */
    public static function ensureIndex( string $table_name, string $index_name,
                                        array $columns, bool $unique = false ) : bool {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $indexes = $wpdb->query(
            $wpdb->prepare(
                'SHOW INDEX FROM %i WHERE key_name = %s',
                $table_name,
                $index_name
            )
        );

        if ( 0 === $indexes ) {
            // Neither interpolated fragment below carries data: $create is one
            // of two literals, and $placeholders is '%i' repeated as many times
            // as there are columns. Every real identifier goes through %i, and
            // MySQL will not take a variable number of placeholders inside a
            // string that is itself a literal, so the generation has to be
            // dynamic. phpcs cannot know that, and this is the only SQL
            // suppression in the project.
            $placeholders = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
            $create = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
            $result = $wpdb->query(
                $wpdb->prepare(
                    "$create %i ON %i ( $placeholders )",
                    array_merge( [ $index_name, $table_name ], $columns )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
            if ( false === $result ) {
                WsLog::l( "Failed to create $index_name index on $table_name." );
            }
            return $result;
        } else {
            return true;
        }
    }

    public static function registerOptionsPage() : void {
        add_menu_page(
            'VibeStatic',
            'VibeStatic',
            'manage_options',
            'wp2static',
            [ ViewRenderer::class, 'renderRunPage' ],
            'dashicons-shield-alt'
        );

        /*
         * The titles are written out, not computed. They used to be
         * `ucfirst( $slug )`, which meant the eight most visible labels in the
         * plugin existed as a string nowhere at all: there was nothing to
         * translate, and every language read "Run", "Caches", "Addons".
         * `ucfirst()` is not a valid capitalisation rule outside English
         * either.
         *
         * @var array<string, array{0: string, 1: callable}> slug => [ label, callback ]
         */
        $submenu_pages = [
            'wp2static' => [
                __( 'Run', 'vibestatic' ),
                [ ViewRenderer::class, 'renderRunPage' ],
            ],
            'wp2static-options' => [
                __( 'Options', 'vibestatic' ),
                [ ViewRenderer::class, 'renderOptionsPage' ],
            ],
            'wp2static-jobs' => [
                __( 'Jobs', 'vibestatic' ),
                [ ViewRenderer::class, 'renderJobsPage' ],
            ],
            'wp2static-caches' => [
                __( 'Caches', 'vibestatic' ),
                [ ViewRenderer::class, 'renderCachesPage' ],
            ],
            'wp2static-diagnostics' => [
                __( 'Diagnostics', 'vibestatic' ),
                [ ViewRenderer::class, 'renderDiagnosticsPage' ],
            ],
            'wp2static-logs' => [
                __( 'Logs', 'vibestatic' ),
                [ ViewRenderer::class, 'renderLogsPage' ],
            ],
            'wp2static-addons' => [
                __( 'Add-ons', 'vibestatic' ),
                [ ViewRenderer::class, 'renderAddonsPage' ],
            ],
            'wp2static-advanced' => [
                __( 'Advanced', 'vibestatic' ),
                [ ViewRenderer::class, 'renderAdvancedOptionsPage' ],
            ],
        ];

        foreach ( $submenu_pages as $menu_slug => $page ) {
            add_submenu_page(
                'wp2static',
                self::pageTitle( $page[0] ),
                $page[0],
                'manage_options',
                $menu_slug,
                $page[1]
            );
        }

        /** @var array<string, array{0: string, 1: callable}> slug => [ titolo, callback ] */
        $hidden_pages = [
            'wp2static-crawl-queue' => [
                __( 'Crawl Queue', 'vibestatic' ),
                [ ViewRenderer::class, 'renderCrawlQueue' ],
            ],
            'wp2static-crawl-cache' => [
                __( 'Crawl Cache', 'vibestatic' ),
                [ ViewRenderer::class, 'renderCrawlCache' ],
            ],
            'wp2static-deploy-cache' => [
                __( 'Deploy Cache', 'vibestatic' ),
                [ ViewRenderer::class, 'renderDeployCache' ],
            ],
            'wp2static-static-site' => [
                __( 'Generated Static Site', 'vibestatic' ),
                [ ViewRenderer::class, 'renderStaticSitePaths' ],
            ],
            'wp2static-post-processed-site' => [
                __( 'Post-processed Static Site', 'vibestatic' ),
                [ ViewRenderer::class, 'renderPostProcessedSitePaths' ],
            ],
        ];

        foreach ( $hidden_pages as $slug => $page ) {
            self::addHiddenPage( self::pageTitle( $page[0] ), $slug, $page[1] );
        }

        self::registerAddonPages();
    }

    /**
     * The pages add-ons ask to have registered.
     *
     * `wp2static_add_menu_items` has been a dead hook since 9 May 2020, commit
     * 0b1db4e3 "rm old submenu page setup": the core stopped firing it, while
     * sftp, s3 and netlify kept registering on it — it is the only route they
     * have to a settings page. The result is that in WP2Static 7.2 those three
     * add-ons install, activate, hook into the deploy, and have nowhere to put
     * their credentials.
     *
     * The contract is the one from back then, and the one add-ons still expect:
     * an array of `slug => callable`, where the slug becomes
     * `wp2static-<slug>`.
     *
     * The label deliberately does not go through `__()`. The add-on decides
     * that text, not us: it is the one string in this interface the plugin does
     * not own, and translating it would mean translating somebody else's
     * product name.
     */
    public static function registerAddonPages() : void {
        /** @var mixed $addon_pages */
        $addon_pages = apply_filters( 'wp2static_add_menu_items', [] );

        if ( ! is_array( $addon_pages ) ) {
            return;
        }

        foreach ( $addon_pages as $slug => $callback ) {
            if ( ! is_string( $slug ) || ! is_callable( $callback ) ) {
                continue;
            }

            add_submenu_page(
                'wp2static',
                self::pageTitle( $slug ),
                $slug,
                'manage_options',
                'wp2static-' . $slug,
                $callback
            );
        }
    }

    /**
     * "VibeStatic Jobs", but with the word order in the translator's hands.
     * Concatenating the plugin name in front of the label works in English and
     * in little else.
     */
    private static function pageTitle( string $label ) : string {
        return sprintf(
            /* translators: %s: name of the admin page, e.g. "Jobs". */
            __( 'VibeStatic %s', 'vibestatic' ),
            $label
        );
    }

    /**
     * @var array<string, string> page slug => title
     */
    private static $hidden_page_titles = [];

    /**
     * Register a page that is reachable but has no menu entry.
     *
     * Keeping the title on the side is not fussiness. WordPress looks it up
     * with `get_admin_page_title()`, which — when the parent is empty, that is,
     * for every hidden page — goes looking among the top-level menus, where a
     * hidden page by definition is not. Not finding it, it leaves `$title`
     * null, and `admin-header.php` then calls `strip_tags()` on it: with
     * WP_DEBUG on, a WordPress deprecation at the top of every one of our
     * pages.
     *
     * Registering them under the real parent and then removing them from the
     * menu does not help: `remove_submenu_page()` deletes the very entry the
     * title would be read from. And the parent stays the empty string, not
     * `null`: the first thing `add_submenu_page()` does is pass it to
     * `plugin_basename()`, which on null emits the same deprecation being
     * removed.
     *
     * @param string   $title    Page title.
     * @param string   $slug     Slug, i.e. the value of ?page=.
     * @param callable $callback What renders it.
     */
    public static function addHiddenPage( string $title, string $slug, $callback ) : void {
        add_submenu_page( '', $title, $title, 'manage_options', $slug, $callback );

        if ( ! self::$hidden_page_titles ) {
            add_action( 'current_screen', [ self::class, 'setHiddenPageTitle' ] );
        }

        self::$hidden_page_titles[ $slug ] = $title;
    }

    /**
     * The page an add-on's Configure link should open, or null if it has none.
     *
     * There are two ways an add-on gets a page, and the Add-ons list has to
     * cope with both. Most go through `wp2static_add_menu_items`, whose key the
     * core registers as `wp2static-<key>`: the key is the slug with
     * `wp2static-addon-` taken off it, so `wp2static-addon-netlify` is reached
     * at `wp2static-netlify`. The directory-deployment and zip modules instead
     * call addHiddenPage() above with the whole slug, because their pages are
     * deliberately not in the menu — `wp2static-addon-zip` is the page.
     *
     * Guessing one convention gets the other wrong, which is how the first
     * attempt at this fix left those two still broken. So nothing is guessed:
     * each candidate is asked of `$_registered_pages`, WordPress's own record
     * of what `add_submenu_page()` has registered, and the first one actually
     * there wins. That is the same register `wp-admin/admin.php` consults
     * before deciding to answer "Sorry, you are not allowed to access this
     * page" — that is, the exact thing that produced the defect.
     *
     * Null when neither is registered. A third-party add-on may register no
     * page at all, or register one under a name of its own; a link to a page
     * that is not there is worse than no link, so the caller renders no gear.
     */
    public static function addonSettingsPage( string $slug ) : ?string {
        $registered = isset( $GLOBALS['_registered_pages'] ) && is_array( $GLOBALS['_registered_pages'] )
            ? $GLOBALS['_registered_pages']
            : [];

        $candidates = [
            // The hidden-page convention: the slug is the page.
            [ $slug, '' ],
            // The `wp2static_add_menu_items` convention.
            [ 'wp2static-' . preg_replace( '/^wp2static-addon-/', '', $slug ), 'wp2static' ],
        ];

        foreach ( $candidates as list( $page, $parent ) ) {
            if ( isset( $registered[ get_plugin_page_hookname( $page, $parent ) ] ) ) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Give the hidden page being opened a title.
     *
     * Runs on `current_screen`, which WordPress fires before including
     * admin-header.php. `get_admin_page_title()` starts with "if the title is
     * already set, keep it", so filling it in here is all it takes.
     */
    public static function setHiddenPageTitle() : void {
        $page = filter_input( INPUT_GET, 'page' );

        if ( ! is_string( $page ) || ! isset( self::$hidden_page_titles[ $page ] ) ) {
            return;
        }

        // @phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- this is our own page's title.
        $GLOBALS['title'] = self::$hidden_page_titles[ $page ];
    }

    // TODO: why is this here? Move to CrawlQueue if still needed
    public function deleteCrawlCache() : void {
        // we now have modified file list in DB
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        $count = $wpdb->get_var(
            $wpdb->prepare( 'SELECT count(*) FROM %i', $table_name )
        );

        if ( $count === '0' ) {
            http_response_code( 200 );

            echo 'SUCCESS';
        } else {
            http_response_code( 500 );
        }
    }

    /**
     * Guard for admin_post_* handlers: capability first, then nonce, and both
     * before any write.
     *
     * A nonce says WHERE a request came from, not WHO sent it: on its own it
     * lets through any authenticated user who was tricked into loading the
     * options page. On multisite, a sub-site administrator passes the first
     * check and not the second.
     */
    public static function authorize( string $nonce_action ) : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__(
                    'You do not have permission to manage VibeStatic.',
                    'vibestatic'
                ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( $nonce_action );
    }

    /**
     * Like authorize(), for the AJAX endpoints.
     */
    public static function authorizeAjax( string $nonce_action ) : void {
        check_ajax_referer( $nonce_action, 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [
                    'message' => __(
                        'You do not have permission to manage VibeStatic.',
                        'vibestatic'
                    ),
                ],
                403
            );
        }
    }

    public function resetDefaultSettings() : void {
        CoreOptions::seedOptions();
    }

    public function deleteDeployCache() : void {
        DeployCache::truncateAll();
    }

    public static function wp2staticUISaveOptions() : void {
        self::authorize( 'wp2static-ui-options' );

        CoreOptions::savePosted( 'core' );

        do_action( 'wp2static_addon_ui_save_options' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-options' ) );
        exit;
    }

    public static function wp2staticCrawlQueueDelete() : void {
        self::authorize( 'wp2static-caches-page' );

        CrawlQueue::truncate();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function wp2staticCrawlQueueShow() : void {
        self::authorize( 'wp2static-caches-page' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-crawl-queue' ) );
        exit;
    }

    public static function wp2staticDeleteJobsQueue() : void {
        self::authorize( 'wp2static-ui-job-options' );

        JobQueue::truncate();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-jobs' ) );
        exit;
    }

    public static function wp2staticDeleteAllCaches() : void {
        self::authorize( 'wp2static-caches-page' );

        self::deleteAllCaches();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function deleteAllCaches() : void {
        CrawlQueue::truncate();
        CrawlCache::truncate();
        StaticSite::delete();
        ProcessedSite::delete();
        DeployCache::truncateAll();
    }

    public static function wp2staticProcessJobsQueue() : void {
        self::authorize( 'wp2static-ui-job-options' );

        WsLog::l( 'Manually processing JobQueue' );

        self::wp2staticProcessQueue();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-jobs' ) );
        exit;
    }

    public static function wp2staticDeployCacheDelete() : void {
        self::authorize( 'wp2static-caches-page' );

        $deploy_namespace = strval( filter_input( INPUT_POST, 'deploy_namespace' ) );
        if ( $deploy_namespace !== '' ) {
            DeployCache::truncate( $deploy_namespace );
        } else {
            DeployCache::truncateAll();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function wp2staticDeployCacheShow() : void {
        self::authorize( 'wp2static-caches-page' );

        $deploy_namespace = strval( filter_input( INPUT_POST, 'deploy_namespace' ) );
        if ( $deploy_namespace !== '' ) {
            wp_safe_redirect(
                admin_url(
                    'admin.php?page=wp2static-deploy-cache&deploy_namespace=' .
                    urlencode( $deploy_namespace )
                )
            );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=wp2static-deploy-cache' ) );
        }

        exit;
    }

    public static function wp2staticCrawlCacheDelete() : void {
        self::authorize( 'wp2static-caches-page' );

        CrawlCache::truncate();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function wp2staticCrawlCacheShow() : void {
        self::authorize( 'wp2static-caches-page' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-crawl-cache' ) );
        exit;
    }

    public static function wp2staticPostProcessedSiteDelete() : void {
        self::authorize( 'wp2static-caches-page' );

        ProcessedSite::delete();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function wp2staticPostProcessedSiteShow() : void {
        self::authorize( 'wp2static-caches-page' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-post-processed-site' ) );
        exit;
    }

    public static function wp2staticLogDelete() : void {
        self::authorize( 'wp2static-log-page' );

        WsLog::truncate();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-logs' ) );
        exit;
    }

    public static function wp2staticStaticSiteDelete() : void {
        self::authorize( 'wp2static-caches-page' );

        StaticSite::delete();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-caches' ) );
        exit;
    }

    public static function wp2staticStaticSiteShow() : void {
        self::authorize( 'wp2static-caches-page' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-static-site' ) );
        exit;
    }

    public static function wp2staticUISaveJobOptions() : void {
        self::authorize( 'wp2static-ui-job-options' );

        CoreOptions::savePosted( 'jobs' );

        do_action( 'wp2static_addon_ui_save_job_options' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-jobs' ) );
        exit;
    }

    public static function wp2staticSavePostHandler( int $post_id ) : void {
        if ( CoreOptions::getValue( 'queueJobOnPostSave' ) &&
             get_post_status( $post_id ) === 'publish' ) {
            self::wp2staticEnqueueJobs();
        }
    }

    public static function wp2staticTrashedPostHandler() : void {
        if ( CoreOptions::getValue( 'queueJobOnPostDelete' ) ) {
            self::wp2staticEnqueueJobs();
        }
    }

    public static function wp2staticUISaveAdvancedOptions() : void {
        self::authorize( 'wp2static-ui-advanced-options' );

        CoreOptions::savePosted( 'advanced' );

        do_action( 'wp2static_addon_ui_save_advanced_options' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-advanced' ) );
        exit;
    }

    public static function wp2staticEnqueueJobs() : void {
        // check each of these in order we want to enqueue
        $job_types = [
            'autoJobQueueDetection' => 'detect',
            'autoJobQueueCrawling' => 'crawl',
            'autoJobQueuePostProcessing' => 'post_process',
            'autoJobQueueDeployment' => 'deploy',
        ];

        foreach ( $job_types as $key => $job_type ) {
            if ( (int) CoreOptions::getValue( $key ) === 1 ) {
                JobQueue::addJob( $job_type );
            }
        }

        if ( CoreOptions::getValue( 'processQueueImmediately' ) ) {
            self::wp2staticProcessQueueAdminPost();
        }
    }

    public static function wp2staticToggleAddon( ?string $addon_slug = null ) : void {
        if ( defined( 'WP_CLI' ) ) {
            if ( ! $addon_slug ) {
                throw new WP2StaticException(
                    'No addon slug given for CLI toggling'
                );
            }

            $addon_slug = sanitize_text_field( $addon_slug );
        } else {
            self::authorize( 'wp2static-addons-page' );

            $addon_slug = sanitize_text_field( strval( filter_input( INPUT_POST, 'addon_slug' ) ) );
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        // get target addon's current state
        $addon = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT enabled, type FROM %i WHERE slug = %s',
                $table_name,
                $addon_slug
            )
        );

        if ( ! $addon ) {
            throw new WP2StaticException( esc_html( "Unknown addon: $addon_slug" ) );
        }

        // if deploy type, disable other deployers when enabling this one
        if ( $addon->type === 'deploy' ) {
            $wpdb->update(
                $table_name,
                [ 'enabled' => 0 ],
                [
                    'enabled' => 1,
                    'type' => 'deploy',
                ]
            );
        }

        // toggle the target addon's state
        $wpdb->update(
            $table_name,
            [ 'enabled' => ! $addon->enabled ],
            [ 'slug' => $addon_slug ]
        );

        if ( ! defined( 'WP_CLI' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wp2static-addons' ) );
        }

        exit;
    }

    public static function wp2staticManuallyEnqueueJobs() : void {
        self::authorize( 'wp2static-manually-enqueue-jobs' );

        // TODO: consider using a transient based notifications system to
        // persist through wp_safe_redirect calls
        // ie, https://github.com/wpscholar/wp-transient-admin-notices/blob/master/TransientAdminNotices.php

        self::wp2staticEnqueueJobs();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-jobs' ) );
        exit;
    }

    /*
        Should only process at most 4 jobs here (1 per type), with
        earlier jobs of the same type having been "squashed" first
    */
    public static function wp2staticProcessQueue() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        JobQueue::markFailedJobs();
        // skip any earlier jobs of same type still in 'waiting' status
        JobQueue::squashQueue();

        if ( JobQueue::jobsInProgress() ) {
            WsLog::l(
                'Job in progress when attempting to process queue.
                  No new jobs will be processed until current in progress is complete.'
            );

            return;
        }

        // get all with status 'waiting' in order of oldest to newest
        $jobs = JobQueue::getProcessableJobs();

        foreach ( $jobs as $job ) {
            $lock = $wpdb->prefix . '.wp2static_jobs.' . $job->job_type;
            $locked = intval(
                $wpdb->get_row(
                    $wpdb->prepare( 'SELECT GET_LOCK(%s, 30) AS lck', $lock )
                )->lck
            );
            if ( ! $locked ) {
                WsLog::l( "Failed to acquire \"$lock\" lock." );
                return;
            }
            try {
                JobQueue::setStatus( $job->id, 'processing' );

                switch ( $job->job_type ) {
                    case 'detect':
                        WsLog::l( 'Starting URL detection' );
                        $detected_count = URLDetector::enqueueURLs();
                        WsLog::l( "URL detection completed ($detected_count URLs detected)" );
                        break;
                    case 'crawl':
                        self::wp2staticCrawl();
                        break;
                    case 'post_process':
                        WsLog::l( 'Starting post-processing' );
                        $post_processor = new PostProcessor();
                        $processed_site_dir =
                            SiteInfo::getPath( 'uploads' ) . 'wp2static-processed-site';
                        $processed_site = new ProcessedSite();
                        $post_processor->processStaticSite( StaticSite::getPath() );
                        WsLog::l( 'Post-processing completed' );
                        break;
                    case 'deploy':
                        $deployer = Addons::getDeployer();

                        if ( ! $deployer ) {
                            WsLog::l( 'No deployment add-ons are enabled, skipping deployment.' );
                        } else {
                            self::deploy( $deployer );
                        }
                        WsLog::l( 'Starting post-deployment actions' );
                        do_action( 'wp2static_post_deploy_trigger', $deployer );

                        break;
                    default:
                        WsLog::l( 'Trying to process unknown job type' );
                }

                JobQueue::setStatus( $job->id, 'completed' );
            } catch ( \Throwable $e ) {
                JobQueue::setStatus( $job->id, 'failed' );
                // We don't want to crawl and deploy if the detect step fails.
                // Skip all waiting jobs when one fails.
                $table_name = $wpdb->prefix . 'wp2static_jobs';
                $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE %i SET status = %s WHERE status = %s',
                        $table_name,
                        'skipped',
                        'waiting'
                    )
                );
                throw $e;
            } finally {
                $wpdb->query( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }

    /**
     *  Make a non-blocking POST request to run wp2staticProcessQueue.
     */
    public static function wp2staticProcessQueueAdminPost() : void {
        $url = admin_url( 'admin-post.php' ) . '?action=wp2static_process_queue';
        $nonce = wp_create_nonce( 'wp2static_process_queue' );
        $result = wp_remote_post(
            $url,
            [
                'blocking' => false,
                'body' => [ '_wpnonce' => $nonce ],
                'cookies' => $_COOKIE,
                'sslverify' => false,
                'timeout' => 0.01,
            ]
        );

        if ( is_wp_error( $result ) ) {
            WsLog::l(
                'Error in wp2staticProcessQueueAdminPost. Request to admin-post.php failed: ' .
                json_encode( $result->errors )
            );
        }
    }

    public static function wp2staticHeadless() : void {
        WsLog::l( 'Running VibeStatic in Headless mode' );
        WsLog::l( 'Starting URL detection' );
        $detected_count = URLDetector::enqueueURLs();
        WsLog::l( "URL detection completed ($detected_count URLs detected)" );

        self::wp2staticCrawl();

        WsLog::l( 'Starting post-processing' );
        $post_processor = new PostProcessor();
        $processed_site_dir =
            SiteInfo::getPath( 'uploads' ) . 'wp2static-processed-site';
        $processed_site = new ProcessedSite();
        $post_processor->processStaticSite( StaticSite::getPath() );
        WsLog::l( 'Post-processing completed' );

        $deployer = Addons::getDeployer();

        if ( ! $deployer ) {
            WsLog::l( 'No deployment add-ons are enabled, skipping deployment.' );
        } else {
            self::deploy( $deployer );
        }
        WsLog::l( 'Starting post-deployment actions' );
        do_action( 'wp2static_post_deploy_trigger', $deployer );
    }

    /**
     * Fire the deploy.
     *
     * The only place it starts from: the same block used to be repeated three
     * times, twice here and once in the CLI.
     *
     * The report on what is about to change is deliberately not printed here.
     * `DeployCache::plan()` needs to know which namespace the deployer
     * registered what it has already published under, and only the deployer
     * knows that name, not the core: printing it from here would mean guessing
     * it, and a guessed number in a report is worse than no report. The
     * deployer asks for it and writes it — see the directory-deployment add-on.
     *
     * @param string $deployer Slug of the add-on doing the deploying.
     */
    public static function deploy( string $deployer ) : void {
        WsLog::l( 'Starting deployment' );

        do_action(
            'wp2static_deploy',
            ProcessedSite::getPath(),
            $deployer
        );
    }

    public static function invalidateSingleURLCache(
        int $post_id = 0,
        ?WP_Post $post = null
    ) : void {
        if ( ! $post ) {
            return;
        }

        $permalink = get_permalink(
            $post->ID
        );

        /*
         * `get_permalink()` returns string|false — false for a post that does
         * not exist. The check on $site_url beside this one was genuinely
         * redundant, and taking the whole condition away with it would leave
         * str_replace() a boolean to work on.
         */
        if ( ! is_string( $permalink ) ) {
            return;
        }

        $site_url = SiteInfo::getUrl( 'site' );

        $url = str_replace(
            $site_url,
            '/',
            $permalink
        );

        CrawlCache::rmUrl( $url );
    }

    public static function emailDeployNotification() : void {
        if ( empty( CoreOptions::getValue( 'completionEmail' ) ) ) {
            return;
        }

        WsLog::l( 'Sending deployment notification email...' );

        $to = CoreOptions::getValue( 'completionEmail' );

        $subject = sprintf(
            /* translators: %s: the site title. */
            __( 'VibeStatic deployment complete on site: %s', 'vibestatic' ),
            get_bloginfo( 'name' )
        );

        $body = __( 'VibeStatic deployment complete!', 'vibestatic' );

        if ( wp_mail( $to, $subject, $body, [] ) ) {
            WsLog::l( 'Deployment notification email sent without error.' );
        } else {
            WsLog::l( 'Failed to send deployment notification email.' );
        }
    }

    public static function webhookDeployNotification() : void {
        $webhook_url = CoreOptions::getValue( 'completionWebhook' );

        if ( empty( $webhook_url ) ) {
            return;
        }

        WsLog::l( 'Sending deployment notification webhook...' );

        $http_method = CoreOptions::getValue( 'completionWebhookMethod' );

        /*
         * This does NOT go through __(). A person reads the email message, so
         * it belongs in the site's language; a program on the other end of the
         * webhook reads this one, and expects the string it saw during setup.
         * Translating it would turn a change of site language into a silent
         * broken integration.
         */
        $message = 'VibeStatic deployment complete!';

        $body = $http_method === 'POST' ? $message : [ 'message' => $message ];

        $webhook_response = wp_remote_request(
            $webhook_url,
            [
                'method' => CoreOptions::getValue( 'completionWebhookMethod' ),
                'timeout' => 30,
                'user-agent' =>
                    apply_filters( 'wp2static_deploy_webhook_user_agent', 'VibeStatic' ),
                'body' => apply_filters( 'wp2static_deploy_webhook_body', $body ),
                'headers' => apply_filters( 'wp2static_deploy_webhook_headers', [] ),
            ]
        );

        WsLog::l(
            'Webhook response code: ' . wp_remote_retrieve_response_code( $webhook_response )
        );
    }

    public static function wp2staticRun() : void {
        self::authorizeAjax( 'wp2static-run-page' );

        WsLog::l( 'Running full workflow from UI' );

        self::wp2staticHeadless();

        wp_die();
    }

    public static function wp2staticCrawl() : void {
        WsLog::l( 'Starting crawling' );
        $crawlers = Addons::getType( 'crawl' );
        $crawler_slug = empty( $crawlers ) ? 'wp2static' : $crawlers[0]->slug;
        do_action(
            'wp2static_crawl',
            StaticSite::getPath(),
            $crawler_slug
        );
        WsLog::l( 'Crawling completed' );
    }

    /**
     * Give logs to UI
     */
    public static function wp2staticPollLog() : void {
        self::authorizeAjax( 'wp2static-run-page' );

        // This used to be `echo $logs;` — arbitrary text (crawled URLs,
        // third-party error messages) printed raw in response to an AJAX
        // request. Today it lands in a textarea's .val() and is not
        // interpreted, but the day somebody switches to .html() it becomes an
        // XSS, and in the meantime the escaping sniff has no way to tell the
        // two cases apart. wp_send_json_success() encodes as JSON, which is
        // the AJAX contract WordPress expects and does not alter the log text.
        wp_send_json_success( [ 'log' => WsLog::poll() ] );
    }
}
