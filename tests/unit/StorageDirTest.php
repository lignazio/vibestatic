<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The directory under uploads that the plugin writes into.
 *
 * What is being tested here is not that a folder gets created. It is that the
 * three files which keep a web server from serving a whole copy of the site are
 * written, that upgrading does not lose an archive somebody has not downloaded
 * yet, and that the crawl's exclusion is a rule rather than a string match that
 * a neighbouring directory name can defeat.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StorageDirTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();

        Mockery::mock( 'overload:\WP2Static\WsLog' )
            ->shouldReceive( 'l' )->andReturnNull()
            ->shouldReceive( 'w' )->andReturnNull();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * An uploads directory in memory, with SiteInfo pointing at it.
     */
    private function uploads() : string {
        $vfs = vfsStream::setup( 'uploads' );
        $url = $vfs->url() . '/';

        Mockery::mock( 'overload:\WP2Static\SiteInfo' )
            ->shouldReceive( 'getPath' )->andReturn( $url );

        return $url;
    }

    public function testItCreatesTheDirectoryAndProtectsIt() : void {
        $uploads = $this->uploads();

        $this->assertTrue( StorageDir::ensure() );

        $storage = $uploads . 'vibestatic/';

        $this->assertDirectoryExists( $storage );

        foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $filename ) {
            $this->assertFileExists(
                $storage . $filename,
                "$filename is what stops a web server serving the whole site to anyone who guesses the path."
            );
        }

        $htaccess = (string) file_get_contents( $storage . '.htaccess' );

        // Both Apache generations: a site on 2.2 gets no protection at all from
        // `Require all denied`, and one on 2.4 without mod_access_compat
        // answers 500 to `Deny from all` — so each is inside its own IfModule.
        $this->assertStringContainsString( 'Require all denied', $htaccess );
        $this->assertStringContainsString( 'Deny from all', $htaccess );
    }

    /**
     * A host that needs something else in there gets to keep it.
     */
    public function testItLeavesAnExistingProtectionFileAlone() : void {
        $uploads = $this->uploads();

        StorageDir::ensure();

        $htaccess = $uploads . 'vibestatic/.htaccess';
        file_put_contents( $htaccess, "# mine\n" );

        StorageDir::ensure();

        $this->assertSame( "# mine\n", (string) file_get_contents( $htaccess ) );
    }

    public function testItRecognisesPathsInsideItself() : void {
        $uploads = $this->uploads();

        $this->assertTrue(
            StorageDir::contains( $uploads . 'vibestatic/crawled-site/index.html' )
        );

        $this->assertFalse(
            StorageDir::contains( $uploads . '2026/09/foto.jpg' )
        );
    }

    /**
     * The trailing slash in the comparison is the whole of this test: without
     * it, a user's own `uploads/vibestatic-exports/` would be silently dropped
     * from every crawl because its name begins with ours.
     */
    public function testANeighbourWhoseNameStartsTheSameIsNotInside() : void {
        $uploads = $this->uploads();

        $this->assertFalse(
            StorageDir::contains( $uploads . 'vibestatic-exports/index.html' )
        );
    }

    public function testItMovesWhatEarlierVersionsLeftInTheUploadsRoot() : void {
        $uploads = $this->uploads();

        mkdir( $uploads . 'wp2static-crawled-site' );
        file_put_contents( $uploads . 'wp2static-crawled-site/index.html', 'crawled' );
        mkdir( $uploads . 'wp2static-processed-site' );
        file_put_contents( $uploads . 'wp2static-processed-site/index.html', 'processed' );
        file_put_contents( $uploads . 'wp2static-processed-site.zip', 'PK' );

        StorageDir::migrateLegacyPaths();

        $storage = $uploads . 'vibestatic/';

        $this->assertSame(
            'crawled',
            (string) file_get_contents( $storage . 'crawled-site/index.html' )
        );
        $this->assertSame(
            'processed',
            (string) file_get_contents( $storage . 'processed-site/index.html' )
        );
        $this->assertSame(
            'PK',
            (string) file_get_contents( $storage . 'processed-site.zip' ),
            'The archive is the one thing here that cannot be rebuilt by running an export again.'
        );

        $this->assertDirectoryDoesNotExist( $uploads . 'wp2static-crawled-site' );
        $this->assertFileDoesNotExist( $uploads . 'wp2static-processed-site.zip' );
    }

    /**
     * Schema::install() calls this on every upgrade, not only the one that
     * introduced it.
     */
    public function testMigratingTwiceDoesNotOverwriteTheNewArchive() : void {
        $uploads = $this->uploads();

        StorageDir::ensure();
        file_put_contents( $uploads . 'vibestatic/processed-site.zip', 'current' );
        file_put_contents( $uploads . 'wp2static-processed-site.zip', 'stale' );

        StorageDir::migrateLegacyPaths();

        $this->assertSame(
            'current',
            (string) file_get_contents( $uploads . 'vibestatic/processed-site.zip' )
        );
    }
}
