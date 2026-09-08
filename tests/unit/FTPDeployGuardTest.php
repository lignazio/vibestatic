<?php

namespace WP2StaticFTP;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The FTP module's guards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FTPDeployGuardTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    private function deployRunsWith( string $enabled_deployer ) : bool {
        $ran = false;

        WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
        WP_Mock::userFunction( 'add_action', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_filter', [ 'return' => true ] );

        Mockery::mock( 'alias:WP2Static\WsLog' )->shouldReceive( 'l' )->andReturnUsing(
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

    public function testItStaysOutWhenNoDeployerIsNamed() : void {
        $this->assertFalse( $this->deployRunsWith( '' ) );
    }

    public function testItRunsWhenItIsTheSelectedDeployer() : void {
        $this->assertTrue( $this->deployRunsWith( Controller::SLUG ) );
    }

    /**
     * Its own deploy-cache namespace, not sFTP's.
     *
     * The two modules can point at different servers. Sharing a namespace would
     * have each of them read the other's uploads as its own and skip files it
     * had never sent — which is what this add-on's ancestor did by writing to
     * `default`.
     */
    public function testItHasADeployCacheNamespaceOfItsOwn() : void {
        $this->assertSame( 'wp2static-addon-ftp', Deployer::DEFAULT_NAMESPACE );
        $this->assertNotSame( 'default', Deployer::DEFAULT_NAMESPACE );
        $this->assertSame( Controller::SLUG, Deployer::DEFAULT_NAMESPACE );
    }

    /**
     * Encryption is the default.
     *
     * Plain FTP sends the password, and then every byte of the site, in the
     * clear. It stays available because shared hosting sometimes offers nothing
     * else, but it has to be asked for — so the default is the safe one, and a
     * test says so rather than a comment.
     */
    public function testTheConnectionIsEncryptedUnlessAskedOtherwise() : void {
        $this->assertSame( '1', Controller::DEFAULTS['use_tls'] );
    }

    /**
     * The password is not among the fields written back as plain text.
     */
    public function testThePasswordIsNotStoredInTheClear() : void {
        $source = file_get_contents( __DIR__ . '/../../addons/ftp/src/Controller.php' );

        $this->assertIsString( $source );
        $this->assertStringContainsString(
            "encrypt_decrypt( 'encrypt', \$password )",
            (string) $source
        );
    }
}
