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

    /**
     * A stored zero is a zero, not "unset".
     *
     * The test used to be `if ( ! $option_value )`, and '0' is falsy in PHP, so
     * every option stored as zero was sent back to its own default — thirteen
     * of which are not zero. What that looked like from the outside: unticking
     * a box on the Options page saved a 0 that was read back as a 1. The crawl
     * cache could not be turned off, and neither could the four detection
     * toggles or the four job-queue ones. The setting was written, the
     * interface showed it written, and nothing downstream ever saw it.
     */
    public function testAnOptionStoredAsZeroIsNotMistakenForUnset() : void {
        foreach ( [ 'useCrawlCaching', 'detectPosts', 'crawlProgressReportInterval' ] as $name ) {
            $this->repository()->shouldReceive( 'getValue' )->with( $name )->andReturn( '0' );

            $this->assertSame(
                '0',
                CoreOptions::getValue( $name ),
                "$name reads back as its default instead of the stored zero"
            );

            CoreOptions::setRepository( null );
        }
    }

    /**
     * A missing row still falls back, which is the behaviour the falsy test was
     * there for in the first place.
     */
    public function testAMissingRowStillFallsBackToTheDefault() : void {
        $this->repository()->shouldReceive( 'getValue' )
            ->with( 'crawlProgressReportInterval' )
            ->andReturn( null );

        $this->assertSame( '300', CoreOptions::getValue( 'crawlProgressReportInterval' ) );
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
        $this->expectExceptionMessageMatches( '/AUTH_KEY and AUTH_SALT/' );

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
