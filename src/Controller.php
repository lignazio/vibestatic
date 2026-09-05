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
        // L'elenco delle tabelle sta in Schema, in un posto solo: prima era
        // qui, e all'aggiornamento del plugin non lo leggeva nessuno.
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
     * Checks if the named index exists. If it doesn't, create it. This won't
     * alter an existing index. If you need to change an index, give it a new name.
     *
     * WordPress's dbDelta is very unreliable for indexes. It tends to create duplicate
     * indexes, acts badly if whitespace isn't exactly what it expects, and fails
     * silently. It's okay to create the table and primary key with dbDelta,
     * but use ensureIndex for index creation.
     *
     * @param string $table_name The name of the table that the index is for.
     * @param string $index_name The name of the index.
     * @param string $create_index_sql The SQL to execute if the index needs to be created.
     * @return bool true if the index already exists or was created. false if creation failed.
     */
    /**
     * Crea un indice se non c'è già.
     *
     * Prima questo metodo accettava la CREATE INDEX già scritta dal chiamante,
     * cioè SQL grezzo che arrivava da fuori. Ora riceve i pezzi e costruisce
     * la query qui, con %i su ogni identificatore: non c'è più nessun punto
     * in cui una stringa di SQL attraversa il confine fra due classi.
     *
     * @param string[] $columns Colonne dell'indice
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
            // I due frammenti interpolati qui sotto non contengono dati: $create
            // è uno di due letterali, e $placeholders è una ripetizione di '%i'
            // lunga quanto $columns. Tutti gli identificatori veri passano da
            // %i, e MySQL non accetta un numero variabile di segnaposto in una
            // stringa che sia essa stessa un letterale, quindi la generazione
            // dev'essere dinamica. phpcs non può saperlo, e questa è la sola
            // soppressione SQL del progetto.
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
         * I titoli sono scritti, non calcolati. Prima erano `ucfirst( $slug )`,
         * cioe' le otto etichette piu' visibili del plugin non esistevano come
         * stringa da nessuna parte e non c'era niente da tradurre: qualunque
         * lingua avrebbe letto «Run», «Caches», «Addons». E `ucfirst()` non e'
         * nemmeno una regola tipografica valida fuori dall'inglese.
         *
         * @var array<string, array{0: string, 1: callable}> slug => [ etichetta, callback ]
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
    }

    /**
     * «VibeStatic Jobs», ma con l'ordine delle due parole nelle mani di chi
     * traduce: concatenare il nome del plugin davanti all'etichetta funziona in
     * inglese e in poco altro.
     */
    private static function pageTitle( string $label ) : string {
        return sprintf(
            /* translators: %s: name of the admin page, e.g. "Jobs". */
            __( 'VibeStatic %s', 'vibestatic' ),
            $label
        );
    }

    /**
     * @var array<string, string> slug della pagina => titolo
     */
    private static $hidden_page_titles = [];

    /**
     * Registra una pagina raggiungibile ma senza voce di menu.
     *
     * Il titolo va tenuto da parte, e non e' pignoleria. WordPress lo cerca con
     * `get_admin_page_title()`, che quando il genitore e' vuoto — cioe' per ogni
     * pagina nascosta — lo va a cercare fra i menu di primo livello, dove una
     * pagina nascosta per definizione non c'e'. Non trovandolo lascia `$title`
     * a null, e `admin-header.php` ci fa sopra `strip_tags()`: con WP_DEBUG
     * acceso, una deprecation di WordPress in cima a ognuna delle nostre pagine.
     *
     * Registrarle sotto il genitore vero e poi toglierle dal menu non aiuta:
     * `remove_submenu_page()` cancella proprio la voce da cui il titolo si
     * leggerebbe. E il genitore resta la stringa vuota, non `null`: la prima
     * cosa che `add_submenu_page()` fa e' passarlo a `plugin_basename()`, che
     * su null emette la stessa deprecation che si sta togliendo.
     *
     * @param string   $title    Titolo della pagina.
     * @param string   $slug     Slug, cioe' il valore di ?page=.
     * @param callable $callback Chi la disegna.
     */
    public static function addHiddenPage( string $title, string $slug, $callback ) : void {
        add_submenu_page( '', $title, $title, 'manage_options', $slug, $callback );

        if ( ! self::$hidden_page_titles ) {
            add_action( 'current_screen', [ self::class, 'setHiddenPageTitle' ] );
        }

        self::$hidden_page_titles[ $slug ] = $title;
    }

    /**
     * Da` un titolo alla pagina nascosta che si sta aprendo.
     *
     * Gira su `current_screen`, che WordPress lancia prima di includere
     * admin-header.php. `get_admin_page_title()` comincia con «se il titolo c'e'
     * gia', tienilo»: riempirlo qui e' quindi tutto quello che serve.
     */
    public static function setHiddenPageTitle() : void {
        $page = filter_input( INPUT_GET, 'page' );

        if ( ! is_string( $page ) || ! isset( self::$hidden_page_titles[ $page ] ) ) {
            return;
        }

        // @phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- è il titolo della nostra pagina.
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
     * Guardia per gli handler admin_post_*: prima il permesso, poi il nonce,
     * e comunque prima di qualunque scrittura.
     *
     * Il nonce dice da DOVE arriva la richiesta, non CHI la manda: da solo
     * lascia passare qualunque utente autenticato che sia stato indotto a
     * caricare la pagina delle opzioni. Su un multisite, un amministratore di
     * sotto-sito supera il primo controllo e non il secondo.
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
     * Come authorize(), per gli endpoint AJAX.
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
     * Lancia il deploy.
     *
     * L'unico punto da cui parte: prima lo stesso blocco era ripetuto tre
     * volte, due qui e una in CLI.
     *
     * Il rapporto su cosa cambiera' non si stampa qui, e non e' una svista.
     * `DeployCache::plan()` vuole sapere in quale spazio dei nomi il deployer
     * ha registrato quello che ha gia' pubblicato, e quel nome lo conosce il
     * deployer, non il core: stamparlo da qui vorrebbe dire indovinarlo, e un
     * numero indovinato in un rapporto e' peggio di nessun rapporto. Chi
     * deploya lo chiede e lo scrive — vedi l'addon directory-deployment.
     *
     * @param string $deployer Slug dell'addon che deploya.
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

        $site_url = SiteInfo::getUrl( 'site' );

        if ( ! is_string( $permalink ) || ! is_string( $site_url ) ) {
            return;
        }

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
         * Questo NON passa da __(). Il messaggio dell'email lo legge una
         * persona, quindi va nella lingua del sito; questo lo legge un
         * programma dall'altra parte del webhook, che si aspetta la stringa che
         * ha visto durante la configurazione. Tradurlo trasformerebbe un cambio
         * di lingua del sito in un guasto silenzioso di un'integrazione.
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

        // Prima era `echo $logs;` — testo arbitrario (URL crawlati, messaggi
        // d'errore di terzi) stampato grezzo in risposta a una richiesta AJAX.
        // Oggi finisce nel .val() di una textarea e non viene interpretato,
        // ma il giorno che qualcuno passa a .html() diventa una XSS, e nel
        // frattempo il sniff di escaping non ha modo di distinguere i due casi.
        // wp_send_json_success() codifica in JSON, che è il contratto AJAX
        // previsto da WordPress e che non altera il testo del log.
        wp_send_json_success( [ 'log' => WsLog::poll() ] );
    }
}
