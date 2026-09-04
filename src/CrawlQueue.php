<?php

namespace WP2Static;

class CrawlQueue {

    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url VARCHAR(2083) NOT NULL,
            hashed_url CHAR(32) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        Controller::ensureIndex( $table_name, 'hashed_url', [ 'hashed_url' ], true );
    }

    /**
     * Add all Urls to queue
     *
     * @param string[] $urls List of URLs to crawl
     */
    public static function addUrls( array $urls ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';
        $url_count = count( $urls );

        while ( $url_count ) {
            $chunk = array_slice( $urls, 0, 100 );
            $urls = array_slice( $urls, 100 );
            $url_count = count( $urls );
            $placeholders = array_fill( 0, count( $chunk ), '(%s, %s)' );
            $values = [];

            foreach ( $chunk as $url ) {
                array_push( $values, md5( $url ), rawurldecode( $url ) );
            }

            // I segnaposto sono tanti quanti gli URL del chunk, quindi la
            // stringa si compone; ma è composta di soli '(%s, %s)', e ogni
            // valore passa da prepare().
            $query_string =
                'INSERT IGNORE INTO %i (hashed_url, url) VALUES ' .
                implode( ', ', $placeholders );

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
            $wpdb->query(
                $wpdb->prepare( $query_string, array_merge( [ $table_name ], $values ) )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
        }
    }

    /**
     *  Get all crawlable URLs
     *
     *  @return string[] All crawlable URLs
     */
    public static function getCrawlablePaths() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $urls = [];

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT id, url FROM %i ORDER BY url ASC', $table_name )
        );

        foreach ( $rows as $row ) {
            $urls[ $row->id ] = $row->url;
        }

        return $urls;
    }

    /**
     * Remove multiple URLs at once
     *
     * @param array<string> $ids
     * @return void
     */
    public static function rmUrlsById( array $ids ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $ids = array_map( 'absint', $ids );

        if ( ! $ids ) {
            return;
        }

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id IN ( $placeholders )",
                array_merge( [ $table_name ], $ids )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
    }

    public static function rmUrl( string $url ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $wpdb->delete(
            $table_name,
            [
                'hashed_url' => md5( $url ),
            ]
        );
    }

    /**
     *  Get total crawlable URLs
     *
     *  @return int Total crawlable URLs
     */
    public static function getTotalCrawlableURLs() : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $total_urls = $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
        );

        return $total_urls;
    }

    /**
     *  Clear CrawlQueue via truncate or deletion
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting CrawlQueue (Detected URLs)' );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        $total_urls = self::getTotalCrawlableURLs();

        if ( $total_urls > 0 ) {
            WsLog::l( 'failed to truncate CrawlQueue: try deleting instead' );
        }
    }

    /**
     *  Count URLs in Crawl Queue
     */
    public static function getTotal() : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_urls';

        $total = $wpdb->get_var(
            $wpdb->prepare( 'SELECT count(*) FROM %i', $table_name )
        );

        return $total;
    }
}
