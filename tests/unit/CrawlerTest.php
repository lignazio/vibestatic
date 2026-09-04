<?php

namespace WP2Static;

use Mockery;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Handler\MockHandler;
use WP2Static\Vendor\GuzzleHttp\HandlerStack;
use WP2Static\Vendor\GuzzleHttp\Psr7\Response;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Il crawler e' il pezzo che decide cosa viene ripubblicato: e' lui a
 * confrontare l'hash della pagina appena scaricata con quello in cache, ed e'
 * su quel confronto che poggia il deploy incrementale. Non aveva un test,
 * perche' il client HTTP se lo costruiva dentro il costruttore e l'unico modo
 * di esercitarlo era avere un sito vero dall'altra parte.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CrawlerTest extends TestCase {

    /**
     * @var array<string, string> Quello che il crawler ha scritto su disco.
     */
    private $written = [];

    /**
     * @var array<int, array<string, mixed>> Quello che ha messo in cache.
     */
    private $cached = [];

    public function setUp() : void {
        WP_Mock::setUp();

        $this->written = [];
        $this->cached = [];

        Mockery::mock( 'overload:\WP2Static\WsLog' )
            ->shouldReceive( 'l' )->andReturnNull()
            ->shouldReceive( 'w' )->andReturnNull();

        Mockery::mock( 'overload:\WP2Static\StaticSite' )
            ->shouldReceive( 'add' )->andReturnUsing(
                function ( $path, $contents ) {
                    $this->written[ $path ] = $contents;
                }
            )
            ->shouldReceive( 'getPath' )->andReturn( '/percorso/che-non-esiste/' );

        Mockery::mock( 'overload:\WP2Static\ProcessedSite' )
            ->shouldReceive( 'getPath' )->andReturn( '/percorso/che-non-esiste/' );

        WP_Mock::userFunction( 'trailingslashit', [ 'return' => fn( $s ) => rtrim( $s, '/' ) . '/' ] );
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
     * @param array<string, string|false> $cache Percorso => hash già in cache.
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

        // Un percorso che finisce con / diventa un index.html: e' la
        // trasformazione che rende navigabile il sito statico.
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
         * E' il cuore del riconoscimento delle pagine cambiate: la richiesta
         * HTTP si fa comunque — la cache non evita lo scaricamento — ma se
         * l'hash coincide il file non viene riscritto. E' per questo che le
         * pagine dipendenti da un post modificato si scoprono da sole, senza
         * che nessuno dichiari chi dipende da chi.
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

        // Una pagina che non c'e' piu' non va scritta e non va messa in cache,
        // o al giro dopo risulterebbe «invariata» e resterebbe pubblicata.
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
        // L'hash di un redirect e' fatto di stato e destinazione, non del
        // corpo: e' cosi' che si accorge se la destinazione cambia.
        $this->assertSame( md5( '301/nuovo/' ), $this->cached[0]['hash'] );
    }
}
