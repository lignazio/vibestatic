<?php

namespace WP2Static;

use Mockery;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Handler\MockHandler;
use WP2Static\Vendor\GuzzleHttp\HandlerStack;
use WP2Static\Vendor\GuzzleHttp\Exception\ConnectException;
use WP2Static\Vendor\GuzzleHttp\Psr7\Request;
use WP2Static\Vendor\GuzzleHttp\Psr7\Response;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The crawler is the piece that decides what gets republished: it compares the
 * hash of the page just downloaded with the one in cache, and the incremental
 * deploy rests on that comparison. It had no test, because it built its HTTP
 * client inside the constructor and the only way to exercise it was to have a
 * real site on the other end.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CrawlerTest extends TestCase {

    /**
     * @var array<string, string> What the crawler wrote to disk.
     */
    private $written = [];

    /**
     * @var array<int, array<string, mixed>> What it put in the cache.
     */
    private $cached = [];

    /**
     * @var string[]|null The list of paths that must stay, as it reached
     *                    StaticSite::prune(). Null if prune() was never
     *                    called at all.
     */
    private $pruned_against = null;

    public function setUp() : void {
        WP_Mock::setUp();

        $this->written = [];
        $this->cached = [];
        $this->pruned_against = null;

        Mockery::mock( 'overload:\WP2Static\WsLog' )
            ->shouldReceive( 'l' )->andReturnNull()
            ->shouldReceive( 'w' )->andReturnNull();

        Mockery::mock( 'overload:\WP2Static\StaticSite' )
            ->shouldReceive( 'add' )->andReturnUsing(
                function ( $path, $contents ) {
                    $this->written[ $path ] = $contents;
                }
            )
            ->shouldReceive( 'prune' )->andReturnUsing(
                function ( $expected ) {
                    $this->pruned_against = $expected;

                    return [];
                }
            )
            ->shouldReceive( 'getPath' )->andReturn( '/path/that-does-not-exist/' );

        Mockery::mock( 'overload:\WP2Static\ProcessedSite' )
            ->shouldReceive( 'getPath' )->andReturn( '/path/that-does-not-exist/' );

        WP_Mock::userFunction( 'trailingslashit', [ 'return' => fn( $s ) => rtrim( $s, '/' ) . '/' ] );

        Mockery::mock( 'overload:\WP2Static\FilesHelper' )
            ->shouldReceive( 'pruningEnabled' )->andReturn( true );
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param string[] $paths
     * @param bool     $use_cache
     */
    private function mockOptionsAndQueue( array $paths, bool $use_cache ) : void {
        Mockery::mock( 'overload:\WP2Static\CoreOptions' )
            ->shouldReceive( 'getValue' )->andReturnUsing(
                function ( $name ) use ( $use_cache ) {
                    if ( 'useCrawlCaching' === $name ) {
                        return $use_cache ? '1' : '';
                    }

                    if ( 'crawlConcurrency' === $name ) {
                        return '1';
                    }

                    return '';
                }
            );

        Mockery::mock( 'overload:\WP2Static\CrawlQueue' )
            ->shouldReceive( 'getCrawlablePaths' )->andReturn( $paths )
            ->shouldReceive( 'rmUrl' )->andReturnNull();
    }

    /**
     * @param array<string, string|false> $cache Path => hash already in cache.
     */
    private function mockCrawlCache( array $cache ) : void {
        Mockery::mock( 'overload:\WP2Static\CrawlCache' )
            ->shouldReceive( 'getUrl' )->andReturnUsing(
                function ( $path, $hash ) use ( $cache ) {
                    return ( ( $cache[ $path ] ?? null ) === $hash ) ? md5( $path ) : '';
                }
            )
            ->shouldReceive( 'addUrl' )->andReturnUsing(
                function ( $path, $hash, $status, $redirect ) {
                    $this->cached[] = compact( 'path', 'hash', 'status', 'redirect' );
                }
            )
            ->shouldReceive( 'rmUrl' )->andReturnNull();
    }

    /**
     * @param Response[] $responses
     */
    private function crawler( array $responses ) : Crawler {
        $stack = HandlerStack::create( new MockHandler( $responses ) );

        return new Crawler(
            new Client( [ 'handler' => $stack ] ),
            'https://esempio.it'
        );
    }

    public function testAPageIsSavedWithTheDirectoryIndexAppended() : void {
        $this->mockOptionsAndQueue( [ '/chi-siamo/' ], false );
        $this->mockCrawlCache( [] );

        $this->crawler( [ new Response( 200, [], '<h1>Chi siamo</h1>' ) ] )
            ->crawlSite( '/statico' );

        // A path ending in / becomes an index.html: the transformation that
        // makes the static site navigable.
        $this->assertSame(
            [ '/chi-siamo/index.html' => '<h1>Chi siamo</h1>' ],
            $this->written
        );
        $this->assertSame( md5( '<h1>Chi siamo</h1>' ), $this->cached[0]['hash'] );
        $this->assertSame( 200, $this->cached[0]['status'] );
    }

    public function testAnUnchangedPageIsCountedAsCachedAndNotRewritten() : void {
        $contents = '<h1>Identica</h1>';

        $this->mockOptionsAndQueue( [ '/identica/' ], true );
        $this->mockCrawlCache( [ '/identica/' => md5( $contents ) ] );

        $this->crawler( [ new Response( 200, [], $contents ) ] )->crawlSite( '/statico' );

        /*
         * This is the heart of recognising changed pages: the HTTP request
         * happens regardless — the cache does not avoid the download — but if
         * the hash matches, the file is not rewritten. It is why the pages that
         * depend on a modified post are discovered by themselves, without
         * anyone declaring who depends on whom.
         */
        $this->assertSame( [], $this->written );
    }

    public function testAChangedPageIsRewritten() : void {
        $this->mockOptionsAndQueue( [ '/cambiata/' ], true );
        $this->mockCrawlCache( [ '/cambiata/' => md5( 'vecchio contenuto' ) ] );

        $this->crawler( [ new Response( 200, [], 'nuovo contenuto' ) ] )->crawlSite( '/statico' );

        $this->assertSame(
            [ '/cambiata/index.html' => 'nuovo contenuto' ],
            $this->written
        );
    }

    public function testA404IsNeitherSavedNorCached() : void {
        $this->mockOptionsAndQueue( [ '/sparita/' ], false );
        $this->mockCrawlCache( [] );

        $this->crawler( [ new Response( 404, [], '<h1>Non trovata</h1>' ) ] )
            ->crawlSite( '/statico' );

        // A page that is gone must not be written and must not be cached, or
        // on the next run it would read as "unchanged" and stay published.
        $this->assertSame( [], $this->written );
        $this->assertSame( [], $this->cached );
    }

    public function testARedirectIsCachedByItsDestinationAndNotByItsBody() : void {
        $this->mockOptionsAndQueue( [ '/vecchio/' ], false );
        $this->mockCrawlCache( [] );

        $this->crawler(
            [
                new Response(
                    301,
                    [ 'X-Guzzle-Redirect-History' => 'https://esempio.it/nuovo/' ],
                    'corpo che non conta'
                ),
            ]
        )->crawlSite( '/statico' );

        $this->assertSame( [], $this->written );
        $this->assertSame( '/nuovo/', $this->cached[0]['redirect'] );
        $this->assertSame( 301, $this->cached[0]['status'] );
        // A redirect's hash is made of status and destination, not of the
        // body: that is how a changed destination is noticed.
        $this->assertSame( md5( '301/nuovo/' ), $this->cached[0]['hash'] );
    }

    public function testAFinishedCrawlPrunesTheStaticSiteAgainstTheQueue() : void {
        $this->mockOptionsAndQueue( [ '/chi-siamo/', '/logo.svg' ], false );
        $this->mockCrawlCache( [] );

        $this->crawler(
            [
                new Response( 200, [], 'chi siamo' ),
                new Response( 200, [], '<svg/>' ),
            ]
        )->crawlSite( '/statico' );

        /*
         * The list of what to keep is the queue put through transformPath, not
         * what this crawl just wrote: a cache hit does not rewrite the file, and
         * comparing against the writes would delete the whole site on the first
         * cold crawl.
         */
        $this->assertSame(
            [ '/chi-siamo/index.html', '/logo.svg' ],
            $this->pruned_against
        );
    }

    public function testACrawlWhoseRequestsAllFailStillKeepsTheQueuedPaths() : void {
        $this->mockOptionsAndQueue( [ '/chi-siamo/' ], false );
        $this->mockCrawlCache( [] );

        // The server does not answer: the request is rejected, we serve no
        // Response at all.
        $stack = HandlerStack::create(
            new MockHandler( [ new ConnectException( 'niente rete', new Request( 'GET', '/' ) ) ] )
        );

        ( new Crawler( new Client( [ 'handler' => $stack ] ), 'https://esempio.it' ) )
            ->crawlSite( '/statico' );

        /*
         * An unreachable site does not unpublish itself: the URL is still in the
         * queue, so its file must be kept even though nothing arrived this time.
         */
        $this->assertSame( [], $this->written );
        $this->assertSame( [ '/chi-siamo/index.html' ], $this->pruned_against );
    }
}
