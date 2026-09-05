<?php

namespace WP2Static;

class Addons {
    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            slug VARCHAR(191) NOT NULL,
            type VARCHAR(249) NOT NULL,
            name VARCHAR(249) NOT NULL,
            docs_url VARCHAR(2083) NOT NULL,
            description VARCHAR(249) NOT NULL,
            enabled TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
            PRIMARY KEY  (slug)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function registerAddon(
        string $slug,
        string $type,
        string $name,
        string $docs_url,
        string $description
    ) : void {
        // TODO: guard against unknown addon type

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        /*
         * INSERT IGNORE used to be the whole of it, and it meant an add-on's
         * metadata was frozen at the moment it was first registered. That is
         * right for `enabled`, which is the user's choice, and wrong for
         * everything else: name, type, description and documentation URL belong
         * to the add-on and change with it.
         *
         * It showed up as a dead link. This add-on's docs URL still pointed at
         * the upstream repository it came from, which now returns 404, and
         * correcting it in the source changed nothing on any installation that
         * already had the row — the book icon on the Add-ons page kept leading
         * nowhere.
         *
         * `enabled` is deliberately absent from the UPDATE: re-registering an
         * add-on on every page load must not switch a deployer back on that the
         * user switched off.
         */
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (slug, type, name, docs_url, description)
                 VALUES (%s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                 type = %s, name = %s, docs_url = %s, description = %s',
                $table_name,
                $slug,
                $type,
                $name,
                $docs_url,
                $description,
                $type,
                $name,
                $docs_url,
                $description
            )
        );
    }

    /**
     * Get all Addons
     *
     * @return mixed[] array of Addon objects
     */
    public static function getAll( string $type = 'all' ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        if ( $type === 'all' ) {
            return $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i ORDER BY type DESC', $table_name )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE type = %s ORDER BY type DESC',
                $table_name,
                $type
            )
        );
    }

    /**
     * Get enabled Addons of a given type
     *
     * @param string $type Type of addon to return
     * @return list<object{slug: string, type: string, enabled: int}> array of Addon objects
     */
    public static function getType( string $type ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        /** @var list<object{slug: string, type: string, enabled: int}> $addons */
        $addons = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE type = %s AND enabled = 1 ORDER BY slug',
                $table_name,
                $type
            )
        ) ?? [];

        return $addons;
    }

    /**
     *  Deregister Addons
     */
    public static function truncate() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_addons';

        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        WsLog::l( 'Deregistered all Addons' );
    }

    /**
     * Get enabled deployer
     *
     * "There can be only one!"
     *
     * The declared type used to be `string|bool`, which says "a string, or
     * true, or false": true was never a possible value, and callers had to rule
     * it out themselves. Below it is `string|false`.
     *
     * @return string|false deployment add-on slug or false
     */
    public static function getDeployer() {
        $addons = self::getType( 'deploy' );

        if ( empty( $addons ) ) {
            return false;
        }

        return $addons[0]->slug;
    }
}
