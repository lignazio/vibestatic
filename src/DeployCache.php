<?php

namespace WP2Static;

class DeployCache {

    const DEFAULT_NAMESPACE = 'default';

    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $charset_collate = $wpdb->get_charset_collate();

        $check_table_query =
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $table_name )
            );

        // if table exists, check structure
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $check_table_query è la prepare() qui sopra.
        if ( $wpdb->get_var( $check_table_query ) === $table_name ) {
            // @todo We can remove this eventually
            // If the ID column is missing, just remove the table and start
            // again because dbDelta isn't adding it correctly
            $id_row = $wpdb->get_row(
                $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table_name, 'id' )
            );

            if ( ! $id_row ) {
                $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
            }
        }

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            path_hash CHAR(32) NOT NULL,
            path VARCHAR(2083) NOT NULL,
            file_hash CHAR(32) NOT NULL,
            namespace VARCHAR(128) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash_ns_idx (path_hash, namespace)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function addFile(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $deploy_cache_table = $wpdb->prefix . 'wp2static_deploy_cache';

        $post_processed_dir = ProcessedSite::getPath();

        $deployed_file = $post_processed_dir . $local_path;

        $path_hash = md5( $deployed_file );

        if ( ! $file_hash ) {
            $file_contents = file_get_contents( $deployed_file );

            if ( ! $file_contents ) {
                return;
            }

            $file_hash = md5( $file_contents );
        }

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (path_hash, path, file_hash, namespace)
                 VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE file_hash = %s, namespace = %s',
                $deploy_cache_table,
                // Insert values
                $path_hash,
                $local_path,
                $file_hash,
                $namespace,
                // Duplicate key values
                $file_hash,
                $namespace
            )
        );
    }

    /**
     * Checks if file can skip deployment
     *  - uses hash of file and path's hash
     */
    public static function fileisCached(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : bool {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $post_processed_dir = ProcessedSite::getPath();

        $deployed_file = $post_processed_dir . $local_path;

        $path_hash = md5( $deployed_file );

        if ( ! $file_hash ) {
            $file_contents = file_get_contents( $deployed_file );

            if ( ! $file_contents ) {
                return false;
            }

            $file_hash = md5( $file_contents );
        }

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $hash = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT path_hash FROM %i
                 WHERE path_hash = %s AND file_hash = %s AND namespace = %s LIMIT 1',
                $table_name,
                $path_hash,
                $file_hash,
                $namespace
            )
        );

        return (bool) $hash;
    }

    public static function truncate(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : void {
        WsLog::l( 'Deleting DeployCache' );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE namespace = %s',
                $table_name,
                $namespace
            )
        );
    }

    /**
     *  Count Paths in Deploy Cache for default or specific namespace
     */
    public static function getTotalByNamespace(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $total = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT count(*) FROM %i WHERE namespace = %s',
                $table_name,
                $namespace
            )
        );

        return $total;
    }

    /**
     *  Count Paths in Deploy Cache across all namespaces
     *
     *  @return mixed[] namespace totals
     */
    public static function getTotal() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $counts = [];

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT namespace, COUNT(*) AS count FROM %i GROUP BY namespace',
                $table_name
            )
        );

        foreach ( $rows as $row ) {
            $counts[ $row->namespace ] = $row->count;
        }

        return $counts;
    }


    /**
     *  Get all cached paths
     *
     *  @return string[] All cached paths
     */
    public static function getPaths(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $urls = [];

        $table_name = $wpdb->prefix . 'wp2static_deploy_cache';

        $urls = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT path FROM %i WHERE namespace = %s ORDER BY path',
                $table_name,
                $namespace
            )
        );

        return $urls;
    }
}
