<?php

namespace WP2Static;

class CrawlCache {

    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $charset_collate = $wpdb->get_charset_collate();

        // if table exists, check structure
        $existing_table = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
        );

        if ( $existing_table === $table_name ) {
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
            hashed_url CHAR(32) NOT NULL,
            url VARCHAR(2083) NOT NULL,
            page_hash CHAR(32) NOT NULL,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            status SMALLINT DEFAULT 200 NOT NULL,
            redirect_to VARCHAR(2083) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hashed_url_idx (hashed_url)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     *  Get all Crawl Cache URLs
     *
     *  @return string[] All URLs
     */
    public static function getHashes() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $urls = [];

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $urls = $wpdb->get_col(
            $wpdb->prepare( 'SELECT hashed_url FROM %i', $table_name )
        );

        return $urls;
    }

    public static function addUrl( string $url, string $page_hash, int $status,
                                   ?string $redirect_to ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (time, hashed_url, url, page_hash, status, redirect_to)
                 VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY
                 UPDATE time = %s, page_hash = %s, status = %s, redirect_to = %s',
                $table_name,
                current_time( 'mysql' ),
                md5( $url ),
                $url,
                $page_hash,
                $status,
                $redirect_to,
                current_time( 'mysql' ),
                $page_hash,
                $status,
                $redirect_to
            )
        );
    }

    // TODO: enable date filter as option/alternate method
    public static function getUrl( string $url, string $page_hash ) : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $hashed_url = md5( $url );

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $hashed_url = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT hashed_url FROM %i WHERE hashed_url = %s AND page_hash = %s LIMIT 1',
                [ $table_name, $hashed_url, $page_hash ]
            )
        );

        return (string) $hashed_url;
    }

    /**
     *  Get all URLs in CrawlCache
     *
     *  @return object[] {
     *      All crawlable URLs
     *
     *      @type int      $id                   ID
     *      @type string   $hashed_url           MD5 hashed URL
     *      @type string   $url                  URL in plain text
     *      @type string   $page_hash            MD5 hashed page
     *  }
     */
    public static function getURLs() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $urls = [];

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, hashed_url, url, page_hash FROM %i ORDER BY url',
                $table_name
            )
        );

        foreach ( $rows as $row ) {
            $urls[ $row->id ] = $row;
        }

        return $urls;
    }

    public static function rmUrl( string $url ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $wpdb->delete(
            $table_name,
            [
                'hashed_url' => md5( $url ),
            ]
        );
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

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        // Un %d per ogni id: la lista è di lunghezza variabile, quindi i
        // segnaposto si generano, ma restano segnaposto.
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

    /**
     *  Clear CrawlCache via truncation
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting CrawlCache' );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        $totalcrawl_cache = self::getTotal();

        if ( $totalcrawl_cache > 0 ) {
            WsLog::l( 'Failed to truncate CrawlCache: try deleting instead' );
        }
    }

    /**
     *  Count URLs in Crawl Cache
     */
    public static function getTotal() : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $total = $wpdb->get_var(
            $wpdb->prepare( 'SELECT count(*) FROM %i', $table_name )
        );

        return $total;
    }

    /**
     * @param mixed[] $redirs
     * @return mixed[] redirects
     */
    public static function wp2static_list_redirects( array $redirs ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT url, redirect_to FROM %i WHERE 0 < LENGTH(redirect_to)',
                $table_name
            )
        );

        foreach ( $rows as $row ) {
            $redirs[ $row->url ] = [
                'url' => $row->url,
                'redirect_to' => $row->redirect_to,
            ];
        }

        return $redirs;
    }
}
