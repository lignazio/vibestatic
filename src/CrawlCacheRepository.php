<?php
/**
 * Accesso alla tabella wp_wp2static_crawl_cache.
 *
 * Le stesse query che stavano nei metodi statici di CrawlCache, con una
 * differenza sola ma decisiva: la connessione arriva dal costruttore invece che
 * da `global $wpdb`. Da qui la classe si puo' istanziare in un test con una
 * connessione finta, che e' il motivo per cui questa tabella non aveva un test
 * solo pur essendo il cuore del riconoscimento delle pagine cambiate.
 *
 * CrawlCache resta la facciata pubblica e non cambia di una virgola: e' API che
 * ventuno addon chiamano staticamente.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CrawlCacheRepository {

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

        // get_col() e' dichiarata come lista di string|null. La colonna e'
        // NOT NULL, quindi non si scarta niente: si dice a chi legge che
        // quello che esce sono stringhe, invece di far propagare il forse.
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
     * @param array<string> $ids
     */
    public function rmUrlsById( array $ids ) : void {
        $ids = array_map( 'absint', $ids );

        if ( ! $ids ) {
            return;
        }

        // Un %d per ogni id: la lista è di lunghezza variabile, quindi i
        // segnaposto si generano, ma restano segnaposto.
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
