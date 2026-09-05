<?php

namespace WP2StaticDirectoryDeployer;

class Controller {
    public function run() : void {
        add_filter(
            'wp2static_add_menu_items',
            [ 'WP2StaticDirectoryDeployer\Controller', 'addSubmenuPage' ]
        );

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

        do_action(
            'wp2static_register_addon',
            'wp2static-addon-directory-deployment',
            'deploy',
            'Directory Deployment',
            'https://github.com/twardoch/wp2static-addon-directory-deployment',
            'Deploys to local directory, either overwriting or replacing existing files'
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
     * Inserisce le opzioni che non ci sono ancora, senza toccare quelle presenti.
     *
     * Nome e valore, non piu' etichetta e descrizione. Le due colonne le
     * scriveva questo metodo alla prima apertura della pagina, cioe' congelava
     * nel database la lingua attiva in quel momento; ora le etichette stanno
     * nella view, tradotte a ogni caricamento. Il core aveva la stessa coppia di
     * colonne e le ha lasciate cadere per la stessa ragione.
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
         * `label` e `description` non le legge piu' nessuno: le etichette stanno
         * nella view, dove possono essere tradotte. dbDelta non toglie una
         * colonna che non c'e' piu' nella CREATE TABLE, quindi va tolta a mano —
         * stesso rattoppo che il core ha sulla sua tabella delle opzioni.
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

    /**
     * Add WP2Static submenu
     *
     * @param mixed[] $submenu_pages array of submenu pages
     * @return mixed[] array of submenu pages
     */
    public static function addSubmenuPage( array $submenu_pages ) : array {
        $submenu_pages['directorydeployer'] = [ 'WP2StaticDirectoryDeployer\Controller', 'renderDirectoryDeployerPage' ];

        return $submenu_pages;
    }

    public static function saveOptionsFromUI() : void {
        /*
         * Il nonce c'era, la capability no. Non e' la stessa cosa: il nonce
         * dice da dove arriva la richiesta, non chi la manda, e su questo
         * modulo la differenza pesa quanto puo' pesare — la cartella di
         * destinazione che si salva qui e' quella che il deploy cancella con
         * rrmdir() prima di riempirla. Un utente con un ruolo qualunque, in
         * possesso del nonce, aveva un modo per far cancellare una cartella a
         * scelta sul server.
         *
         * Controller::authorize() del core verifica capability e nonce, in
         * quest'ordine, ed e' lo stesso guardiano dei ventiquattro handler del
         * core.
         */
        \WP2Static\Controller::authorize( 'wp2static-directory-deployment-options' );

        foreach (
            [
                'directoryDeploymentDeleteBeforeDeployment',
                'directoryDeploymentTargetDirectory',
                'directoryDeploymentAdditionalSourceDirectory',
            ] as $name
        ) {
            // `?? ''` e wp_unslash(): i tre campi venivano letti come
            // $_POST['x'] diretto — una richiesta senza quel campo dava un
            // warning e salvava null — e senza togliere le barre che WordPress
            // aggiunge, quindi un percorso con un apostrofo tornava indietro
            // cambiato.
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

        // %i per l'identificatore, come nel core: il nome della tabella non
        // arriva da fuori, ma interpolarlo a mano e' l'abitudine da cui e'
        // nata la SQL injection chiusa alla fase 3.
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
        // Passa dal core, che alla pagina nascosta da' anche un titolo: senza,
        // WordPress non lo trova e admin-header.php fa strip_tags( null ).
        \WP2Static\Controller::addHiddenPage(
            __( 'Directory Deployment Options', 'vibestatic-directory-deployment' ),
            'wp2static-addon-directory-deployment',
            [ $this, 'renderDirectoryDeployerPage' ]
        );
    }
}

