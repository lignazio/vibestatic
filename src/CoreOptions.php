<?php

namespace WP2Static;

/*
    Simple interface to wp2static_core_options DB table


*/
class CoreOptions {

    /**
     * @var ?array<string, array<string, ?string>>
     */
    private static $cached_option_specs = null;

    /**
     * @var CoreOptionsRepository|null
     */
    private static $repository = null;

    /**
     * @param CoreOptionsRepository|null $repository Null per tornare al default.
     */
    public static function setRepository( ?CoreOptionsRepository $repository ) : void {
        self::$repository = $repository;
    }

    /**
     * @return CoreOptionsRepository Costruito su `global $wpdb` se non iniettato.
     */
    public static function repository() : CoreOptionsRepository {
        if ( ! self::$repository ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            self::$repository = new CoreOptionsRepository( $wpdb );
        }

        return self::$repository;
    }

    public static function init() : void {
        self::createTable();
        self::seedOptions();
    }

    public static function createTable() : void {
        self::repository()->createTable();
    }

    /**
     * @return array<string, ?string>
     */
    public static function makeOptionSpec(
        string $type,
        string $name,
        string $default_value,
        string $label,
        string $description,
        ?string $default_blob_value = null,
        ?string $filter_name = null
    ) : array {
        return [
            'type' => $type,
            'name' => $name,
            'default_value' => $default_value,
            'label' => $label,
            'description' => $description,
            'default_blob_value' => $default_blob_value,
            'filter_name' => $filter_name ? $filter_name : "wp2static_option_$name",
        ];
    }

