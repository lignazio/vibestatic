<?php

namespace WP2StaticDirectoryDeployer;

class Controller {
    public function run() : void {
        /*
         * No `wp2static_add_menu_items`. Now that the core fires it again,
         * registering on it would give this add-on TWO identical pages — that
         * one and `wp2static-addon-directory-deployment`, which is the only one
         * the Add-ons page's gear icon points at. The hook stays for the
         * add-ons that were not adopted and have no other route.
         */

        add_action(
            'admin_post_wp2static_directory_deployment_save_options',
            [ $this, 'saveOptionsFromUI' ],
            15,
            1
        );

        add_action(
            'wp2static_deploy',
            [ $this, 'deploy' ],
            15,
            2
        );

        add_action(
            'admin_menu',
            [ $this, 'addOptionsPage' ],
            15,
            1
        );

        /*
         * The docs URL points at this fork, not at the upstream repository the
         * add-on came from: github.com/twardoch/wp2static-addon-directory-
         * deployment now returns 404, so the Add-ons page's book icon led
         * users nowhere. The add-on is maintained here now, and this is where
         * its documentation lives.
         */
        do_action(
            'wp2static_register_addon',
            'wp2static-addon-directory-deployment',
            'deploy',
            'Directory Deployment',
            'https://github.com/lignazio/vibestatic#directory-deployment',
            'Deploys the generated site to a directory on the same machine, copying only what changed'
        );

        if ( defined( 'WP_CLI' ) ) {
            \WP_CLI::add_command(
                'wp2static directory-deployment',
                [ CLI::class, 'directoryDeployment' ]
            );
        }
    }

    /**
     *  Get all add-on options
     *
     *  @return mixed[] All options
     */
    public static function getOptions() : array {
        global $wpdb;
        $options = [];

        $table_name = $wpdb->prefix . 'wp2static_addon_directory_deployment_options';

        $rows = $wpdb->get_results( "SELECT * FROM $table_name" );

        foreach ( $rows as $row ) {
            $options[ $row->name ] = $row;
        }

        return $options;
    }

    /**
     * Insert the options that are not there yet, leaving existing ones alone.
     *
     * Name and value, no longer label and description. This method wrote those
     * two columns the first time the page was opened, which froze whichever
     * language was active at that moment into the database; the labels now live
     * in the view, translated on every load. The core had the same pair of
     * columns and dropped them for the same reason.
     */
    public static function seedOptions() : void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_directory_deployment_options';

        $defaults = [
            'directoryDeploymentDeleteBeforeDeployment' => '1',
            'directoryDeploymentTargetDirectory' => '',
            'directoryDeploymentAdditionalSourceDirectory' => '',
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
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_directory_deployment_options';

        $wpdb->update(
            $table_name,
            [ 'value' => $value ],
            [ 'name' => $name ]
        );
    }

    public static function renderDirectoryDeployerPage() : void {
        self::createOptionsTable();
        self::seedOptions();

        $view = [];
        $view['nonce_action'] = 'wp2static-directory-deployment-options';
        $view['uploads_path'] = \WP2Static\SiteInfo::getPath( 'uploads' );
        $directory_deployment_target =
            \WP2Static\SiteInfo::getPath( 'uploads' ) . 'wp2static-processed-site.copy';

        // TODO: why do we need this here?
        $view['options'] = self::getOptions();

        $view['copy_url'] =
            is_file( $directory_deployment_target ) ?
                \WP2Static\SiteInfo::getUrl( 'uploads' ) . 'wp2static-processed-site.copy' : '#';

        require_once __DIR__ . '/../views/directory-deployment-page.php';
    }


    public function deploy( string $processed_site_path, string $enabled_deployer ) : void {
        if ( $enabled_deployer !== 'wp2static-addon-directory-deployment' ) {
            return;
        }

        \WP2Static\WsLog::l( 'Directory deployment Addon deploying' );

        $directory_deployer = new Deployer();
        $directory_deployer->uploadFiles( $processed_site_path );
    }

    public static function createOptionsTable() : void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_directory_deployment_options';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            value VARCHAR(255) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        /*
         * Nothing reads `label` and `description` any more: the labels live in
         * the view, where they can be translated. dbDelta does not drop a column
         * that has left the CREATE TABLE, so it has to go by hand — the same
         * patch the core carries on its own options table.
         */
        foreach ( [ 'label', 'description' ] as $obsolete_column ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SHOW COLUMNS FROM %i LIKE %s',
                    $table_name,
                    $obsolete_column
                )
            );

            if ( ! $exists ) {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table_name, $obsolete_column )
            );
        }

        // dbDelta doesn't handle unique indexes well.
        $indexes = $wpdb->query( "SHOW INDEX FROM $table_name WHERE key_name = 'name'" );
        if ( 0 === $indexes ) {
            $result = $wpdb->query( "CREATE UNIQUE INDEX name ON $table_name (name)" );
            if ( false === $result ) {
                \WP2Static\WsLog::l( "Failed to create 'name' index on $table_name." );
            }
        }
    }

    public static function activateForSingleSite(): void {
        self::createOptionsTable();
        self::seedOptions();
    }

    public static function deactivateForSingleSite() : void {
    }

    public static function deactivate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            global $wpdb;

            $query = 'SELECT blog_id FROM %s WHERE site_id = %d;';

            $site_ids = $wpdb->get_col(
                sprintf(
                    $query,
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

    public static function activate( ?bool $network_wide = null ) : void {
        if ( $network_wide ) {
            global $wpdb;

            $query = 'SELECT blog_id FROM %s WHERE site_id = %d;';

            $site_ids = $wpdb->get_col(
                sprintf(
                    $query,
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

    public static function saveOptionsFromUI() : void {
        /*
         * The nonce was there, the capability was not. They are not the same
         * thing: a nonce says where a request came from, not who sent it, and on
         * this module the difference weighs as much as it possibly can — the
         * target directory saved here is the one the deploy wipes with rrmdir()
         * before filling it. A user of any role, in possession of the nonce, had
         * a way to have a directory of their choosing deleted on the server.
         *
         * The core's Controller::authorize() checks capability and nonce, in
         * that order, and is the same guard the core's twenty-four handlers use.
         */
        \WP2Static\Controller::authorize( 'wp2static-directory-deployment-options' );

        foreach (
            [
                'directoryDeploymentDeleteBeforeDeployment',
                'directoryDeploymentTargetDirectory',
                'directoryDeploymentAdditionalSourceDirectory',
            ] as $name
        ) {
            // `?? ''` and wp_unslash(): the three fields used to be read as a
            // bare $_POST['x'] — a request without that field gave a warning
            // and saved null — and without stripping the slashes WordPress
            // adds, so a path containing an apostrophe came back altered.
            $value = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

            self::saveOption( $name, sanitize_text_field( strval( $value ) ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-addon-directory-deployment' ) );
        exit;
    }

    /**
     * Get option value
     *
     * @return string option value
     */
    public static function getValue( string $name ) : string {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addon_directory_deployment_options';

        // %i for the identifier, as in the core: the table name does not come
        // from outside, but interpolating it by hand is the habit the SQL
        // injection this project already closed grew out of.
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

    public function addOptionsPage() : void {
        // Goes through the core, which also gives the hidden page a title:
        // without one, WordPress cannot find it and admin-header.php calls
        // strip_tags( null ).
        \WP2Static\Controller::addHiddenPage(
            __( 'Directory Deployment Options', 'vibestatic-directory-deployment' ),
            'wp2static-addon-directory-deployment',
            [ $this, 'renderDirectoryDeployerPage' ]
        );
    }
}

