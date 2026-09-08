<?php

namespace WP2StaticS3;

class Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-s3';

    const TABLE = 'wp2static_addon_s3_options';

    /**
     * Option name => default.
     *
     * **The names are the original add-on's, and that is deliberate.** The
     * options table has the same name too, so an installation that had the old
     * add-on keeps its bucket, its region and its credentials instead of
     * finding the module unconfigured. It is the same argument as keeping the
     * slug: what keys somebody's configuration is not ours to rename.
     *
     * `cfMaxPathsToInvalidate` has a real default rather than zero, and that is
     * the fix for the defect that cost money: with it unset the original ended
     * up invalidating `/*` — the whole distribution — on every single deploy.
     *
     * `cfAccessKeyID` and `cfSecretAccessKey` are optional and fall back to the
     * S3 ones. They exist because the original had them: a separate IAM user
     * for CloudFront is unusual, but somebody configured one, and taking the
     * option away would break their deploy without saying so.
     *
     * @var array<string, string>
     */
    const DEFAULTS = [
        'cfAccessKeyID' => '',
        'cfDistributionID' => '',
        'cfMaxPathsToInvalidate' => '1000',
        'cfSecretAccessKey' => '',
        's3AccessKeyID' => '',
        's3Bucket' => '',
        's3CacheControl' => '',
        's3ObjectACL' => '',
        's3Region' => 'us-east-1',
        's3RemotePath' => '',
        's3SecretAccessKey' => '',
    ];

    /**
     * The options held encrypted rather than in the clear.
     *
     * @var string[]
     */
    const SECRETS = [ 's3SecretAccessKey', 'cfSecretAccessKey' ];

    public function run() : void {
        add_action(
            'admin_post_wp2static_s3_save_options',
            [ $this, 'saveOptionsFromUI' ],
            15,
            1
        );

        add_action( 'wp2static_deploy', [ $this, 'deploy' ], 15, 2 );

        add_action( 'init', [ $this, 'registerAddon' ] );

        add_filter( 'wp2static_add_menu_items', [ self::class, 'addSubmenuPage' ] );

        if ( defined( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static s3', [ CLI::class, 's3' ] );
        }
    }

    public function registerAddon() : void {
        do_action(
            'wp2static_register_addon',
            self::SLUG,
            'deploy',
            'S3',
            'https://github.com/lignazio/vibestatic#s3',
            'Uploads the generated site to Amazon S3, and tells CloudFront what changed'
        );
    }

    /**
     * @param mixed $submenu_pages Pages registered so far.
     * @return mixed[] The same, plus this one.
     */
    public static function addSubmenuPage( $submenu_pages ) : array {
        $pages = is_array( $submenu_pages ) ? $submenu_pages : [];

        $pages['s3'] = [ self::class, 'renderS3Page' ];

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

    public static function seedOptions() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        foreach ( self::DEFAULTS as $name => $value ) {
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

        /*
         * One upsert. The sFTP module reached this the long way — an UPDATE and
         * then an INSERT when nothing was updated — and MySQL reports zero
         * affected rows when an UPDATE writes the same value back, so re-saving
         * an unchanged field looked like "no such row" and produced a
         * duplicate-key error per field on every save.
         */
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

        /*
         * The string is returned as it is, '0' included. Testing the value for
         * truth instead — `! $value ? default : $value` — is how the core came
         * to have thirteen options that could not be turned off: '0' is falsy
         * in PHP, and `use_tls` is a flag whose whole purpose is to be zero.
         */
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

    public static function renderS3Page() : void {
        $view = [
            'nonce_action' => 'wp2static-s3-options',
            'options' => self::getOptions(),
        ];

        require __DIR__ . '/../views/s3-page.php';
    }

    /**
     * @param string $processed_site_path The processed site's directory.
     * @param string $enabled_deployer    Slug of the deployer the user selected.
     */
    public function deploy( string $processed_site_path, string $enabled_deployer = '' ) : void {
        if ( self::SLUG !== $enabled_deployer ) {
            return;
        }

        \WP2Static\WsLog::l( 'S3 Addon deploying' );

        ( new Deployer() )->deploy( $processed_site_path );
    }

    public static function saveOptionsFromUI() : void {
        /*
         * Capability as well as nonce, and before anything is written. What is
         * saved here are the credentials of an AWS account: a nonce says where
         * a request came from, not who sent it.
         */
        \WP2Static\Controller::authorize( 'wp2static-s3-options' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by \WP2Static\Controller::authorize() above; WPCS discards guards reached through ::.
        foreach ( array_keys( self::DEFAULTS ) as $name ) {
            if ( in_array( $name, self::SECRETS, true ) ) {
                continue;
            }

            $value = isset( $_POST[ $name ] )
                ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) )
                : '';

            self::saveOption( $name, $value );
        }

        foreach ( self::SECRETS as $name ) {
            $secret = isset( $_POST[ $name ] )
                ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) )
                : '';

            self::saveOption(
                $name,
                \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $secret )
            );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-s3' ) );
        exit;
    }
}