    /**
     * @return array<string, array<string, ?string>>
     */
    public static function optionSpecs() : array {
        if ( self::$cached_option_specs ) {
            return self::$cached_option_specs;
        }

        $specs = [
            self::makeOptionSpec(
                'boolean',
                'detectCustomPostTypes',
                '1',
                'Detect Custom Post Types',
                'Include Custom Post Types in URL Detection.'
            ),
            self::makeOptionSpec(
                'boolean',
                'detectPages',
                '1',
                'Detect Pages',
                'Include Pages in URL Detection.'
            ),
            self::makeOptionSpec(
                'boolean',
                'detectPosts',
                '1',
                'Detect Posts',
                'Include Posts in URL Detection.'
            ),
            self::makeOptionSpec(
                'boolean',
                'detectUploads',
                '1',
                'Detect Uploads',
                'Include Uploads in URL Detection.'
            ),
            self::makeOptionSpec(
                'boolean',
                'queueJobOnPostSave',
                '1',
                'Post Save',
                'Queues a new job every time a Post or Page is saved.'
            ),
            self::makeOptionSpec(
                'boolean',
                'queueJobOnPostDelete',
                '1',
                'Post Delete',
                'Queues a new job every time a Post or Page is deleted.'
            ),
            self::makeOptionSpec(
                'boolean',
                'processQueueImmediately',
                '0',
                'Process Queue Immediately',
                'Begin processing the queue as soon as a job is added, without waiting for WP-Cron.'
            ),
            self::makeOptionSpec(
                'integer',
                'processQueueInterval',
                '0',
                'Process Queue Interval',
                'WP-Cron will attempt to process the job queue at this interval'
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueDetection',
                '1',
                'Detect URLs',
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueCrawling',
                '1',
                'Crawl Site',
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueuePostProcessing',
                '1',
                'Post-Process',
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueDeployment',
                '1',
                'Deploy',
                ''
            ),
            self::makeOptionSpec(
                'string',
                'basicAuthUser',
                '',
                'Basic Auth User',
                'Username for basic authentication.'
            ),
            self::makeOptionSpec(
                'string',
                'deploymentURL',
                'https://example.com',
                'Deployment URL',
                'URL your static site will be hosted at.'
            ),
            self::makeOptionSpec(
                'password',
                'basicAuthPassword',
                '',
                'Basic Auth Password',
                'Password for basic authentication.'
            ),
            self::makeOptionSpec(
                'boolean',
                'useCrawlCaching',
                '1',
                'Use CrawlCache',
                'Skip crawling unchanged URLs.',
                null,
                'wp2static_use_crawl_cache'
            ),
            self::makeOptionSpec(
                'string',
                'completionEmail',
                '',
                'Completion Email',
                'Email to send deployment completion notification to.'
            ),
            self::makeOptionSpec(
                'string',
                'completionWebhook',
                '',
                'Completion Webhook',
                'Webhook to send deployment completion notification to.'
            ),
            self::makeOptionSpec(
                'string',
                'completionWebhookMethod',
                'POST',
                'Completion Webhook Method',
                'How to send completion webhook payload (GET|POST).'
            ),

            // Advanced options
            self::makeOptionSpec(
                'integer',
                'crawlConcurrency',
                '1',
                'Crawl Concurrency',
                'The maximum number of files that will be crawled at the same time.'
            ),
            self::makeOptionSpec(
                'array',
                'fileExtensionsToIgnore',
                '1',
                'File Extensions to Ignore',
                'Files with these extensions will be ignored while crawling.',
                implode(
                    "\n",
                    [
                        '.bat',
                        '.crt',
                        '.DS_Store',
                        '.git',
                        '.idea',
                        '.ini',
                        '.less',
                        '.map',
                        '.md',
                        '.mo',
                        '.php',
                        '.PHP',
                        '.phtml',
                        '.po',
                        '.pot',
                        '.scss',
                        '.sh',
                        '.sql',
                        '.SQL',
                        '.tar.gz',
                        '.tpl',
                        '.txt',
                        '.yarn',
                        '.zip',
                    ]
                )
            ),
            self::makeOptionSpec(
                'array',
                'filenamesToIgnore',
                '1',
                'Directory and File Names to Ignore',
                'Directories and files with these names will be ignored while crawling.',
                implode(
                    "\n",
                    [
                        '__MACOSX',
                        '.babelrc',
                        '.git',
                        '.gitignore',
                        '.gitkeep',
                        '.htaccess',
                        '.php',
                        '.svn',
                        '.travis.yml',
                        'backwpup',
                        'bower_components',
                        'bower.json',
                        'composer.json',
                        'composer.lock',
                        'config.rb',
                        'current-export',
                        'Dockerfile',
                        'gulpfile.js',
                        'latest-export',
                        'LICENSE',
                        'Makefile',
                        'node_modules',
                        'package.json',
                        'pb_backupbuddy',
                        'plugins/wp2static',
                        'previous-export',
                        'README',
                        'static-html-output-plugin',
                        '/tests/',
                        'thumbs.db',
                        'tinymce',
                        'wc-logs',
                        'wpallexport',
                        'wpallimport',
                        'wp-static-html-output', // exclude earlier version exports
                        'wp2static-addon',
                        'wp2static-crawled-site',
                        'wp2static-processed-site',
                        'wp2static-working-files',
                        'yarn-error.log',
                        'yarn.lock',
                    ]
                )
            ),
            self::makeOptionSpec(
                'array',
                'hostsToRewrite',
                '1',
                'Hosts to Rewrite',
                'Hosts to rewrite to the deployment URL.',
                'localhost'
            ),
            self::makeOptionSpec(
                'boolean',
                'skipURLRewrite',
                '0',
                'Skip URL Rewrite',
                'Don\'t rewrite any URLs. This may give a slight speed-up when the'
                . ' deployment URL is the same as WordPress\'s URL.'
            ),
            self::makeOptionSpec(
                'boolean',
                'removeWordPressCruft',
                '0',
                'Remove WordPress emoji and RSD output',
                'Removes the emoji scripts, the wlwmanifest link and the wp-embed and'
                . ' comment-reply scripts. This changes your LIVE site as well as the'
                . ' exported one, on purpose: a static copy should look like what your'
                . ' visitors actually get.'
            ),
        ];

        $ret = [];
        foreach ( $specs as $s ) {
            $ret[ $s['name'] ] = $s;
        }
        self::$cached_option_specs = $ret;
        return $ret;
    }

    /**
     * Seed options
     */
    public static function seedOptions() : void {
        self::repository()->seedOptions( self::optionSpecs() );
    }

    /**
     * Get option value
     *
     * @throws WP2StaticException
     * @return string option value
     */
    public static function getValue( string $name ) : string {
        /*
         * `?? null`, non l'accesso diretto. La riga era
         * `$opt_spec = self::optionSpecs()[ $name ];` seguita da
         * `if ( ! $opt_spec )`: su un nome sconosciuto PHP emette
         * «Undefined array key» PRIMA di arrivare alla guardia, quindi il
         * messaggio che avvisa dell'opzione sconosciuta non e' mai stato
         * stampato — al suo posto c'era un warning che non nomina l'opzione.
         */
        $opt_spec = self::optionSpecs()[ $name ] ?? null;

        if ( ! $opt_spec ) {
            WsLog::w( "Attempt to getValue of unknown option $name" );
            return '';
        }

        $option_value = self::repository()->getValue( $name );

        if ( ! $option_value ) {
            $option_value = (string) $opt_spec['default_value'];
        }

        if ( $opt_spec['type'] === 'password' ) {
            $option_value = self::encrypt_decrypt( 'decrypt', $option_value );
        }

        // default deploymentURL is '/', else remove trailing slash
        if ( $name === 'deploymentURL' ) {
            if ( $option_value !== '/' ) {
                $option_value = untrailingslashit( $option_value );
            }
        }

        $option_value = apply_filters( (string) $opt_spec['filter_name'], $option_value );

        return $option_value;
    }

