<?php

namespace WP2StaticSFTP;

class Controller {

    /**
     * The slug this add-on is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-sftp';

    public function run() : void {
        add_action(
            'admin_post_wp2static_sftp_save_options',
            [ $this, 'saveOptionsFromUI' ],
            15,
            1
        );

        /*
         * Two accepted arguments, not one. The core passes the path AND the
         * slug of the deployer the user selected, and every deployer is meant
         * to return early when it is not the one. Registering for a single
         * argument makes that impossible: this add-on could not see the slug,
         * so it uploaded on every deploy no matter which deployer was chosen.
         *
         * Measured before the fix: with Directory Deployment selected and
         * nothing to publish, merely having this add-on active produced 1802
         * attempted uploads to an unconfigured server and 1802 log rows.
         */
        add_action(
            'wp2static_deploy',
            [ $this, 'deploy' ],
            15,
            2
        );

        /*
         * Registering makes it appear on the Add-ons page and, more to the
         * point, makes it selectable: the core reads the enabled deployer out
         * of the add-ons table, so an add-on that never registers can never be
         * the one chosen. This add-on hooked the deploy without ever
         * registering, which is the other half of why it behaved as it did —
         * it could not be picked, and it ran anyway.
         */
        add_action( 'init', [ $this, 'registerAddon' ] );

        add_filter( 'wp2static_add_menu_items', [ 'WP2StaticSFTP\Controller', 'addSubmenuPage' ] );

