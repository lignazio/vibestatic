<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * What a crawled page is taken to point at.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LinkDiscoveryTest extends TestCase {

    const HOST = 'example.com';

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @return string[]
     */
    private function find( string $html, string $page = 'https://example.com/blog/post/' ) : array {
        $found = LinkDiscovery::find( $html, $page, self::HOST );

        sort( $found );

        return $found;
    }

    public function testItFindsWhereALinkPoints() : void {
        $this->assertSame(
            [ '/about/', '/blog/other/' ],
            $this->find( '<a href="/about/">a</a> <a href="../other/">b</a>' )
        );
    }

    /**
     * Relative references resolve against the page they were found on, not
     * against the site root — which is the whole reason the page URL is passed
     * in rather than assumed.
     */
    public function testItResolvesRelativeReferencesAgainstThePage() : void {
        $this->assertSame(
            [ '/blog/post/deeper/' ],
            $this->find( '<a href="deeper/">x</a>' )
        );
    }

    public function testItFindsWhatAPageLoadsAsWellAsWhereItPoints() : void {
        $html = '<link rel="stylesheet" href="/style.css">'
            . '<script src="/app.js"></script>'
            . '<img src="/logo.png">'
            . '<video poster="/poster.jpg"></video>';

        $this->assertSame(
            [ '/app.js', '/logo.png', '/poster.jpg', '/style.css' ],
            $this->find( $html )
        );
    }

    /**
     * A srcset is a list, and reading it as one URL finds none of them.
     */
    public function testItReadsEveryURLInASrcset() : void {
        $this->assertSame(
            [ '/a-480.png', '/a-960.png' ],
            $this->find( '<img srcset="/a-480.png 480w, /a-960.png 960w">' )
        );
    }

    /**
     * Another host is not ours to crawl, and neither is a scheme that does not
     * fetch anything.
     */
    public function testItReadsTheLazyLoadingAttributes() : void {
        /*
         * Lazy loading is the normal way a theme ships media today: the URL
         * lives in `data-src` and JavaScript moves it into `src` when the
         * element approaches the viewport. Reading only `src` means the file
         * never gets crawled, and the published page points at a video that is
         * not there — measured on a real portfolio, thirteen of them.
         */
        $this->assertSame(
            [
                '/wp-content/uploads/loop.mp4',
                '/wp-content/uploads/poster.webp',
            ],
            $this->find(
                '<video data-poster="/wp-content/uploads/poster.webp">' .
                '<source data-src="/wp-content/uploads/loop.mp4" type="video/mp4">' .
                '</video>'
            )
        );
    }

    public function testItReadsEveryURLInALazySrcset() : void {
        $this->assertSame(
            [ '/img/grande.jpg', '/img/piccola.jpg' ],
            $this->find(
                '<img data-srcset="/img/piccola.jpg 480w, /img/grande.jpg 1200w">'
            )
        );
    }

    public function testItIgnoresWhatIsNotAPageOfOurs() : void {
        $html = '<a href="https://elsewhere.test/page/">off site</a>'
            . '<a href="mailto:someone@example.com">mail</a>'
            . '<a href="tel:+390212345">phone</a>'
            . '<a href="javascript:void(0)">script</a>'
            . '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">';

        $this->assertSame( [], $this->find( $html ) );
    }

    /**
     * A bare fragment is the page you are already on. Following it would queue
     * the same URL again under a name that never repeats.
     */
    public function testItIgnoresABareFragment() : void {
        $this->assertSame( [], $this->find( '<a href="#main">skip</a>' ) );
    }

    /**
     * The fragment and the query string are dropped.
     *
     * On disk `/page/?a=1` and `/page/?a=2` are one file. Keeping the query
     * would queue a page once per link pointing at it.
     */
    public function testItDropsTheQueryStringAndTheFragment() : void {
        $this->assertSame(
            [ '/page/' ],
            $this->find( '<a href="/page/?a=1">x</a><a href="/page/#b">y</a><a href="/page/?a=2">z</a>' )
        );
    }

    /**
     * Markup a browser would forgive is markup a theme produces.
     */
    public function testItReadsPagesThatAreNotWellFormed() : void {
        $html = '<p>unclosed <a href=/naked-attribute>x</a><br><div>';

        $this->assertSame( [ '/naked-attribute' ], $this->find( $html ) );
    }

    /**
     * An accented path survives.
     *
     * Without the charset declaration libxml assumes ISO-8859-1 and hands back
     * a mangled string — the same shape of defect as reading a post title
     * through wptexturize and getting entities.
     */
    public function testItKeepsAccentedCharacters() : void {
        $this->assertSame(
            [ '/citt%C3%A0/' ],
            $this->find( '<a href="/città/">x</a>' )
        );
    }

    public function testItIgnoresAMalformedReferenceRatherThanFailing() : void {
        $this->assertSame( [ '/good/' ], $this->find( '<a href="http://">bad</a><a href="/good/">ok</a>' ) );
    }

    public function testAnEmptyPageFindsNothing() : void {
        $this->assertSame( [], $this->find( '' ) );
        $this->assertSame( [], $this->find( '   ' ) );
    }

    /**
     * The same target found twice is one path.
     */
    public function testItReturnsEachPathOnce() : void {
        $this->assertSame(
            [ '/same/' ],
            $this->find( '<a href="/same/">a</a><a href="/same/">b</a><img src="/same/">' )
        );
    }
}
