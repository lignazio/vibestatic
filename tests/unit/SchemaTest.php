<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SchemaTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testAFreshSiteNeedsTheSchema() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => false ] );

        $this->assertTrue( Schema::needsUpdate() );
    }

    public function testASiteAtTheCurrentVersionDoesNot() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) Schema::VERSION ] );

        $this->assertFalse( Schema::needsUpdate() );
    }

    public function testASiteAtAnOlderVersionDoes() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) ( Schema::VERSION - 1 ) ] );

        /*
         * E' il caso che prima non esisteva: le tabelle si creavano solo dentro
         * register_activation_hook, che aggiornando il plugin non scatta. Una
         * colonna aggiunta in una versione nuova non sarebbe mai arrivata sui
         * siti gia' installati.
         */
        $this->assertTrue( Schema::needsUpdate() );
    }

    public function testNothingHappensOnAFrontEndRequest() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => false ] );

        // Il confronto costa una lettura di un'opzione autoloaded; dbDelta no.
        // Farlo partire dalla richiesta di un visitatore vuol dire fargli
        // pagare l'aggiornamento.
        WP_Mock::userFunction( 'get_option', [ 'times' => 0 ] );

        Schema::updateIfNeeded();

        $this->assertTrue( true );
    }

    public function testAnAdminRequestAtTheCurrentVersionWritesNothing() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => true ] );
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) Schema::VERSION ] );
        WP_Mock::userFunction( 'update_option', [ 'times' => 0 ] );

        Schema::updateIfNeeded();

        $this->assertTrue( true );
    }
}
