<?php

namespace WP2StaticNetlify;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * How a path is put into the upload URL.
 *
 * The original used `urlencode()` on the whole remote path, and a remote path
 * begins with a slash — `ProcessedSite::getPath()` returns no trailing one — so
 * `/index.html` became `%2Findex.html` and the request went to
 * `…/deploys/{id}/files%2Findex.html`, where the path had fused with the
 * `files` segment. Measured against a server that routes on the raw path:
 * every upload failed, for every file, not only the nested ones.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NetlifyPathEncodingTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    private function encode( string $path ) : string {
        $method = new \ReflectionMethod( Deployer::class, 'encodePath' );
        $method->setAccessible( true );

        return (string) $method->invoke( new Deployer(), $path );
    }

    /**
     * The separators survive. They are what makes it a path.
     */
    public function testItKeepsTheSlashes() : void {
        $this->assertSame( '/index.html', $this->encode( '/index.html' ) );
        $this->assertSame(
            '/blog/2019/august/index.html',
            $this->encode( '/blog/2019/august/index.html' )
        );
    }

    /**
     * A space is %20, not +.
     *
     * `urlencode()` produces `+`, which is the form for a query string and
     * means a literal plus inside a path — so a directory called "with space"
     * would be asked for under a name containing a plus sign.
     */
    public function testItEncodesASpaceForAPathAndNotForAQueryString() : void {
        $this->assertSame( '/with%20space/index.html', $this->encode( '/with space/index.html' ) );
        $this->assertStringNotContainsString( '+', $this->encode( '/with space/index.html' ) );
    }

    /**
     * Characters that would end the path segment are encoded.
     */
    public function testItEncodesWhatWouldBreakTheURL() : void {
        $this->assertSame( '/a%3Fb.html', $this->encode( '/a?b.html' ) );
        $this->assertSame( '/a%23b.html', $this->encode( '/a#b.html' ) );
    }

    /**
     * Whatever it does, decoding it segment by segment gives the path back.
     *
     * The property that matters, and the one the original broke: the server on
     * the other end decodes each segment, and what it gets must be the file's
     * real path.
     */
    public function testTheServerGetsBackThePathItWasGiven() : void {
        foreach (
            [
                '/index.html',
                '/blog/2019/august/index.html',
                '/with space/index.html',
                '/a?b.html',
                '/percento-100%/index.html',
            ] as $path
        ) {
            $decoded = implode(
                '/',
                array_map( 'rawurldecode', explode( '/', $this->encode( $path ) ) )
            );

            $this->assertSame( $path, $decoded );
        }
    }
}