    /**
     * Get option BLOB value
     *
     * @throws WP2StaticException
     * @return string option BLOB value
     */
    public static function getBlobValue( string $name ) : string {
        $option_value = self::repository()->getBlobValue( $name );

        if ( ! is_string( $option_value ) ) {
            $os = self::optionSpecs()[ $name ] ?? null;

            if ( ! $os ) {
                return '';
            }

            $option_value = (string) $os['default_blob_value'];
        }

        return $option_value;
    }

    /**
     * @return array<string>
     */
    public static function getLineDelimitedBlobValue( string $name ) : array {
        $vals = preg_split(
            '/\r\n|\r|\n/',
            self::getBlobValue( $name )
        );

        if ( ! $vals ) {
            return [];
        }

        return $vals;
    }

    /**
     * Get option default BLOB value
     *
     * @throws WP2StaticException
     * @return string option default BLOB value
     */
    public static function getDefaultBlobValue( string $name ) : string {
        $val = self::optionSpecs()[ $name ]['default_blob_value'] ?? null;

        return $val ? $val : '';
    }

    /**
     * @return array<string>
     */
    public static function getDefaultLineDelimitedBlobValue( string $name ) : array {
        $vals = preg_split(
            '/\r\n|\r|\n/',
            self::getDefaultBlobValue( $name )
        );

        if ( ! $vals ) {
            return [];
        }

        return $vals;
    }

    /**
     * Get option (value, description, label, etc)
     *
     * @return mixed option
     */
    public static function get( string $name ) {
        $opt_spec = self::optionSpecs()[ $name ] ?? null;

        if ( ! $opt_spec ) {
            WsLog::w( "Attempt to get unknown option $name" );

            return null;
        }

        $option = self::repository()->getRow( $name );

        /*
         * La decifratura stava PRIMA del controllo su $option, e leggeva
         * `$option->value` su un risultato che puo' essere null: un'opzione di
         * tipo password non ancora salvata dava un fatal error, non un valore
         * di partenza. L'ordine giusto e' guardare se la riga c'e'.
         */
        if ( ! $option ) {
            // Make a copy so we don't modify $cached_option_specs
            $opt = array_merge( $opt_spec );
            $opt['unfiltered_value'] = $opt_spec['default_value'];
            $opt['blob_value'] = $opt_spec['default_blob_value'];

            if ( $opt_spec['filter_name'] ) {
                $opt['value'] = apply_filters(
                    $opt_spec['filter_name'],
                    $opt_spec['default_value']
                );
            } else {
                $opt['value'] = $opt_spec['default_value'];
            }

            return $opt;
        }

        // decrypt password fields
        if ( $opt_spec['type'] === 'password' && is_string( $option->value ) ) {
            $option->value = self::encrypt_decrypt( 'decrypt', $option->value );
        }

        $option->unfiltered_value = $option->value;
        $option->value = apply_filters( (string) $opt_spec['filter_name'], $option->value );

        return (object) array_merge( $opt_spec, (array) $option );
    }

    /**
     * Get all options (value, description, label, etc)
     *
     * @return array<string, mixed> array of option name to option object
     */
    public static function getAll() {
        $options_map = self::repository()->getAllRows();

        $ret = [];
        foreach ( self::optionSpecs() as $opt_spec ) {
            $name = (string) $opt_spec['name'];
            // `?? null`: un'opzione definita nel codice ma non ancora nella
            // tabella — cioe' ogni opzione nuova, fra l'aggiornamento del
            // plugin e la prima seedOptions() — passava di qui con un
            // «Undefined array key» prima della guardia che la gestisce.
            $opt = $options_map[ $name ] ?? null;
            if ( ! $opt ) {
                 // Make a copy so we don't modify $cached_option_specs
                $opt = array_merge( $opt_spec );
                $opt['unfiltered_value'] = $opt_spec['default_value'];
                $opt['blob_value'] = $opt_spec['default_blob_value'];
                $opt['value'] = apply_filters(
                    (string) $opt_spec['filter_name'],
                    $opt_spec['default_value']
                );
                $ret[ $name ] = $opt;
            } else {
                $val = $opt['value'];

                if ( $opt_spec['type'] === 'password' ) {
                    $val = self::encrypt_decrypt( 'decrypt', $val );
                }

                $opt['unfiltered_value'] = $val;
                $opt['value'] = apply_filters( (string) $opt_spec['filter_name'], $val );
                $ret[ $name ] = (object) array_merge( $opt_spec, $opt );
            }
        }

        return $ret;
    }

