<?php
/*
    WordPressAdmin

    VibeStatic's interface to WordPress Admin functions

    Used for registering hooks, Admin UI components, ...
*/

namespace WP2Static;

class WordPressAdmin {

    /**
     * WordPressAdmin constructor
     */
    public function __construct() {

    }

    /**
     * Register hooks for WordPress and VibeStatic actions
     *
     * @param string $bootstrap_file main plugin filepath
     */
    public static function registerHooks( string $bootstrap_file ) : void {
        register_activation_hook(
            $bootstrap_file,
            [ Controller::class, 'activate' ]
        );

        register_deactivation_hook(
            $bootstrap_file,
            [ Controller::class, 'deactivate' ]
        );

        /*
         * Activation does not fire on every plugin update, so on its own it is
         * not enough to land a schema change.
         *
         * On `init` and not on `plugins_loaded`, where it used to be: the update
         * goes through `CoreOptions::seedOptions()`, so through `optionSpecs()`,
         * where the labels are now wrapped in `__()`. Asking for a translation
         * before `after_setup_theme` triggers the `_doing_it_wrong` WordPress
         * 6.7 added to `_load_textdomain_just_in_time()` — and it only triggers
         * where a translation of the domain actually exists, that is, never
         * during development in English and always for whoever runs the plugin
         * translated. Priority 5 keeps the schema update ahead of any other
         * `init` hook that reads an option.
         */
        add_action( 'init', [ Schema::class, 'updateIfNeeded' ], 5 );

        /*
         * The bundled deployers. On `plugins_loaded` and not here at load time:
         * plugin files are included alphabetically, so `vibestatic` runs before
         * any `wp2static-addon-*` still installed separately, and the check for
         * an already-loaded copy would always come back empty. Priority 5 keeps
         * them ahead of the schema update on `init`, which asks them for their
         * tables.
         */
        add_action( 'plugins_loaded', [ Modules::class, 'load' ], 5 );

        // Registers two hooks and reads no options: with no endpoint
        // configured, FormConverter opens no file and touches no form.
        FormConverter::registerHooks();

        /*
         * On `plugins_loaded`, and the reason is the same one that put the
         * modules there: this line runs while the plugin file is being
         * included, plugin files are included alphabetically, and `vibestatic`
         * comes before `woocommerce`. Asked here, `class_exists( 'WooCommerce' )`
         * is false on a site that has WooCommerce — so the module registered
         * nothing, always, and the only sign of it was a cart page that kept
         * being published.
         */
        add_action( 'plugins_loaded', [ WooCommerce\Snipcart::class, 'registerHooks' ], 5 );

        // Updates for anyone who installed from a zip. This registers filters
        // only: the call to GitHub happens when WordPress checks, not now.
        Updater::registerHooks();

        add_filter(
            // phpcs:ignore WordPress.WP.CronInterval -- namespaces not yet fully supported
            'cron_schedules',
            [ WPCron::class, 'wp2static_custom_cron_schedules' ]
        );

        add_filter(
            'wp2static_list_redirects',
            [ CrawlCache::class, 'wp2static_list_redirects' ]
        );

        add_filter(
            'cron_request',
            [ WPCron::class, 'wp2static_cron_with_http_basic_auth' ]
        );

        add_action(
            'wp_ajax_wp2static_run',
            [ Controller::class, 'wp2staticRun' ],
            10,
            0
        );

        add_action(
            'wp_ajax_wp2static_poll_log',
            [ Controller::class, 'wp2staticPollLog' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_ui_save_options',
            [ Controller::class, 'wp2staticUISaveOptions' ],
            10,
            0
        );

        add_action(
            'wp2static_register_addon',
            [ Addons::class, 'registerAddon' ],
            10,
            5
        );

        add_action(
            'wp2static_post_deploy_trigger',
            [ Controller::class, 'emailDeployNotification' ],
            10,
            0
        );

        add_action(
            'wp2static_post_deploy_trigger',
            [ Controller::class, 'webhookDeployNotification' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_post_processed_site_delete',
            [ Controller::class, 'wp2staticPostProcessedSiteDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_post_processed_site_show',
            [ Controller::class, 'wp2staticPostProcessedSiteShow' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_log_delete',
            [ Controller::class, 'wp2staticLogDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_delete_all_caches',
            [ Controller::class, 'wp2staticDeleteAllCaches' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_delete_jobs_queue',
            [ Controller::class, 'wp2staticDeleteJobsQueue' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2staticProcessJobsQueue',
            [ Controller::class, 'wp2staticProcessJobsQueue' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_process_queue',
            [ self::class, 'adminPostProcessQueue' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_crawl_queue_delete',
            [ Controller::class, 'wp2staticCrawlQueueDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_crawl_queue_show',
            [ Controller::class, 'wp2staticCrawlQueueShow' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_deploy_cache_delete',
            [ Controller::class, 'wp2staticDeployCacheDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_deploy_cache_show',
            [ Controller::class, 'wp2staticDeployCacheShow' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_crawl_cache_delete',
            [ Controller::class, 'wp2staticCrawlCacheDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_crawl_cache_show',
            [ Controller::class, 'wp2staticCrawlCacheShow' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_static_site_delete',
            [ Controller::class, 'wp2staticStaticSiteDelete' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_static_site_show',
            [ Controller::class, 'wp2staticStaticSiteShow' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_ui_save_job_options',
            [ Controller::class, 'wp2staticUISaveJobOptions' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_ui_save_advanced_options',
            [ Controller::class, 'wp2staticUISaveAdvancedOptions' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_manually_enqueue_jobs',
            [ Controller::class, 'wp2staticManuallyEnqueueJobs' ],
            10,
            0
        );

        add_action(
            'admin_post_wp2static_toggle_addon',
            [ Controller::class, 'wp2staticToggleAddon' ],
            10,
            0
        );

        add_action(
            'wp2static_process_queue',
            [ Controller::class, 'wp2staticProcessQueue' ],
            10,
            0
        );

        add_action(
            'wp2static_headless_hook',
            [ Controller::class, 'wp2staticHeadless' ],
            10,
            0
        );

        add_action(
            'wp2static_crawl',
            [ Crawler::class, 'wp2staticCrawl' ],
            10,
            2
        );

        add_action(
            'wp2static_process_html',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'wp2static_process_css',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'wp2static_process_js',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'wp2static_process_robots_txt',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'wp2static_process_xml',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'wp2static_process_xsl',
            [ SimpleRewriter::class, 'rewrite' ],
            10,
            1
        );

        add_action(
            'save_post',
            [ Controller::class, 'wp2staticSavePostHandler' ],
            0
        );

        add_action(
            'trashed_post',
            [ Controller::class, 'wp2staticTrashedPostHandler' ],
            0
        );

        /*
         * Register actions for when we should invalidate cache for
         * a URL(s) or whole site
         *
         */
        $single_url_invalidation_events = [
            'save_post',
            'deleted_post',
        ];

        $full_site_invalidation_events = [
            'switch_theme',
        ];

        foreach ( $single_url_invalidation_events as $invalidation_events ) {
            add_action(
                $invalidation_events,
                [ Controller::class, 'invalidateSingleURLCache' ],
                10,
                2
            );
        }

    }

    /**
     * Add VibeStatic elements to WordPress Admin UI
     */
    public static function addAdminUIElements() : void {
        if ( is_admin() ) {
            add_action(
                'admin_menu',
                [ Controller::class, 'registerOptionsPage' ]
            );
            add_filter( 'custom_menu_order', '__return_true' );
            add_filter( 'menu_order', [ Controller::class, 'setMenuOrder' ] );
        }
    }

    /*
     * Do security checks before calling Controller::wp2staticProcessQueue
     */
    public static function adminPostProcessQueue() : void {
        // This method used to redo capability and nonce by hand, and did both
        // worse: no capability check at all, REQUEST_METHOD read through
        // filter_input( INPUT_SERVER, ... ) which returns null under FPM, and a
        // \RuntimeException thrown from an admin_post_ handler — that is, a
        // white screen instead of a message. The nonce name in the error text
        // was misspelled "wpstatic" on top of that.
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            WsLog::l( 'Non-POST request to admin-post.php (wp2static_process_queue)' );
            wp_die(
                esc_html__( 'Invalid request method.', 'vibestatic' ),
                '',
                [ 'response' => 405 ]
            );
        }

        Controller::authorize( 'wp2static_process_queue' );

        Controller::wp2staticProcessQueue();
    }

}
