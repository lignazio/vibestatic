<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CrawlQueueRepositoryTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
        WP_Mock::userFunction( 'absint', [ 'return' => fn( $v ) => abs( (int) $v ) ] );
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function db() {
        $wpdb = Mockery::mock( '\WPDB' );
        $wpdb->prefix = 'wp_';
        wp2static_test_mock_prepare( $wpdb );

        return $wpdb;
    }

    public function testAddUrlsStoresHashAndDecodedUrl() : void {
        $wpdb = $this->db();
        $queries = [];

        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$queries ) {
                $queries[] = $sql;
                return 1;
            }
        );

        ( new CrawlQueueRepository( $wpdb ) )->addUrls( [ '/caff%C3%A8/' ] );

        $this->assertCount( 1, $queries );
        $this->assertStringContainsString(
            'INSERT IGNORE INTO `wp_wp2static_urls` (hashed_url, url) VALUES',
            $queries[0]
        );
        // L'hash è dell'URL come arriva, il valore memorizzato è decodificato:
        // le due cose sono diverse di proposito e vanno lette insieme.
        $this->assertStringContainsString( "'" . md5( '/caff%C3%A8/' ) . "'", $queries[0] );
        $this->assertStringContainsString( "'/caffè/'", $queries[0] );
    }

    public function testAddUrlsSplitsIntoChunksOfOneHundred() : void {
        $wpdb = $this->db();
        $queries = [];

        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$queries ) {
                $queries[] = $sql;
                return 1;
            }
        );

        $urls = [];

        for ( $i = 0; $i < 250; $i++ ) {
            $urls[] = "/post-$i/";
        }

        ( new CrawlQueueRepository( $wpdb ) )->addUrls( $urls );

        // 250 URL: due INSERT piene e una da 50. Una INSERT sola con 500
        // segnaposto supererebbe max_allowed_packet su una sitemap vera.
        $this->assertCount( 3, $queries );
        $this->assertSame( 100, substr_count( $queries[0], '(' ) - 1 );
        $this->assertSame( 50, substr_count( $queries[2], '(' ) - 1 );
    }

    public function testAddUrlsWithNothingToAddRunsNoQuery() : void {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive( 'query' );

        ( new CrawlQueueRepository( $wpdb ) )->addUrls( [] );

        $this->assertTrue( true );
    }

    public function testCrawlablePathsAreOrderedAndKeyedById() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'get_results' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return [
                    (object) [
                        'id' => 3,
                        'url' => '/about/',
                    ],
                    (object) [
                        'id' => 1,
                        'url' => '/zeta/',
                    ],
                ];
            }
        );

        $paths = ( new CrawlQueueRepository( $wpdb ) )->getCrawlablePaths();

        $this->assertSame( 'SELECT id, url FROM `wp_wp2static_urls` ORDER BY url ASC', $captured );
        $this->assertSame(
            [
                3 => '/about/',
                1 => '/zeta/',
            ],
            $paths
        );
    }

    public function testRmUrlsByIdGeneratesOnePlaceholderPerId() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return 2;
            }
        );

        ( new CrawlQueueRepository( $wpdb ) )->rmUrlsById( [ '12', 'x' ] );

        $this->assertSame(
            'DELETE FROM `wp_wp2static_urls` WHERE id IN ( 12, 0 )',
            $captured
        );
    }

    public function testRmUrlsByIdSplitsALongListIntoChunks() : void {
        $wpdb = $this->db();
        $queries = [];

        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$queries ) {
                $queries[] = $sql;
                return 1;
            }
        );

        // Da quando la rilevazione allinea la coda, qui puo' arrivare tutta la
        // parte di sito sparita in un colpo solo: una DELETE con 250 %d
        // supererebbe max_allowed_packet su installazioni strette.
        ( new CrawlQueueRepository( $wpdb ) )->rmUrlsById(
            array_map( 'strval', range( 1, 250 ) )
        );

        $this->assertCount( 3, $queries );
        $this->assertSame( 100, substr_count( $queries[0], ',' ) + 1 );
        $this->assertSame( 50, substr_count( $queries[2], ',' ) + 1 );
    }

    public function testRmUrlsByIdWithNoIdsRunsNoQuery() : void {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive( 'query' );

        ( new CrawlQueueRepository( $wpdb ) )->rmUrlsById( [] );

        $this->assertTrue( true );
    }

    public function testGetTotalReturnsAnInteger() : void {
        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '1830' );

        $this->assertSame( 1830, ( new CrawlQueueRepository( $wpdb ) )->getTotal() );
    }

    public function testTheFacadeUsesTheInjectedRepository() : void {
        $repository = Mockery::mock( CrawlQueueRepository::class );
        $repository->shouldReceive( 'getTotal' )->once()->andReturn( 1830 );

        CrawlQueue::setRepository( $repository );

        try {
            // getTotalCrawlableURLs() e getTotal() sono due nomi per la stessa
            // domanda: se un giorno divergono, questo test se ne accorge.
            $this->assertSame( 1830, CrawlQueue::getTotalCrawlableURLs() );
        } finally {
            CrawlQueue::setRepository( null );
        }
    }
}
