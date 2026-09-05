<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * The Crawl Cache decides which pages changed and which did not: it is the
 * piece the whole rest of the plugin rests on, and until now it did not have a
 * single test, because its queries sat in static methods reading `global $wpdb`.
 */
final class CrawlCacheRepositoryTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
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

    public function testTableNameComesFromThePrefix() : void {
        $wpdb = $this->db();
        $wpdb->prefix = 'blog7_';

        $captured = null;

        $wpdb->shouldReceive( 'get_var' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return '0';
                }
            );

        ( new CrawlCacheRepository( $wpdb ) )->getTotal();

        $this->assertSame( 'SELECT count(*) FROM `blog7_wp2static_crawl_cache`', $captured );
    }

    public function testGetTotalReturnsAnInteger() : void {
        $wpdb = $this->db();

        // get_var() always returns strings: without the cast, a caller
        // comparing with === 0 never finds anything.
        $wpdb->shouldReceive( 'get_var' )->andReturn( '42' );

        $total = ( new CrawlCacheRepository( $wpdb ) )->getTotal();

        $this->assertSame( 42, $total );
    }

    public function testAddUrlWritesTheSameTimestampInBothHalves() : void {
        WP_Mock::userFunction( 'current_time', [ 'return' => '2026-09-04 12:00:00' ] );

        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 1;
                }
            );

        ( new CrawlCacheRepository( $wpdb ) )
            ->addUrl( 'https://foo.com/about/', 'abc123', 200, null );

        $this->assertStringContainsString( '`wp_wp2static_crawl_cache`', $captured );
        // The URL never appears raw: its md5 is the key.
        $this->assertStringContainsString( "'" . md5( 'https://foo.com/about/' ) . "'", $captured );
        $this->assertStringContainsString( "'https://foo.com/about/'", $captured );

        // The timestamp is computed once. current_time() used to be called
        // twice, once for the INSERT and once for the UPDATE: across a second
        // boundary the two halves of the same query said different times.
        $this->assertSame( 2, substr_count( $captured, "'2026-09-04 12:00:00'" ) );
    }

    public function testGetUrlLooksUpByHashAndPageHash() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_var' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) {
                    $this->assertStringContainsString(
                        "WHERE hashed_url = '" . md5( 'https://foo.com/' ) . "'",
                        $sql
                    );
                    $this->assertStringContainsString( "AND page_hash = 'deadbeef'", $sql );

                    return 'una-riga';
                }
            );

        $found = ( new CrawlCacheRepository( $wpdb ) )->getUrl( 'https://foo.com/', 'deadbeef' );

        $this->assertSame( 'una-riga', $found );
    }

    public function testRmUrlsByIdGeneratesOnePlaceholderPerIdAndCastsThem() : void {
        WP_Mock::userFunction( 'absint', [ 'return' => fn( $v ) => abs( (int) $v ) ] );

        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 3;
                }
            );

        // The third is not a number: absint() zeroes it, and it goes through %d anyway.
        ( new CrawlCacheRepository( $wpdb ) )->rmUrlsById( [ '4', '9', '7 OR 1=1' ] );

        $this->assertSame(
            'DELETE FROM `wp_wp2static_crawl_cache` WHERE id IN ( 4, 9, 7 )',
            $captured
        );
    }

    public function testRmUrlsByIdWithNoIdsRunsNoQuery() : void {
        WP_Mock::userFunction( 'absint', [ 'return' => fn( $v ) => abs( (int) $v ) ] );

        $wpdb = $this->db();

        // Without the guard the query would become `id IN ( )`, which is a
        // syntax error, not a delete that matches nothing.
        $wpdb->shouldNotReceive( 'query' );

        ( new CrawlCacheRepository( $wpdb ) )->rmUrlsById( [] );

        $this->assertTrue( true );
    }

    public function testRmUrlDeletesByHashedUrl() : void {
        $wpdb = $this->db();

        $captured = null;

        $wpdb->shouldReceive( 'delete' )
            ->once()
            ->andReturnUsing(
                function ( $table, $where ) use ( &$captured ) {
                    $captured = [ $table, $where ];
                    return 1;
                }
            );

        ( new CrawlCacheRepository( $wpdb ) )->rmUrl( 'https://foo.com/gone/' );

        $this->assertSame(
            [
                'wp_wp2static_crawl_cache',
                [ 'hashed_url' => md5( 'https://foo.com/gone/' ) ],
            ],
            $captured
        );
    }

    public function testListRedirectsAddsToWhatItReceives() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'url' => '/vecchio/',
                    'redirect_to' => '/nuovo/',
                ],
            ]
        );

        // It is a filter callback: it must preserve what it is handed.
        $redirects = ( new CrawlCacheRepository( $wpdb ) )->listRedirects(
            [ '/da-un-addon/' => [ 'url' => '/da-un-addon/' ] ]
        );

        $this->assertArrayHasKey( '/da-un-addon/', $redirects );
        $this->assertSame(
            [
                'url' => '/vecchio/',
                'redirect_to' => '/nuovo/',
            ],
            $redirects['/vecchio/']
        );
    }

    public function testTheFacadeUsesTheInjectedRepository() : void {
        // This is the seam that makes the rest testable: without it CrawlCache
        // would reach for `global $wpdb` and a test would have nothing to
        // substitute.
        $repository = Mockery::mock( CrawlCacheRepository::class );
        $repository->shouldReceive( 'getTotal' )->once()->andReturn( 7 );

        CrawlCache::setRepository( $repository );

        try {
            $this->assertSame( 7, CrawlCache::getTotal() );
        } finally {
            CrawlCache::setRepository( null );
        }
    }

    public function testGetURLsIsKeyedById() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'id' => 7,
                    'url' => '/a/',
                ],
                (object) [
                    'id' => 9,
                    'url' => '/b/',
                ],
            ]
        );

        $urls = ( new CrawlCacheRepository( $wpdb ) )->getURLs();

        $this->assertSame( [ 7, 9 ], array_keys( $urls ) );
    }

    public function testRmUrlsDeletesByTheHashOfEachUrl() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return 2;
            }
        );

        ( new CrawlCacheRepository( $wpdb ) )->rmUrls( [ '/chi-siamo/', '/logo.svg' ] );

        /*
         * The rows go together with the queue's. If they stayed, a URL coming
         * back with the same content would be recognised as already seen, its
         * file would not be rewritten, and it would stay detected, crawled and
         * absent from the published site: a worse hole than the one pruning
         * closes.
         */
        $this->assertSame(
            sprintf(
                "DELETE FROM `wp_wp2static_crawl_cache` WHERE hashed_url IN ( '%s', '%s' )",
                md5( '/chi-siamo/' ),
                md5( '/logo.svg' )
            ),
            $captured
        );
    }

    public function testRmUrlsSplitsALongListIntoChunks() : void {
        $wpdb = $this->db();
        $queries = [];

        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$queries ) {
                $queries[] = $sql;
                return 1;
            }
        );

        ( new CrawlCacheRepository( $wpdb ) )->rmUrls(
            array_map( fn( $n ) => "/pagina-$n/", range( 1, 250 ) )
        );

        $this->assertCount( 3, $queries );
    }

    public function testRmUrlsWithNoUrlsRunsNoQuery() : void {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive( 'query' );

        ( new CrawlCacheRepository( $wpdb ) )->rmUrls( [] );

        $this->assertTrue( true );
    }
}
