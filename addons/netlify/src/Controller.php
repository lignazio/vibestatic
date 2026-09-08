<?php

namespace WP2StaticNetlify;

class Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-netlify';

    const TABLE = 'wp2static_addon_netlify_options';

    public function run() : void {
        add_action(
            'admin_post_wp2static_netlify_save_options',
            [ $this, 'saveOptionsFromUI' ],
            15,
            1
        );

        add_action( 'wp2static_deploy', [ $this, 'deploy' ], 15, 2 );

        add_action( 'init', [ $this, 'registerAddon' ] );

        add_filter( 'wp2static_add_menu_items', [ self::class, 'addSubmenuPage' ] );

        if ( defined( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static netlify', [ CLI::class, 'netlify' ] );
        }
    }

    public function registerAddon() : void {
        do_action(
            'wp2static_register_addon',
            self::SLUG,
            'deploy',
            'Netlify',
            'https://github.com/lignazio/vibestatic#netlify',
            'Uploads the generated site to Netlify, sending only what it does not already hold'
        );
    }

    /**
     * @param mixed $submenu_pages Pages registered so far.
     * @return mixed[] The same, plus this one.
     */
    public static function addSubmenuPage( $submenu_pages ) : array {
        $pages = is_array( $submenu_pages ) ? $submenu_pages : [];

        $pages['netlify'] = [ self::class, 'renderNetlifyPage' ];

        return $pages;
    }

    private static function tableName() : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * @return mixed[] All options, keyed by name.
     */
    public static function getOptions() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        /** @var list<object{name: string, value: string}>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i', self::tableName() )
        );

        $options = [];

        foreach ( $rows ?? [] as $row ) {
            $options[ $row->name ] = $row;
        }

        return $options;
    }

    /**
     * Insert the options that are not there yet, leaving existing ones alone.
     *
     * INSERT IGNORE, and the table below has a unique key on `name`. The
     * original used a plain INSERT with no unique key at all, so every call
     * added a row: `getOptions()` keeps the last row per name while
     * `getValue()` takes `LIMIT 1` with no ORDER BY, so the command line and
     * the settings page could disagree about the same option.
     *
     * Label and description are gone from the table. They were written on first
     * use, which froze whichever language was active at that moment into the
     * database; the labels live in the view, translated on every load.
     */
    public static function seedOptions() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        foreach ( [ 'siteID' => '', 'accessToken' => '' ] as $name => $value ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO %i (name, value) VALUES (%s, %s)',
                    self::tableName(),
                    $name,
                    $value
                )
            );
        }
    }

    /**
     * @param mixed $value Option value to save.
     */
    public static function saveOption( string $name, $value ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        // One upsert. A bare INSERT, which is what this was, added a row every
        // time an option was saved.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (name, value) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE value = %s',
                self::tableName(),
                $name,
                $value,
                $value
            )
        );
    }

    public static function getValue( string $name ) : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value FROM %i WHERE name = %s LIMIT 1',
                self::tableName(),
                $name
            )
        );

        return is_string( $value ) ? $value : '';
    }

    /**
     * Create and seed this module's options table.
     *
     * Called by WP2Static\Modules::installTables(), from Schema::install().
     */
    public static function installTables() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = self::tableName();
        $charset_collate = $wpdb->get_charset_collate();

        // VARCHAR(191) and a unique key on `name`: 191 because that is the
        // longest a utf8mb4 column can be and still be indexed, and the key
        // because without one saveOption() could not be an upsert.
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            value VARCHAR(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        self::seedOptions();
    }

    public static function renderNetlifyPage() : void {
        $view = [
            'nonce_action' => 'wp2static-netlify-options',
            'options' => self::getOptions(),
        ];

        require __DIR__ . '/../views/netlify-page.php';
    }

    /**
     * @param string $processed_site_path The processed site's directory.
     * @param string $enabled_deployer    Slug of the deployer the user selected.
     */
    public function deploy( string $processed_site_path, string $enabled_deployer = '' ) : void {
        if ( self::SLUG !== $enabled_deployer ) {
            return;
        }

        \WP2Static\WsLog::l( 'Netlify Addon deploying' );

        ( new Deployer() )->uploadFiles( $processed_site_path );
    }

    public static function saveOptionsFromUI() : void {
        /*
         * Capability as well as nonce, and before anything is written. The
         * values saved here are the credentials of the account the whole site
         * is published to: a nonce says where a request came from, not who
         * sent it.
         */
        \WP2Static\Controller::authorize( 'wp2static-netlify-options' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by \WP2Static\Controller::authorize() above; WPCS discards guards reached through ::.
        $site_id = isset( $_POST['siteID'] )
            ? sanitize_text_field( wp_unslash( $_POST['siteID'] ) )
            : '';

        $access_token = isset( $_POST['accessToken'] )
            ? sanitize_text_field( wp_unslash( $_POST['accessToken'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        self::saveOption( 'siteID', $site_id );
        self::saveOption(
            'accessToken',
            \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $access_token )
        );

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-netlify' ) );
        exit;
    }
}