        if ( defined( 'WP_CLI' ) ) {
            \WP_CLI::add_command(
                'wp2static sftp',
                [ 'WP2StaticSFTP\CLI', 'sftp' ]
            );
        }
    }

    /**
     *  Get all add-on options
     *
     *  @return mixed[] All options
     */
    public static function getOptions() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $options = [];

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i', $table_name )
        );

        foreach ( $rows as $row ) {
            $options[ $row->name ] = $row;
        }

        return $options;
    }

    /**
     * Insert the options that are not there yet, leaving existing ones alone.
     *
     * This was eleven copies of the same four lines with the query string
     * interpolated, which is what the eleven "use placeholders" violations
     * were. Labels and descriptions are gone from the table: they are the
     * view's business, where they can be translated.
     */
    public static function seedOptions() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        $defaults = [
            'dir_permissions' => '0755',
            'file_permissions' => '0644',
            'group' => '',
            'host' => '',
            'owner' => '',
            'passphrase' => '',
            'password' => '',
            'port' => '22',
            'private_key' => '',
            'remote_root' => '',
            'username' => '',
        ];

        foreach ( $defaults as $name => $value ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO %i (name, value) VALUES (%s, %s)',
                    $table_name,
                    $name,
                    $value
                )
            );
        }
    }

    /**
     * Save options
     *
     * @param mixed $value option value to save
     */
    public static function saveOption( string $name, $value ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        /*
         * An upsert, not a bare INSERT. There is no unique key on `name`, so
         * every call used to add a row: saving an option twice left two rows
         * for it, and `getOptions()` — which keys a `SELECT *` by name —
         * returned whichever came last. The table grew without bound and the
         * value in effect depended on row order.
         */
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET value = %s WHERE name = %s',
                $table_name,
                $value,
                $name
            )
        );

        if ( 0 === (int) $wpdb->rows_affected ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO %i (name, value) VALUES (%s, %s)',
                    $table_name,
                    $name,
                    $value
                )
            );
        }
    }

    public static function renderSFTPPage() : void {
        $view = [];
        $view['nonce_action'] = 'wp2static-sftp-options';
        $view['uploads_path'] = \WP2Static\SiteInfo::getPath( 'uploads' );
        $view['options'] = self::getOptions();

        require_once __DIR__ . '/../views/sftp-page.php';
    }


    public function registerAddon() : void {
        do_action(
            'wp2static_register_addon',
            self::SLUG,
            'deploy',
            'sFTP',
            'https://github.com/lignazio/vibestatic#sftp',
            'Uploads the generated site to a remote server over sFTP, sending only what changed'
        );
    }

    /**
     * @param string $processed_site_path The processed site's directory.
     * @param string $enabled_deployer    Slug of the deployer the user selected.
     */
    public function deploy( string $processed_site_path, string $enabled_deployer = '' ) : void {
        if ( self::SLUG !== $enabled_deployer ) {
            return;
        }

        \WP2Static\WsLog::l( 'sFTP Addon deploying' );

        $sftp_deployer = new Deployer();
        $sftp_deployer->upload_files( $processed_site_path );
    }

    public static function activate_for_single_site() : void {
        // initialize options DB
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        $charset_collate = $wpdb->get_charset_collate();

        /*
         * VARCHAR(191) and a unique key on `name`. There was no unique key at
         * all, which is why saveOption() could insert a second row for an
         * option that already had one; 191 because that is the longest a
         * utf8mb4 column can be and still be indexed.
         */
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            value VARCHAR(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $options = self::getOptions();

        if ( ! isset( $options['host'] ) ) {
            self::seedOptions();
        }
    }

    public static function deactivate_for_single_site() : void {
    }

    public static function deactivate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            // prepare() with %i, not sprintf(): a table name pushed through
            // sprintf is a query assembled by string, which is the shape every
            // SQL injection in this project has had.
            $site_ids = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT blog_id FROM %i WHERE site_id = %d',
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                self::deactivate_for_single_site();
            }

            restore_current_blog();
        } else {
            self::deactivate_for_single_site();
        }
    }

    public static function activate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            // prepare() with %i, not sprintf(): a table name pushed through
            // sprintf is a query assembled by string, which is the shape every
            // SQL injection in this project has had.
            $site_ids = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT blog_id FROM %i WHERE site_id = %d',
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                self::activate_for_single_site();
            }

            restore_current_blog();
        } else {
            self::activate_for_single_site();
        }
    }

    /**
     * Add WP2Static submenu
     *
     * @param mixed[] $submenu_pages array of submenu pages
     * @return mixed[] array of submenu pages
     */
    public static function addSubmenuPage( $submenu_pages ) : array {
        $submenu_pages['sftp'] = [ 'WP2StaticSFTP\Controller', 'renderSFTPPage' ];

        return $submenu_pages;
    }

    public static function saveOptionsFromUI() : void {
        /*
         * The nonce was checked, the capability was not — and on this add-on
         * that gap is as wide as it gets: the values saved here are the host,
         * the username and the password of the server the whole site is
         * uploaded to. Anyone who could be induced to submit this form could
         * point the next deploy at a server of their choosing.
         *
         * The core's guard checks capability first, then the nonce.
         */
        \WP2Static\Controller::authorize( 'wp2static-sftp-options' );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        $simple_text_fields = [
            'dir_permissions',
            'file_permissions',
            'group',
            'host',
            'owner',
            'port',
            'private_key',
            'remote_root',
            'username',
        ];

        foreach ( $simple_text_fields as $option_name ) {
            // isset() and wp_unslash(): the fields used to be read as a bare
            // $_POST['x'] — a request without one gave a warning and saved
            // null — and without stripping the slashes WordPress adds, so a
            // path or a host containing a quote came back altered.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by \WP2Static\Controller::authorize() at the top of this method; WPCS discards guards reached through :: (see has_object_operator_before() in NonceVerificationSniff).
            if ( ! isset( $_POST[ $option_name ] ) ) {
                continue;
            }

            $wpdb->update(
                $table_name,
                [
                    'value' => sanitize_text_field(
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by \WP2Static\Controller::authorize() at the top of this method; WPCS discards guards reached through :: (see has_object_operator_before() in NonceVerificationSniff).
                        wp_unslash( $_POST[ $option_name ] )
                    ),
                ],
                [ 'name' => $option_name ]
            );
        }

        foreach ( [ 'passphrase', 'password' ] as $secret ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by \WP2Static\Controller::authorize() at the top of this method; WPCS discards guards reached through :: (see has_object_operator_before() in NonceVerificationSniff).
            if ( ! isset( $_POST[ $secret ] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by \WP2Static\Controller::authorize() at the top of this method; WPCS discards guards reached through :: (see has_object_operator_before() in NonceVerificationSniff).
            $posted = sanitize_text_field( wp_unslash( $_POST[ $secret ] ) );

            $wpdb->update(
                $table_name,
                [
                    'value' => '' === $posted
                        ? ''
                        : \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $posted ),
                ],
                [ 'name' => $secret ]
            );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-sftp' ) );
        exit;
    }

    /**
     * Get option value
     *
     * @return string option value
     */
    public static function getValue( string $name ) : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_sftp_options';

        $option_value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value FROM %i WHERE name = %s LIMIT 1',
                $table_name,
                $name
            )
        );

        if ( ! is_string( $option_value ) ) {
            return '';
        }

        return $option_value;
    }
}

