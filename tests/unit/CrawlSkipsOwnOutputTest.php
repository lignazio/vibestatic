<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The crawl walks the uploads directory, and the plugin's own output is in it.
 *
 * Left in, the previous run's copy of the site is published inside this run's,
 * and the run after that publishes both — the kind of defect that is invisible
 * on a test site of six pages and doubles a real one until the disk fills. It
 * used to be kept out by two entries in the user-editable "Directory and File
 * Names to Ignore" list, so the test passes an empty list: the point is that
 * the exclusion holds without it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CrawlSkipsOwnOutputTest extends TestCase {

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

    public function testFilesUnderThePluginsOwnDirectoryAreNotCrawled() : void {
        $vfs = vfsStream::setup( 'uploads' );

        vfsStream::create(
            [
                '2026' => [ '09' => [ 'foto.jpg' => 'jpeg' ] ],
                'vibestatic' => [
                    'crawled-site' => [ 'index.html' => 'last run' ],
                    'processed-site' => [ 'index.html' => 'last run, rewritten' ],
                    'processed-site.zip' => 'PK',
                    '.htaccess' => 'deny',
                ],
                // Not ours, however much the first nine letters suggest it is.
                'vibestatic-exports' => [ 'note.txt' => 'a user directory' ],
            ],
            $vfs
        );

        $uploads = $vfs->url() . '/';

        Mockery::mock( 'overload:\WP2Static\SiteInfo' )
            ->shouldReceive( 'getPath' )->andReturn( $uploads );

        $found = FilesHelper::getListOfLocalFilesByDir( $uploads, [], [] );

        sort( $found );

        $this->assertSame(
            [
                '/2026/09/foto.jpg',
                '/vibestatic-exports/note.txt',
            ],
            $found
        );
    }
}
