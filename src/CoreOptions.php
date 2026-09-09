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
     * @param CoreOptionsRepository|null $repository Null to fall back to the default.
     */
    public static function setRepository( ?CoreOptionsRepository $repository ) : void {
        self::$repository = $repository;
    }

    /**
     * @return CoreOptionsRepository Built on `global $wpdb` when not injected.
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
                __(
                    'Detect Custom Post Types',
                    'vibestatic'
                ),
                __(
                    'Include Custom Post Types in URL Detection.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'detectPages',
                '1',
                __(
                    'Detect Pages',
                    'vibestatic'
                ),
                __(
                    'Include Pages in URL Detection.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'detectPosts',
                '1',
                __(
                    'Detect Posts',
                    'vibestatic'
                ),
                __(
                    'Include Posts in URL Detection.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'detectUploads',
                '0',
                __(
                    'Detect Uploads',
                    'vibestatic'
                ),
                __(
                    'Queue every file under uploads, whether a page links to it or not. Off by default: what the pages reference is picked up while crawling, which covers the media the site actually shows and keeps the published copy the size of the site rather than of the media library. Turn it on when something has to be published that no crawled page mentions — an image whose src is built in JavaScript, a background coming from a stylesheet, a PDF linked only from an email or another site. It also publishes every thumbnail size WordPress generated and whatever other plugins keep under uploads.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'queueJobOnPostSave',
                '1',
                __(
                    'Post Save',
                    'vibestatic'
                ),
                __(
                    'Queues a new job every time a Post or Page is saved.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'queueJobOnPostDelete',
                '1',
                __(
                    'Post Delete',
                    'vibestatic'
                ),
                __(
                    'Queues a new job every time a Post or Page is deleted.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'processQueueImmediately',
                '0',
                __(
                    'Process Queue Immediately',
                    'vibestatic'
                ),
                __(
                    'Begin processing the queue as soon as a job is added, without waiting for WP-Cron.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'integer',
                'processQueueInterval',
                '0',
                __(
                    'Process Queue Interval',
                    'vibestatic'
                ),
                __(
                    'WP-Cron will attempt to process the job queue at this interval',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueDetection',
                '1',
                __(
                    'Detect URLs',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueCrawling',
                '1',
                __(
                    'Crawl Site',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueuePostProcessing',
                '1',
                __(
                    'Post-Process',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'boolean',
                'autoJobQueueDeployment',
                '1',
                __(
                    'Deploy',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'string',
                'basicAuthUser',
                '',
                __(
                    'Basic Auth User',
                    'vibestatic'
                ),
                __(
                    'Username for basic authentication.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'deploymentURL',
                'https://example.com',
                __(
                    'Deployment URL',
                    'vibestatic'
                ),
                __(
                    'URL your static site will be hosted at.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'password',
                'basicAuthPassword',
                '',
                __(
                    'Basic Auth Password',
                    'vibestatic'
                ),
                __(
                    'Password for basic authentication.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'useCrawlCaching',
                '1',
                __(
                    'Use CrawlCache',
                    'vibestatic'
                ),
                __(
                    'Skip crawling unchanged URLs.',
                    'vibestatic'
                ),
                null,
                'wp2static_use_crawl_cache'
            ),
            self::makeOptionSpec(
                'boolean',
                'addURLsWhileCrawling',
                '1',
                __(
                    'Follow links while crawling',
                    'vibestatic'
                ),
                __(
                    'Also crawl URLs found in the pages as they are crawled, not only the ones detection produced. It reads img src and srcset, video, audio, source, link and script, so it is what brings the media a page shows into the published site, and it finds pages reachable by a link and nothing else — a hand-written link in a post, a route a plugin renders. On by default: with "Detect Uploads" off, this is what keeps the images in the site.',
                    'vibestatic'
                ),
                null,
                'wp2static_add_urls_while_crawling'
            ),
            self::makeOptionSpec(
                'string',
                'snipcartApiKey',
                '',
                __(
                    'Snipcart public API key',
                    'vibestatic'
                ),
                __(
                    'For a WooCommerce shop being published as a static site: with a key here, the add-to-cart buttons are rewritten for Snipcart, which is a cart that runs in the browser. WooCommerce\'s own cart is PHP and cannot be published. Empty means no page is touched. Shown only where WooCommerce is active.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'formAction',
                '',
                __(
                    'Form Endpoint',
                    'vibestatic'
                ),
                __(
                    'Where the published forms should post. A static site cannot run PHP, so a form left pointing at WordPress is a button that does nothing; give it the URL of a form service — Formspree, Basin, Web3Forms — and it will work. Empty means no form is touched at all.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'formProvider',
                'endpoint',
                __(
                    'Form Handling',
                    'vibestatic'
                ),
                __(
                    '"endpoint" points forms at the URL above. "netlify" instead marks them for Netlify Forms, which is not a URL but two marks on the markup — use it only when the site is deployed to Netlify.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'array',
                'formRules',
                '1',
                __(
                    'Form Rules',
                    'vibestatic'
                ),
                __(
                    'One rule per line, as "id = endpoint", where the id is the form\'s id or name attribute. It overrides the endpoint above for that one form. Leave the endpoint empty — "id =" — to leave that form exactly as it is.',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'array',
                'additionalPathsToCrawl',
                '1',
                __(
                    'Additional Paths to Crawl',
                    'vibestatic'
                ),
                __(
                    'One path per line, for what nothing enumerates and nothing links to: a file put on the server by hand, a route a plugin answers without a post behind it. They are added to detection, not to the crawl queue directly — anything the detection does not name is removed from the queue on the next run.',
                    'vibestatic'
                ),
                ''
            ),
            self::makeOptionSpec(
                'integer',
                'crawlChunkSize',
                '0',
                __(
                    'Crawl Chunk Size',
                    'vibestatic'
                ),
                __(
                    'How many URLs to hold in memory at a time. 0 takes the whole queue at once, which is what it has always done and is fine for most sites; set a few thousand if the crawl runs out of memory on a very large one.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'integer',
                'crawlProgressReportInterval',
                '300',
                __(
                    'Crawl Progress Interval',
                    'vibestatic'
                ),
                __(
                    'Write a progress line to the log every this many URLs. 0 turns the running count off and leaves only the line at the end.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'detectRedirectionPluginURLs',
                '0',
                __(
                    'Detect Redirection plugin URLs',
                    'vibestatic'
                ),
                __(
                    "Crawl the source addresses of the redirects the Redirection plugin manages, so the 301s they answer with end up in the static site. Off where that plugin is not installed: it does nothing.",
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'completionEmail',
                '',
                __(
                    'Completion Email',
                    'vibestatic'
                ),
                __(
                    'Email to send deployment completion notification to.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'completionWebhook',
                '',
                __(
                    'Completion Webhook',
                    'vibestatic'
                ),
                __(
                    'Webhook to send deployment completion notification to.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'string',
                'completionWebhookMethod',
                'POST',
                __(
                    'Completion Webhook Method',
                    'vibestatic'
                ),
                __(
                    'How to send completion webhook payload (GET|POST).',
                    'vibestatic'
                )
            ),

            // Advanced options
            self::makeOptionSpec(
                'integer',
                'crawlConcurrency',
                '1',
                __(
                    'Crawl Concurrency',
                    'vibestatic'
                ),
                __(
                    'The maximum number of files that will be crawled at the same time.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'array',
                'fileExtensionsToIgnore',
                '1',
                __(
                    'File Extensions to Ignore',
                    'vibestatic'
                ),
                __(
                    'Files with these extensions will be ignored while crawling.',
                    'vibestatic'
                ),
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
                __(
                    'Directory and File Names to Ignore',
                    'vibestatic'
                ),
                __(
                    'Directories and files with these names will be ignored while crawling.',
                    'vibestatic'
                ),
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
                        /*
                          * The plugin's own directory. These were two stale
                          * names — `plugins/wp2static` and `wp2static-addon` —
                          * left over from the rename: since then the plugin
                          * stopped excluding itself, and the crawl published
                          * `wp-content/plugins/vibestatic` (WordPress's 404 for
                          * that URL, 84 KB) into the static site. The old names
                          * stay: anyone arriving from WP2Static still has those
                          * directories on disk.
                          */
                        'plugins/vibestatic',
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
                        'vibestatic-addon',
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
                __(
                    'Hosts to Rewrite',
                    'vibestatic'
                ),
                __(
                    'Hosts to rewrite to the deployment URL.',
                    'vibestatic'
                ),
                'localhost'
            ),
            self::makeOptionSpec(
                'boolean',
                'skipURLRewrite',
                '0',
                __(
                    'Skip URL Rewrite',
                    'vibestatic'
                ),
                __(
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- gettext wants a single literal: splitting it with `.` makes it unextractable.
                    'Do not rewrite any URLs. This may give a slight speed-up when the deployment URL is the same as the WordPress URL.',
                    'vibestatic'
                )
            ),
            self::makeOptionSpec(
                'boolean',
                'removeWordPressCruft',
                '0',
                __(
                    'Remove WordPress emoji and RSD output',
                    'vibestatic'
                ),
                __(
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- gettext wants a single literal: splitting it with `.` makes it unextractable.
                    'Removes the emoji scripts, the wlwmanifest link and the wp-embed and comment-reply scripts. This changes your LIVE site as well as the exported one, on purpose: a static copy should look like what your visitors actually get.',
                    'vibestatic'
                )
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
     * Run an option's filter, when it has a name to run.
     *
     * `apply_filters()` wants a non-empty hook name, and `filter_name` comes
     * out of the option specification as `mixed`: four call sites cast it to
     * string, which turns a missing name into `''` and then fires a filter
     * called nothing. An option without a filter name simply has no filter.
     *
     * @param array<string, mixed> $opt_spec The option's specification.
     * @param mixed                $value    What to filter.
     * @return mixed The filtered value, or the value unchanged.
     */
    private static function applyOptionFilter( array $opt_spec, $value ) {
        $hook = $opt_spec['filter_name'] ?? '';

        if ( ! is_string( $hook ) || '' === $hook ) {
            return $value;
        }

        return apply_filters( $hook, $value );
    }

    /**
     * Get option value
     *
     * @throws WP2StaticException
     * @return string option value
     */
    public static function getValue( string $name ) : string {
        /*
         * `?? null`, not direct access. The line used to be
         * `$opt_spec = self::optionSpecs()[ $name ];` followed by
         * `if ( ! $opt_spec )`: on an unknown name PHP emits "Undefined array
         * key" BEFORE reaching the guard, so the message written to warn about
         * an unknown option was never printed — in its place came a warning
         * that does not name the option.
         */
        $opt_spec = self::optionSpecs()[ $name ] ?? null;

        if ( ! $opt_spec ) {
            WsLog::w( "Attempt to getValue of unknown option $name" );
            return '';
        }

        $option_value = self::repository()->getValue( $name );

        /*
         * `null === … || '' === …`, not `! $option_value`.
         *
         * The string '0' is falsy in PHP, so the old test sent every option
         * stored as zero back to its own default — and thirteen of them have a
         * default that is not zero. In plain terms: unticking a box on the
         * Options page saved a 0 that was then read back as a 1. The crawl
         * cache could not be turned off, none of the four detection toggles
         * could be turned off, and neither could the four job-queue ones. The
         * setting was written, the interface showed it as written, and nothing
         * downstream ever saw it.
         *
         * The repository already answers null for a row that is not there, so
         * "not set" and "set to zero" were distinguishable all along.
         */
        if ( null === $option_value || '' === $option_value ) {
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

        $option_value = self::applyOptionFilter( $opt_spec, $option_value );

        /*
         * Declared to return a string, and the last thing that touched the
         * value was somebody else's filter. Casting quietly would turn an array
         * into "Array"; this says what happened and falls back to the default,
         * which is a value the plugin chose.
         */
        if ( ! is_string( $option_value ) ) {
            if ( is_scalar( $option_value ) ) {
                return (string) $option_value;
            }

            WsLog::l(
                "A filter on the '$name' option returned " . gettype( $option_value ) .
                '; using the default.'
            );

            return (string) $opt_spec['default_value'];
        }

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
         * Decryption used to sit BEFORE the check on $option, reading
         * `$option->value` on a result that can be null: a password option that
         * had never been saved gave a fatal error rather than a default. The
         * right order is to look whether the row is there first.
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
        $option->value = self::applyOptionFilter( $opt_spec, $option->value );

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
            // `?? null`: an option defined in code but not yet in the table
            // — that is, every new option, between the plugin update and the
            // first seedOptions() — came through here with an "Undefined array
            // key" before the guard meant to handle it.
            $opt = $options_map[ $name ] ?? null;
            if ( ! $opt ) {
                 // Make a copy so we don't modify $cached_option_specs
                $opt = array_merge( $opt_spec );
                $opt['unfiltered_value'] = $opt_spec['default_value'];
                $opt['blob_value'] = $opt_spec['default_blob_value'];
                $opt['value'] = self::applyOptionFilter( $opt_spec, $opt_spec['default_value'] );
                $ret[ $name ] = $opt;
            } else {
                $val = $opt['value'];

                if ( $opt_spec['type'] === 'password' && is_string( $val ) ) {
                    $val = self::encrypt_decrypt( 'decrypt', $val );
                }

                $opt['unfiltered_value'] = $val;
                $opt['value'] = self::applyOptionFilter( $opt_spec, $val );
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
         * When AUTH_KEY or AUTH_SALT are missing, there used to be two keys
         * written into the source here. The source is public: encrypting the
         * basic auth password with a key anyone can read on GitHub is not
         * encrypting it, it is encoding it — with the added harm that it looks
         * encrypted.
         *
         * WordPress generates those two constants at install time, and a site
         * without them is a broken site. Better to say so than to fall back.
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
     * Nonce and capability are checked by Controller::authorize(), which every
     * admin_post_* handler calls as its first statement — before reaching here.
     * phpcs cannot follow that across a class boundary.
     *
     * @todo This method should not read $_POST itself. It should receive
     *       already-validated data; while it reads them, the nonce check and
     *       the place the data is used stay in two different files, and that is
     *       exactly the distance that let three handlers write before checking.
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
                    'addURLsWhileCrawling',
                    [ 'value' => isset( $_POST['addURLsWhileCrawling'] ) ? 1 : 0 ]
                );

                foreach ( [ 'formAction', 'formProvider', 'snipcartApiKey' ] as $form_option ) {
                    self::repository()->update(
                        $form_option,
                        [ 'value' => sanitize_text_field( strval( filter_input( INPUT_POST, $form_option ) ) ) ]
                    );
                }

                self::repository()->update(
                    'detectRedirectionPluginURLs',
                    [ 'value' => isset( $_POST['detectRedirectionPluginURLs'] ) ? 1 : 0 ]
                );

                foreach ( [ 'crawlChunkSize', 'crawlProgressReportInterval' ] as $crawl_number ) {
                    self::repository()->update(
                        $crawl_number,
                        [ 'value' => max( 0, intval( filter_input( INPUT_POST, $crawl_number ) ) ) ]
                    );
                }

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
                    && is_scalar( $_POST['processQueueInterval'] )
                    ? absint( wp_unslash( (string) $_POST['processQueueInterval'] ) )
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
                    && is_scalar( $_POST['crawlConcurrency'] )
                    ? absint( wp_unslash( (string) $_POST['crawlConcurrency'] ) )
                    : 1;
                self::repository()->update(
                    'crawlConcurrency',
                    [ 'value' => $crawl_concurrency < 1 ? 1 : $crawl_concurrency ]
                );

                /*
                 * `isset()` before writing, and this is not pedantry.
                 *
                 * `filter_input( INPUT_POST, 'filenamesToIgnore' )` returns
                 * null both when the field was deliberately emptied and when it
                 * was not submitted at all, and `strval( null )` collapses the
                 * two cases into one: an empty string, written over the good
                 * value. A request that does not carry that field — a form
                 * missing the control, a hand-built POST — would wipe a
                 * forty-line hand-curated list, silently and irreversibly.
                 *
                 * It actually happened on this installation: during the window
                 * when `OptionRenderer` returned escaped markup, the Advanced
                 * page showed text instead of fields, so a save submitted no
                 * textarea at all — and the two exclusion lists were left
                 * empty. With `filenamesToIgnore` empty the crawl excludes
                 * nothing: `.git`, `node_modules` and `composer.lock` all
                 * become candidates for export.
                 *
                 * Absent means "leave it alone". Emptying a list is still
                 * possible, but it has to be asked for by sending the field
                 * empty.
                 */
                foreach (
                    [
                        'additionalPathsToCrawl',
                        'formRules',
                        'fileExtensionsToIgnore',
                        'filenamesToIgnore',
                        'hostsToRewrite',
                    ] as $blob_option
                ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by Controller::authorize(), the only route to get here.
                    if ( ! isset( $_POST[ $blob_option ] ) ) {
                        continue;
                    }

                    // A list arrives from a textarea, so as a string. If it
                    // is an array, the request did not come from our form.
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised two lines below; this only inspects the type.
                    if ( ! is_string( $_POST[ $blob_option ] ) ) {
                        continue;
                    }

                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                    $posted_list = sanitize_textarea_field( wp_unslash( $_POST[ $blob_option ] ) );

                    self::repository()->update(
                        $blob_option,
                        [ 'blob_value' => preg_replace( '/^\s+|\s+$/m', '', $posted_list ) ]
                    );
                }

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
