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
         * L'attivazione non scatta a ogni aggiornamento del plugin, quindi da
         * sola non basta a portare a destinazione una modifica dello schema.
         *
         * Su `init` e non su `plugins_loaded`, dove stava: l'aggiornamento passa
         * da `CoreOptions::seedOptions()`, quindi da `optionSpecs()`, dove
         * adesso le etichette sono avvolte in `__()`. Chiedere una traduzione
         * prima di `after_setup_theme` fa scattare il `_doing_it_wrong` che
         * WordPress 6.7 ha aggiunto a `_load_textdomain_just_in_time()`, e lo fa
         * soltanto dove una traduzione del dominio esiste davvero — cioe' mai
         * durante lo sviluppo in inglese, e sempre da chi il plugin lo usa
         * tradotto. La priorita' 5 tiene l'aggiornamento dello schema prima di
         * qualunque altro gancio su `init` che legga un'opzione.
         */
        add_action( 'init', [ Schema::class, 'updateIfNeeded' ], 5 );

        // Aggiornamenti per chi ha installato da zip. Registra solo dei filtri:
        // la chiamata a GitHub parte quando WordPress controlla, non adesso.
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
        // Prima questo metodo rifaceva a mano permesso e nonce, e li faceva
        // peggio: nessun controllo di capability, REQUEST_METHOD letto con
        // filter_input( INPUT_SERVER, ... ) che sotto FPM torna null, e un
        // \RuntimeException lanciato da un handler admin_post_ — cioè una
        // schermata bianca al posto di un messaggio. Il nome del nonce nel
        // messaggio d'errore era per giunta scritto male, "wpstatic".
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
