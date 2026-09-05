<?php

namespace WP2StaticSFTP;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The core fires `wp2static_deploy` for every listener and passes the slug of
 * the deployer the user selected; each deployer is meant to return early when
 * it is not the one.
 *
 * This add-on registered for a single argument, so it never saw the slug and
 * uploaded on every deploy regardless. Measured before the fix: with Directory
 * Deployment selected and nothing to publish, merely having this add-on active
 * produced 1802 attempted uploads to an unconfigured server and 1802 log rows.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SFTPDeployGuardTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * Reaching WsLog::l() means the guard let the call through: the first thing
     * deploy() does past it is log that it is deploying.
     */
    private function deployRunsWith( string $enabled_deployer ) : bool {
        $ran = false;

        WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
        WP_Mock::userFunction( 'add_action', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_filter', [ 'return' => true ] );

        $logger = Mockery::mock( 'alias:WP2Static\WsLog' );
        $logger->shouldReceive( 'l' )->andReturnUsing(
            function () use ( &$ran ) {
                $ran = true;
            }
        );

        ( new Controller() )->deploy( '/does/not/matter', $enabled_deployer );

        return $ran;
    }

    public function testItStaysOutWhenAnotherDeployerIsSelected() : void {
        $this->assertFalse(
            $this->deployRunsWith( 'wp2static-addon-directory-deployment' )
        );
    }

    /**
     * The empty string is what a caller firing the action with one argument
     * produces. Treating that as "it is me" is how the original behaved.
     */
    public function testItStaysOutWhenNoDeployerIsNamed() : void {
        $this->assertFalse( $this->deployRunsWith( '' ) );
    }

    public function testItRunsWhenItIsTheSelectedDeployer() : void {
        $this->assertTrue( $this->deployRunsWith( Controller::SLUG ) );
    }
}
