<?php
/**
 * Accesso alla tabella wp_wp2static_urls, la coda degli URL da crawlare.
 *
 * Stesso trattamento di CrawlCacheRepository: la connessione arriva dal
 * costruttore, le query sono le stesse.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CrawlQueueRepository {

    /**
     * Quanti URL entrano in una singola INSERT. Cento non e' un numero magico:
     * ogni URL sono due segnaposto, e le liste in gioco arrivano a decine di
     * migliaia di righe — una INSERT sola supererebbe max_allowed_packet.
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

    /**
     * @param \wpdb $db Connessione WordPress.
     */
    public function __construct( \wpdb $db ) {
        $this->db = $db;
        $this->table = $db->prefix . 'wp2static_urls';
    }

    /**
     * Crea la tabella e il suo indice univoco.
     */
    public function createTable() : void {
        $charset_collate = $this->db->get_charset_collate();
        $table = $this->table;

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            url VARCHAR(2083) NOT NULL,
            hashed_url CHAR(32) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // dbDelta non e' affidabile sugli indici: il commento originale degli
        // autori e` ancora vero, e questo resta un aggancio statico finche` le
        // migrazioni di schema non hanno un posto loro.
        Controller::ensureIndex( $table, 'hashed_url', [ 'hashed_url' ], true );
    }

    /**
     * Add all Urls to queue
     *
     * @param string[] $urls List of URLs to crawl
     */
    public function addUrls( array $urls ) : void {
        foreach ( array_chunk( $urls, self::CHUNK_SIZE ) as $chunk ) {
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
            $this->db->query(
                (string) $this->db->prepare(
                    $query_string,
                    array_merge( [ $this->table ], $values )
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
        }
    }

    /**
     *  Get all crawlable URLs
     *
     *  @return string[] All crawlable URLs, keyed by row id
     */
    public function getCrawlablePaths() : array {
        $urls = [];

        /** @var list<object{id: int, url: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare( 'SELECT id, url FROM %i ORDER BY url ASC', $this->table )
        ) ?? [];

        foreach ( $rows as $row ) {
            $urls[ $row->id ] = $row->url;
        }

        return $urls;
    }

    /**
     * Remove multiple URLs at once
     *
     * @param array<string> $ids Row ids.
     */
    public function rmUrlsById( array $ids ) : void {
        $ids = array_map( 'absint', $ids );

        if ( ! $ids ) {
            return;
        }

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

    /**
     * @param string $url URL da togliere dalla coda.
     */
    public function rmUrl( string $url ) : void {
        $this->db->delete(
            $this->table,
            [
                'hashed_url' => md5( $url ),
            ]
        );
    }

    /**
     *  Count URLs in Crawl Queue
     */
    public function getTotal() : int {
        return (int) $this->db->get_var(
            $this->db->prepare( 'SELECT count(*) FROM %i', $this->table )
        );
    }

    /**
     * Svuota la coda.
     */
    public function truncate() : void {
        $this->db->query( (string) $this->db->prepare( 'TRUNCATE TABLE %i', $this->table ) );
    }
}