    /*
     * Naive encypting/decrypting
     *
     * @throws WP2StaticException
     */
    public static function encrypt_decrypt( string $action, string $string ) : string {
        $encrypt_method = 'AES-256-CBC';

        /*
         * Quando AUTH_KEY o AUTH_SALT mancano, qui c'erano due chiavi scritte
         * nel codice. Il codice e' pubblico: cifrare la password della basic
         * auth con una chiave che chiunque puo' leggere su GitHub non e'
         * cifrarla, e` codificarla — con l'aggravante che sembra cifrata.
         *
         * WordPress quelle due costanti le genera in fase di installazione, e
         * un sito che non le ha e' un sito rotto. Meglio dirlo che ripiegare.
         */
        $auth_key = defined( 'AUTH_KEY' ) ? constant( 'AUTH_KEY' ) : '';
        $auth_salt = defined( 'AUTH_SALT' ) ? constant( 'AUTH_SALT' ) : '';

        $secret_key = is_string( $auth_key ) ? $auth_key : '';
        $secret_iv = is_string( $auth_salt ) ? $auth_salt : '';

        if ( '' === $secret_key || '' === $secret_iv ) {
            throw new WP2StaticException(
                'VibeStatic non puo\' proteggere le credenziali salvate:' .
                ' AUTH_KEY e AUTH_SALT non sono definite in wp-config.php.' .
                ' Generale su https://api.wordpress.org/secret-key/1.1/salt/' .
                ' e riprova.'
            );
        }

        $key = hash( 'sha256', $secret_key );
        $variate = substr( hash( 'sha256', $secret_iv ), 0, 32 );
        $hex_key = (string) hex2bin( $key );
        $hex_iv = (string) hex2bin( $variate );

        if ( $action == 'decrypt' ) {
            return (string) openssl_decrypt(
                (string) base64_decode( $string ),
                $encrypt_method,
                $hex_key,
                0,
                $hex_iv
            );
        }

        $output = openssl_encrypt( $string, $encrypt_method, $hex_key, 0, $hex_iv );

        return (string) base64_encode( (string) $output );
    }

