<?php
/**
 * Access to the wp_wp2static_crawl_cache table.
 *
 * The same queries that used to live in CrawlCache's static methods, with one
 * difference that decides everything: the connection arrives through the
 * constructor instead of from `global $wpdb`. That is what lets a test
 * instantiate the class with a fake connection, and it is why this table did
 * not have a single test despite being the heart of recognising changed pages.
 *
 * CrawlCache stays the public facade and does not change by a comma: it is API
 * that twenty-one add-ons call statically.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CrawlCacheRepository {

    /**
     * How many URLs go into a single query. Same number and same reason as
     * CrawlQueueRepository::CHUNK_SIZE: the lists involved are the same ones.
     */
    const CHUNK_SIZE = 100;

    /**
     * @var \wpdb
     */
    private $db;

    /**
     * @var string
     */
    private $table;

    public function __construct( \wpdb $db ) {
        $this->db = $db;
        $this->table = $db->prefix . 'wp2static_crawl_cache';
    }

    public function createTable() : void {
        $charset_collate = $this->db->get_charset_collate();

        // if table exists, check structure
        $existing_table = $this->db->get_var(
            $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table ) )
        );

        if ( $existing_table === $this->table ) {
            // @todo We can remove this eventually
            // If the ID column is missing, just remove the table and start
            // again because dbDelta isn't adding it correctly
            $id_row = $this->db->get_row(
                $this->db->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $this->table, 'id' )
            );

            if ( ! $id_row ) {
                $this->db->query(
                    (string) $this->db->prepare( 'DROP TABLE IF EXISTS %i', $this->table )
                );
            }
        }

        $table = $this->table;

        $sql = "CREATE TABLE $table (
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
     * @return string[] All hashed URLs
     */
    public function getHashes() : array {
        $hashes = $this->db->get_col(
            $this->db->prepare( 'SELECT hashed_url FROM %i', $this->table )
        );

        // get_col() is declared as a list of string|null. The column is NOT
        // NULL, so nothing is being discarded: this tells the reader that what
        // comes out are strings, instead of propagating the maybe.
        return array_values( array_filter( $hashes, 'is_string' ) );
    }

    public function addUrl(
        string $url,
        string $page_hash,
        int $status,
        ?string $redirect_to
    ) : void {
        $now = current_time( 'mysql' );

        $this->db->query(
            (string) $this->db->prepare(
                'INSERT INTO %i (time, hashed_url, url, page_hash, status, redirect_to)
                 VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY
                 UPDATE time = %s, page_hash = %s, status = %s, redirect_to = %s',
                $this->table,
                $now,
                md5( $url ),
                $url,
                $page_hash,
                $status,
                $redirect_to,
                $now,
                $page_hash,
                $status,
                $redirect_to
            )
        );
    }

    // TODO: enable date filter as option/alternate method
    public function getUrl( string $url, string $page_hash ) : string {
        $hashed_url = $this->db->get_var(
            $this->db->prepare(
                'SELECT hashed_url FROM %i WHERE hashed_url = %s AND page_hash = %s LIMIT 1',
                [ $this->table, md5( $url ), $page_hash ]
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
    public function getURLs() : array {
        $urls = [];

        /** @var list<object{id: int, hashed_url: string, url: string, page_hash: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT id, hashed_url, url, page_hash FROM %i ORDER BY url',
                $this->table
            )
        ) ?? [];

        foreach ( $rows as $row ) {
            $urls[ $row->id ] = $row;
        }

        return $urls;
    }

    public function rmUrl( string $url ) : void {
        $this->db->delete(
            $this->table,
            [
                'hashed_url' => md5( $url ),
            ]
        );
    }

    /**
     * Drop several URLs from the cache at once.
     *
     * Needed by whoever forgets a URL from the queue: if the cache row stayed,
     * a URL coming back with the same content would be recognised as already
     * seen and its file would not be rewritten — detected, crawled, and absent
     * from the published site.
     *
     * Chunked like the INSERTs, and for the same reason: the lists involved run
     * to tens of thousands of rows, and a single DELETE would exceed
     * max_allowed_packet.
     *
     * @param string[] $urls URLs to forget, in the form they were cached in.
     */
    public function rmUrls( array $urls ) : void {
        foreach ( array_chunk( $urls, self::CHUNK_SIZE ) as $chunk ) {
            $hashes = array_map( 'md5', $chunk );

            $placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
            $this->db->query(
                (string) $this->db->prepare(
                    "DELETE FROM %i WHERE hashed_url IN ( $placeholders )",
                    array_merge( [ $this->table ], $hashes )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        }
    }

    /**
     * @param array<string> $ids
     */
    public function rmUrlsById( array $ids ) : void {
        $ids = array_map( 'absint', $ids );

        if ( ! $ids ) {
            return;
        }

        // One %d per id: the list is of variable length, so the placeholders
        // are generated — but they stay placeholders.
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        $this->db->query(
            (string) $this->db->prepare(
                "DELETE FROM %i WHERE id IN ( $placeholders )",
                array_merge( [ $this->table ], $ids )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
    }

    public function truncate() : void {
        $this->db->query( (string) $this->db->prepare( 'TRUNCATE TABLE %i', $this->table ) );
    }

    public function getTotal() : int {
        return (int) $this->db->get_var(
            $this->db->prepare( 'SELECT count(*) FROM %i', $this->table )
        );
    }

    /**
     * @param mixed[] $redirs
     * @return mixed[] redirects
     */
    public function listRedirects( array $redirs ) : array {
        /** @var list<object{url: string, redirect_to: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT url, redirect_to FROM %i WHERE 0 < LENGTH(redirect_to)',
                $this->table
            )
        ) ?? [];

        foreach ( $rows as $row ) {
            $redirs[ $row->url ] = [
                'url' => $row->url,
                'redirect_to' => $row->redirect_to,
            ];
        }

        return $redirs;
    }
}
