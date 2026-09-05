<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The crawl queue is the definition of "what the site is": what is not in it is
 * not crawled, and what is not crawled ends up unpublished. Removing one row
 * too many is this code's only way of making a live page disappear, so the two
 * functions that decide are examined on their own, with no database around
 * them.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class URLDetectorPruneTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testItNamesOnlyTheQueuedUrlsDetectionNoLongerClaims() : void {
        $stale = URLDetector::staleQueueEntries(
            [
                11 => '/',
                12 => '/chi-siamo/',
                13 => '/wp-content/plugins/vibestatic',
            ],
            [ '/', '/chi-siamo/' ]
        );

        // The key is the row id, because that is what deletion uses.
        $this->assertSame( [ 13 => '/wp-content/plugins/vibestatic' ], $stale );
    }

    public function testAnEncodedDetectedUrlMatchesItsDecodedQueueRow() : void {
        /*
         * CrawlQueueRepository::addUrls() stores `rawurldecode( $url )` in the
         * `url` column, while the file detectors produce encoded URLs. Comparing
         * the two forms as they are would read every file with a space or an
         * accent in its name as "gone" — that is, an entire non-ASCII media
         * library, on every detection run.
         */
        $stale = URLDetector::staleQueueEntries(
            [ 21 => '/wp-content/uploads/2026/foto d\'estate.jpg' ],
            [ '/wp-content/uploads/2026/foto%20d%27estate.jpg' ]
        );

        $this->assertSame( [], $stale );
    }

    public function testNothingIsStaleWhenDetectionRepeatsItself() : void {
        $queued = [ 1 => '/', 2 => '/robots.txt', 3 => '/favicon.ico' ];

        $this->assertSame(
            [],
            URLDetector::staleQueueEntries( $queued, array_values( $queued ) )
        );
    }
}
