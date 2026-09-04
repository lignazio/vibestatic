<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * La Crawl Cache decide quali pagine sono cambiate e quali no: e' il pezzo su
 * cui poggia tutto il resto del plugin, e fino a qui non aveva un test solo,
 * perche' le sue query stavano in metodi statici che leggevano `global $wpdb`.
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

        // get_var() restituisce sempre stringhe: senza il cast, un chiamante
        // che confronta con === 0 non trova mai niente.
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
        // L'URL non compare mai grezzo: c'e' il suo md5 come chiave.
        $this->assertStringContainsString( "'" . md5( 'https://foo.com/about/' ) . "'", $captured );
        $this->assertStringContainsString( "'https://foo.com/about/'", $captured );

        // Il timestamp e' calcolato una volta sola. Prima current_time() veniva
        // chiamata due volte, una per l'INSERT e una per l'UPDATE: a cavallo di
        // un secondo le due meta' della stessa query dicevano orari diversi.
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

        // Il terzo non e' un numero: absint() lo azzera, e comunque passa da %d.
        ( new CrawlCacheRepository( $wpdb ) )->rmUrlsById( [ '4', '9', '7 OR 1=1' ] );

        $this->assertSame(
            'DELETE FROM `wp_wp2static_crawl_cache` WHERE id IN ( 4, 9, 7 )',
            $captured
        );
    }

    public function testRmUrlsByIdWithNoIdsRunsNoQuery() : void {
        WP_Mock::userFunction( 'absint', [ 'return' => fn( $v ) => abs( (int) $v ) ] );

        $wpdb = $this->db();

        // Senza il guardiano la query diventerebbe `id IN ( )`, che e' un
        // errore di sintassi, non una cancellazione a vuoto.
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

        // E' una callback di filtro: deve conservare quello che le arriva.
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
        // E' la giuntura che rende il resto verificabile: senza, CrawlCache
        // andrebbe a prendersi `global $wpdb` e in un test non ci sarebbe
        // niente da sostituire.
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
}