    /**
     * Save all options POST'ed via UI
     *
     * Il nonce e la capability li verifica Controller::authorize(), che ogni
     * handler admin_post_* chiama come prima istruzione — prima di arrivare
     * qui. phpcs non può seguirlo attraverso il confine fra due classi.
     *
     * @todo Fase 5: questo metodo non dovrebbe leggere $_POST da sé. Deve
     *       ricevere i dati già validati; finché li legge, la verifica del
     *       nonce e il posto dove si usano i dati restano in due file diversi,
     *       ed è esattamente la distanza che aveva permesso ai tre handler di
     *       scrivere prima di controllare.
     */
    // phpcs:disable WordPress.Security.NonceVerification
    public static function savePosted( string $screen = 'core' ) : void {
        switch ( $screen ) {
            case 'core':
                self::repository()->update(
                    'detectCustomPostTypes',
                    [ 'value' => isset( $_POST['detectCustomPostTypes'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'detectPosts',
                    [ 'value' => isset( $_POST['detectPosts'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'detectPages',
                    [ 'value' => isset( $_POST['detectPages'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'detectUploads',
                    [ 'value' => isset( $_POST['detectUploads'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'deploymentURL',
                    [
                        'value' =>
                        esc_url_raw( strval( filter_input( INPUT_POST, 'deploymentURL' ) ) ),
                    ]
                );

                self::repository()->update(
                    'basicAuthUser',
                    [
                        'value' =>
                        sanitize_text_field(
                            strval( filter_input( INPUT_POST, 'basicAuthUser' ) )
                        ),
                    ]
                );

                self::repository()->update(
                    'basicAuthPassword',
                    [
                        'value' =>
                        self::encrypt_decrypt(
                            'encrypt',
                            sanitize_text_field(
                                strval( filter_input( INPUT_POST, 'basicAuthPassword' ) )
                            )
                        ),
                    ]
                );

                self::repository()->update(
                    'useCrawlCaching',
                    [ 'value' => isset( $_POST['useCrawlCaching'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'completionEmail',
                    [
                        'value' =>
                        sanitize_text_field(
                            strval( filter_input( INPUT_POST, 'completionEmail' ) )
                        ),
                    ]
                );

                self::repository()->update(
                    'completionWebhook',
                    [
                        'value' =>
                        esc_url_raw( strval( filter_input( INPUT_POST, 'completionWebhook' ) ) ),
                    ]
                );

                self::repository()->update(
                    'completionWebhookMethod',
                    [
                        'value' =>
                        sanitize_text_field(
                            strval( filter_input( INPUT_POST, 'completionWebhookMethod' ) )
                        ),
                    ]
                );

                break;
            case 'jobs':
                $queue_on_post_save = isset( $_POST['queueJobOnPostSave'] ) ? 1 : 0;
                $queue_on_post_delete = isset( $_POST['queueJobOnPostDelete'] ) ? 1 : 0;
                $process_queue_immediately = isset( $_POST['processQueueImmediately'] ) ? 1 : 0;

                self::repository()->update(
                    'queueJobOnPostSave',
                    [ 'value' => $queue_on_post_save ]
                );

                self::repository()->update(
                    'queueJobOnPostDelete',
                    [ 'value' => $queue_on_post_delete ]
                );

                self::repository()->update(
                    'processQueueImmediately',
                    [ 'value' => $process_queue_immediately ]
                );

                /**
                 * @var int $process_queue_interval
                 */
                $process_queue_interval = isset( $_POST['processQueueInterval'] )
                    ? absint( wp_unslash( $_POST['processQueueInterval'] ) )
                    : 0;

                self::repository()->update(
                    'processQueueInterval',
                    [ 'value' => $process_queue_interval ]
                );

                WPCron::setRecurringEvent( $process_queue_interval );

                self::repository()->update(
                    'autoJobQueueDetection',
                    [ 'value' => isset( $_POST['autoJobQueueDetection'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'autoJobQueueCrawling',
                    [ 'value' => isset( $_POST['autoJobQueueCrawling'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'autoJobQueuePostProcessing',
                    [ 'value' => isset( $_POST['autoJobQueuePostProcessing'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'autoJobQueueDeployment',
                    [ 'value' => isset( $_POST['autoJobQueueDeployment'] ) ? 1 : 0 ]
                );

                break;
            case 'advanced':
                $crawl_concurrency = isset( $_POST['crawlConcurrency'] )
                    ? absint( wp_unslash( $_POST['crawlConcurrency'] ) )
                    : 1;
                self::repository()->update(
                    'crawlConcurrency',
                    [ 'value' => $crawl_concurrency < 1 ? 1 : $crawl_concurrency ]
                );

                $file_extensions_to_ignore = preg_replace(
                    '/^\s+|\s+$/m',
                    '',
                    strval( filter_input( INPUT_POST, 'fileExtensionsToIgnore' ) )
                );
                self::repository()->update(
                    'fileExtensionsToIgnore',
                    [ 'blob_value' => $file_extensions_to_ignore ]
                );

                $filenames_to_ignore = preg_replace(
                    '/^\s+|\s+$/m',
                    '',
                    strval( filter_input( INPUT_POST, 'filenamesToIgnore' ) )
                );
                self::repository()->update(
                    'filenamesToIgnore',
                    [ 'blob_value' => $filenames_to_ignore ]
                );

                $hosts_to_rewrite = preg_replace(
                    '/^\s+|\s+$/m',
                    '',
                    strval( filter_input( INPUT_POST, 'hostsToRewrite' ) )
                );
                self::repository()->update(
                    'hostsToRewrite',
                    [ 'blob_value' => $hosts_to_rewrite ]
                );

                self::repository()->update(
                    'skipURLRewrite',
                    [ 'value' => isset( $_POST['skipURLRewrite'] ) ? 1 : 0 ]
                );

                self::repository()->update(
                    'removeWordPressCruft',
                    [ 'value' => isset( $_POST['removeWordPressCruft'] ) ? 1 : 0 ]
                );
                break;
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification

    /**
     * Save individual option
     *
     * @param mixed $value Updated option value
     */
    public static function save( string $name, $value ) : void {
        // TODO: some validation on save types
        self::repository()->update( $name, [ 'value' => $value ] );
    }
}
