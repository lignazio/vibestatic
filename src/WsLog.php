<?php

namespace WP2Static;

// TODO: add option in UI to also write to PHP error_log
class WsLog {
    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            log TEXT NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function l( string $text ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        $wpdb->insert(
            $table_name,
            [
                'log' => $text,
            ]
        );

        if ( defined( 'WP_CLI' ) ) {
            $date = current_time( 'c' );
            \WP_CLI::log(
                \WP_CLI::colorize( "%W[$date] %n$text" )
            );
        }
    }

    public static function w( string $text ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        $wpdb->insert(
            $table_name,
            [
                'log' => $text,
            ]
        );

        if ( defined( 'WP_CLI' ) ) {
            $date = current_time( 'c' );
            \WP_CLI::warning(
                \WP_CLI::colorize( "%W[$date] %n$text" )
            );
        }
    }

    /**
     * Log multiple lines at once
     *
     * @param string[] $lines List of lines to log
     */
    public static function lines( array $lines ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        $current_time = current_time( 'mysql' );

        // One '(%s)' per log row: the string is assembled because the number
        // of rows varies, but it is made of placeholders only and every row
        // goes through prepare().
        $query = 'INSERT INTO %i (log) VALUES ' .
            implode(
                ',',
                array_fill( 0, count( $lines ), '(%s)' )
            );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
        Utils::runPrepared(
            $wpdb->prepare( $query, array_merge( [ $table_name ], $lines ) )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Get all log lines
     *
     * @return mixed[] array of Log items
     */
    public static function getAll() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $logs = [];

        $table_name = $wpdb->prefix . 'wp2static_log';

        /** @var list<object{time: string, log: string}> $logs */
        $logs = $wpdb->get_results(
            $wpdb->prepare( 'SELECT time, log FROM %i ORDER BY id DESC', $table_name )
        ) ?? [];

        return $logs;
    }

    /**
     * Poll latest log lines
     */
    public static function poll() : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        $logs = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT CONCAT_WS(': ', time, log) FROM %i ORDER BY id DESC",
                $table_name
            )
        );

        $logs = implode( PHP_EOL, $logs );

        return $logs;
    }

    /**
     *  Clear Log via truncation
     */
    public static function truncate() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_log';

        Utils::runPrepared( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        self::l( 'Deleted all Logs' );
    }
}

