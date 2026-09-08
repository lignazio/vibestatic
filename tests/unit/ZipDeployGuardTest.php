<?php

namespace WP2StaticZip;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The ZIP module's two entry points, and the shape of the third.
 *
 * `deleteZip()` is here for a specific reason: it was registered on
 * `admin_post_wp2static_zip_delete` while declaring a required parameter, and
 * WordPress fires `admin_post_*` with no arguments at all. Every click on
 * "Delete ZIP" was an ArgumentCountError — a white screen, since PHP 8, for as
 * long as the button existed. A signature is not something a reader checks, so
 * it is checked here.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ZipDeployGuardTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * Reaching ZipArchiver means the guard let the call through.
     */
    private function deployRunsWith( string $enabled_deployer ) : bool {
        $ran = false;

        WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
        WP_Mock::userFunction( 'add_action', [ 'return' => true ] );

        $logger = Mockery::mock( 'alias:WP2Static\WsLog' );
        $logger->shouldReceive( 'l' )->andReturnUsing(
            function () use ( &$ran ) {
                $ran = true;
            }
        );

        ( new Controller() )->generateZip( '/does/not/exist', $enabled_deployer );

        return $ran;
    }

    public function testItStaysOutWhenAnotherDeployerIsSelected() : void {
        $this->assertFalse( $this->deployRunsWith( 'wp2static-addon-sftp' ) );
    }

    public function testItStaysOutWhenNoDeployerIsNamed() : void {
        $this->assertFalse( $this->deployRunsWith( '' ) );
    }

    public function testItRunsWhenItIsTheSelectedDeployer() : void {
        $this->assertTrue( $this->deployRunsWith( Controller::SLUG ) );
    }

    /**
     * The two admin_post handlers take no arguments.
     *
     * WordPress calls an `admin_post_*` callback with none; a required
     * parameter turns every click into an ArgumentCountError.
     */
    public function testTheAdminPostHandlersTakeNoRequiredArguments() : void {
        foreach ( [ 'deleteZip', 'downloadZip' ] as $handler ) {
            $method = new \ReflectionMethod( Controller::class, $handler );

            $this->assertSame(
                0,
                $method->getNumberOfRequiredParameters(),
                "Controller::$handler() is an admin_post handler and must take no arguments"
            );
        }
    }

    /**
     * One page slug, spelled once.
     *
     * The page was registered as `wp2static-addon-zip` while the refresh link
     * and the redirect after deleting both said `wp2static-zip`: a slug that
     * does not exist, so both landed on "you are not authorized". Neither had
     * ever worked.
     */
    public function testThePageSlugIsUsedConsistently() : void {
        $sources = [
            'src/Controller.php' => file_get_contents( __DIR__ . '/../../addons/zip/src/Controller.php' ),
            'views/zip-page.php' => file_get_contents( __DIR__ . '/../../addons/zip/views/zip-page.php' ),
        ];

        foreach ( $sources as $name => $source ) {
            $this->assertIsString( $source );
            $this->assertStringNotContainsString(
                "'wp2static-zip'",
                (string) $source,
                "$name still names the slug that does not exist"
            );
        }

        $this->assertSame( 'wp2static-addon-zip', Controller::PAGE );
    }
}
