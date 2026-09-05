<?php
/**
 * Static facade over the crawl queue.
 *
 * Le query stanno in CrawlQueueRepository. Qui restano i nomi pubblici e i
 * messaggi di log.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CrawlQueue {

    /**
     * @var CrawlQueueRepository|null
     */
    private static $repository = null;

    /**
     * @param CrawlQueueRepository|null $repository Null to fall back to the default.
     */
    public static function setRepository( ?CrawlQueueRepository $repository ) : void {
        self::$repository = $repository;
    }

    /**
     * @return CrawlQueueRepository Built on `global $wpdb` when not injected.
     */
    public static function repository() : CrawlQueueRepository {
        if ( ! self::$repository ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            self::$repository = new CrawlQueueRepository( $wpdb );
        }

        return self::$repository;
    }

    /**
     * Create the queue table.
     */
    public static function createTable() : void {
        self::repository()->createTable();
    }

    /**
     * Add all Urls to queue
     *
     * @param string[] $urls List of URLs to crawl
     */
    public static function addUrls( array $urls ) : void {
        self::repository()->addUrls( $urls );
    }

    /**
     *  Get all crawlable URLs
     *
     *  The key is the row id, not an index: it is what lets a caller remove
     *  exactly those rows later without going back through the URL.
     *
     *  @return array<int, string> All crawlable URLs, keyed by row id
     */
    public static function getCrawlablePaths() : array {
        return self::repository()->getCrawlablePaths();
    }

    /**
     * Remove multiple URLs at once
     *
     * @param array<string> $ids Row ids.
     * @return void
     */
    public static function rmUrlsById( array $ids ) : void {
        self::repository()->rmUrlsById( $ids );
    }

    /**
     * @param string $url URL to remove from the queue.
     */
    public static function rmUrl( string $url ) : void {
        self::repository()->rmUrl( $url );
    }

    /**
     *  Get total crawlable URLs
     *
     *  Same query as getTotal(): two names for the same question, both of
     *  which predate this fork. Both stay, because both are API.
     *
     *  @return int Total crawlable URLs
     */
    public static function getTotalCrawlableURLs() : int {
        return self::repository()->getTotal();
    }

    /**
     *  Clear CrawlQueue via truncate or deletion
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting CrawlQueue (Detected URLs)' );

        self::repository()->truncate();

        if ( self::getTotal() > 0 ) {
            WsLog::l( 'failed to truncate CrawlQueue: try deleting instead' );
        }
    }

    /**
     *  Count URLs in Crawl Queue
     */
    public static function getTotal() : int {
        return self::repository()->getTotal();
    }
}
