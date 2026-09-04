<?php
/**
 * Facciata statica della Crawl Cache.
 *
 * Non contiene piu' query: le tiene CrawlCacheRepository, che riceve la
 * connessione dal costruttore ed e' quindi verificabile. Questa classe resta
 * perche' e' API pubblica — ventuno addon la chiamano staticamente, e
 * `wp2static_list_redirects` e' agganciata a un filtro con quel nome esatto.
 *
 * setRepository() esiste per i test e per chi voglia sostituire lo strato di
 * persistenza; passandogli null si torna al comportamento normale.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CrawlCache {

    /**
     * @var CrawlCacheRepository|null
     */
    private static $repository = null;

    public static function setRepository( ?CrawlCacheRepository $repository ) : void {
        self::$repository = $repository;
    }

    public static function repository() : CrawlCacheRepository {
        if ( ! self::$repository ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            self::$repository = new CrawlCacheRepository( $wpdb );
        }

        return self::$repository;
    }

    public static function createTable() : void {
        self::repository()->createTable();
    }

    /**
     *  Get all Crawl Cache URLs
     *
     *  @return string[] All URLs
     */
    public static function getHashes() : array {
        return self::repository()->getHashes();
    }

    public static function addUrl( string $url, string $page_hash, int $status,
                                   ?string $redirect_to ) : void {
        self::repository()->addUrl( $url, $page_hash, $status, $redirect_to );
    }

    public static function getUrl( string $url, string $page_hash ) : string {
        return self::repository()->getUrl( $url, $page_hash );
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
        return self::repository()->getURLs();
    }

    public static function rmUrl( string $url ) : void {
        self::repository()->rmUrl( $url );
    }

    /**
     * Remove multiple URLs at once
     *
     * @param array<string> $ids
     * @return void
     */
    public static function rmUrlsById( array $ids ) : void {
        self::repository()->rmUrlsById( $ids );
    }

    /**
     *  Clear CrawlCache via truncation
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting CrawlCache' );

        self::repository()->truncate();

        if ( self::getTotal() > 0 ) {
            WsLog::l( 'Failed to truncate CrawlCache: try deleting instead' );
        }
    }

    /**
     *  Count URLs in Crawl Cache
     */
    public static function getTotal() : int {
        return self::repository()->getTotal();
    }

    /**
     * @param mixed[] $redirs
     * @return mixed[] redirects
     */
    public static function wp2static_list_redirects( array $redirs ) : array {
        return self::repository()->listRedirects( $redirs );
    }
}
