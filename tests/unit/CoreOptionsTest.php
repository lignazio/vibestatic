<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CoreOptionsTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();

        Mockery::mock( 'overload:\WP2Static\WsLog' )
            ->shouldReceive( 'w' )->andReturnNull()
            ->shouldReceive( 'l' )->andReturnNull();
    }

    public function tearDown() : void {
        CoreOptions::setRepository( null );
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function repository() {
        $repository = Mockery::mock( CoreOptionsRepository::class );

        CoreOptions::setRepository( $repository );

        return $repository;
    }

    public function testAnUnknownOptionReturnsEmptyInsteadOfWarning() : void {
        /*
         * La riga era `$opt_spec = self::optionSpecs()[ $name ];` seguita da
         * `if ( ! $opt_spec )`. Su un nome sconosciuto PHP emette «Undefined
         * array key» PRIMA di arrivare alla guardia: qui, dove i warning sono
         * convertiti in eccezioni, il vecchio codice fa fallire questo test.
         * In produzione riempiva il log con un avviso che non nomina nemmeno
         * l'opzione, mentre il messaggio scritto apposta non usciva mai.
         */
        $this->repository()->shouldNotReceive( 'getValue' );

        $this->assertSame( '', CoreOptions::getValue( 'opzioneCheNonEsiste' ) );
    }

    public function testAKnownOptionFallsBackToItsDefaultWhenTheRowIsMissing() : void {
        $this->repository()->shouldReceive( 'getValue' )->once()->andReturn( null );

        WP_Mock::onFilter( 'wp2static_option_crawlConcurrency' )->with( '1' )->reply( '1' );

        $this->assertSame( '1', CoreOptions::getValue( 'crawlConcurrency' ) );
    }

    public function testGetOfAnUnknownOptionReturnsNull() : void {
        $this->repository()->shouldNotReceive( 'getRow' );

        $this->assertNull( CoreOptions::get( 'opzioneCheNonEsiste' ) );
    }

    public function testAPasswordOptionWithNoRowReturnsItsDefaultInsteadOfDying() : void {
        /*
         * La decifratura stava prima del controllo su $option e leggeva
         * `$option->value` su un risultato che puo' essere null: un'opzione di
         * tipo password mai salvata dava un fatal error, non un valore di
         * partenza.
         */
        $this->repository()->shouldReceive( 'getRow' )->once()->andReturn( null );

        WP_Mock::onFilter( 'wp2static_option_basicAuthPassword' )->with( '' )->reply( '' );

        $option = CoreOptions::get( 'basicAuthPassword' );

        $this->assertIsArray( $option );
        $this->assertSame( 'password', $option['type'] );
        $this->assertSame( '', $option['value'] );
    }

    public function testEncryptingRefusesToUseAPublishedKey() : void {
        /*
         * Senza AUTH_KEY e AUTH_SALT qui c'erano due chiavi scritte nel codice.
         * Il codice e' pubblico: cifrare la password della basic auth con una
         * chiave che chiunque puo' leggere non e' cifrarla, e' codificarla —
         * con l'aggravante che sembra cifrata. In questo processo le due
         * costanti non sono definite, che e' esattamente la condizione.
         */
        $this->expectException( WP2StaticException::class );
        $this->expectExceptionMessageMatches( '/AUTH_KEY e AUTH_SALT/' );

        CoreOptions::encrypt_decrypt( 'encrypt', 'una-password' );
    }

    public function testGetAllFillsInOptionsThatAreNotYetInTheTable() : void {
        /*
         * Ogni opzione nuova, fra l'aggiornamento del plugin e la prima
         * seedOptions(), non ha una riga: `$options_map[ $name ]` senza `??`
         * emetteva un «Undefined array key» prima della guardia che gestisce
         * proprio quel caso.
         */
        $this->repository()->shouldReceive( 'getAllRows' )->once()->andReturn( [] );

        WP_Mock::onFilter( 'wp2static_option_detectPosts' )->with( '1' )->reply( '1' );

        $all = CoreOptions::getAll();

        $this->assertArrayHasKey( 'detectPosts', $all );
        $this->assertSame( '1', $all['detectPosts']['value'] );
    }
}
