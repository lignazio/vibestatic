<?php

namespace WP2StaticDirectoryDeployer;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The core fires `wp2static_deploy` for every listener and passes the slug of
 * the deployer the user selected; each listener is meant to return early when
 * it is not the one.
 *
 * This add-on has always had that guard — it was the sftp add-on that did not,
 * and so uploaded on every deploy for five years without anyone noticing. The
 * test is here because "it has always had it" is exactly the claim nothing was
 * checking: this is the deployer the project itself publishes with, and the
 * guard is the only thing keeping two deployers from both writing when one was
 * chosen.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DirectoryDeploymentDeployGuardTest extends TestCase {

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
     *
     * The path is deliberately one that does not exist. Past the guard the
     * deployer's first act is an is_dir() check, so the call stops there and
     * the test never reaches the database — see DirectoryDeploymentDeployerTest
     * for what happens when it does get that far.
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

        ( new Controller() )->deploy( '/does/not/exist', $enabled_deployer );

        return $ran;
    }

    public function testItStaysOutWhenAnotherDeployerIsSelected() : void {
        $this->assertFalse( $this->deployRunsWith( 'wp2static-addon-sftp' ) );
    }

    /**
     * The empty string is what a caller firing the action with one argument
     * produces. Treating that as "it is me" is how the sftp add-on behaved.
     */
    public function testItStaysOutWhenNoDeployerIsNamed() : void {
        $this->assertFalse( $this->deployRunsWith( '' ) );
    }

    public function testItRunsWhenItIsTheSelectedDeployer() : void {
        $this->assertTrue(
            $this->deployRunsWith( 'wp2static-addon-directory-deployment' )
        );
    }
}
