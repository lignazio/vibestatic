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
         * The line used to be `$opt_spec = self::optionSpecs()[ $name ];`
         * followed by `if ( ! $opt_spec )`. On an unknown name PHP emits
         * "Undefined array key" BEFORE reaching the guard: here, where warnings
         * become exceptions, the old code fails this test. In production it
         * filled the log with a notice that does not even name the option,
         * while the message written for the purpose never appeared.
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
         * Decryption sat before the check on $option and read `$option->value`
         * on a result that can be null: a password option that had never been
         * saved gave a fatal error rather than a default.
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
         * Without AUTH_KEY and AUTH_SALT there used to be two keys written into
         * the source here. The source is public: encrypting the basic auth
         * password with a key anyone can read is not encrypting it, it is
         * encoding it — with the added harm that it looks encrypted. In this
         * process the two constants are undefined, which is exactly the
         * condition.
         */
        $this->expectException( WP2StaticException::class );
        $this->expectExceptionMessageMatches( '/AUTH_KEY e AUTH_SALT/' );

        CoreOptions::encrypt_decrypt( 'encrypt', 'una-password' );
    }

    public function testGetAllFillsInOptionsThatAreNotYetInTheTable() : void {
        /*
         * Every new option, between the plugin update and the first
         * seedOptions(), has no row: `$options_map[ $name ]` without `??`
         * emitted an "Undefined array key" before the very guard meant to
         * handle that case.
         */
        $this->repository()->shouldReceive( 'getAllRows' )->once()->andReturn( [] );

        WP_Mock::onFilter( 'wp2static_option_detectPosts' )->with( '1' )->reply( '1' );

        $all = CoreOptions::getAll();

        $this->assertArrayHasKey( 'detectPosts', $all );
        $this->assertSame( '1', $all['detectPosts']['value'] );
    }
}
