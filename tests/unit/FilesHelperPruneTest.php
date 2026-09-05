<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Pruning is the only thing in this plugin that deletes files from a published
 * site. Every test below describes a way of getting it wrong, and the worst way
 * is not keeping one file too many: it is deleting one that was needed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FilesHelperPruneTest extends TestCase {

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
     * Thirteen files, because the fraction safety catch is measured against how
     * many there are: with a small tree every legitimate deletion would on its
     * own be worth more than half the directory, and every test would end up
     * exercising the refusal instead of what it means to measure.
     */
    private function tree() : string {
        $vfs = vfsStream::setup( 'root' );

        $lavori = [];

        foreach ( range( 1, 8 ) as $n ) {
            $lavori[ "progetto-$n" ] = [ 'index.html' => "progetto $n" ];
        }

        vfsStream::create(
            [
                'index.html' => 'home',
                'chi-siamo' => [ 'index.html' => 'chi siamo' ],
                'lavori' => $lavori,
                'archivio' => [ '2019' => [ 'gennaio' => [ 'index.html' => 'vecchio' ] ] ],
                'file con spazi e accènti.txt' => 'ciao',
            ],
            $vfs
        );

        return $vfs->url();
    }

    /**
     * Every path in the tree, to build a "keep everything except these" list
     * without rewriting it by hand in every test.
     *
     * @param string[] $without Paths to drop from the list.
     * @return string[]
     */
    private function allPathsExcept( array $without ) : array {
        $all = [
            '/index.html',
            '/chi-siamo/index.html',
            '/archivio/2019/gennaio/index.html',
            '/file con spazi e accènti.txt',
        ];

        foreach ( range( 1, 8 ) as $n ) {
            $all[] = "/lavori/progetto-$n/index.html";
        }

        return array_values( array_diff( $all, $without ) );
    }

    public function testItRemovesOnlyWhatIsNotInTheList() : void {
        $dir = $this->tree();

        $removed = FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept( [ '/lavori/progetto-2/index.html' ] )
        );

        $this->assertSame( [ '/lavori/progetto-2/index.html' ], $removed );
        $this->assertFileDoesNotExist( $dir . '/lavori/progetto-2/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-1/index.html' );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testAnEmptyListRemovesNothing() : void {
        $dir = $this->tree();

        /*
         * The most important safety catch of them all. An empty list comes from
         * a queue never filled or a crawl never run — "I do not know" — and
         * reading it as "the site is empty" would delete everything. Anyone who
         * really wants to empty it has StaticSite::delete().
         */
        $this->assertSame( [], FilesHelper::removePathsNotIn( $dir, [] ) );
        $this->assertFileExists( $dir . '/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-2/index.html' );
    }

    public function testItRefusesAPruneThatWouldTakeMostOfTheDirectory() : void {
        $dir = $this->tree();

        /*
         * Twelve files out of thirteen. Detection already has a safety catch on
         * the queue, but the queue can also get shorter by routes that do not
         * pass through it — emptied by hand from the Caches page and then only
         * partly refilled. This is the function that actually deletes, and the
         * last point at which one can still stop.
         */
        $removed = FilesHelper::removePathsNotIn( $dir, [ '/index.html' ] );

        $this->assertSame( [], $removed );
        $this->assertFileExists( $dir . '/archivio/2019/gennaio/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-1/index.html' );
        $this->assertFileExists( $dir . '/chi-siamo/index.html' );
    }

    public function testItRemovesTheDirectoriesLeftEmpty() : void {
        $dir = $this->tree();

        FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept(
                [
                    '/chi-siamo/index.html',
                    '/lavori/progetto-1/index.html',
                    '/lavori/progetto-2/index.html',
                    '/lavori/progetto-3/index.html',
                ]
            )
        );

        // An empty directory left lying around becomes, on a server with
        // directory listings, an indexable empty page.
        $this->assertDirectoryDoesNotExist( $dir . '/chi-siamo' );
        $this->assertDirectoryDoesNotExist( $dir . '/lavori/progetto-1' );

        // But not the one that still has something in it.
        $this->assertDirectoryExists( $dir . '/lavori' );
        $this->assertFileExists( $dir . '/lavori/progetto-4/index.html' );
    }

    public function testItRemovesAParentThatEmptiedOnlyBecauseItsChildDid() : void {
        $dir = $this->tree();

        /*
         * A single file at the bottom of three nested directories. The deleted
         * file's only direct parent is `gennaio`: `2019` and `archivio` become
         * empty because what they held became empty, and are reached by walking
         * up.
         */
        FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept( [ '/archivio/2019/gennaio/index.html' ] )
        );

        $this->assertDirectoryDoesNotExist( $dir . '/archivio/2019/gennaio' );
        $this->assertDirectoryDoesNotExist( $dir . '/archivio/2019' );
        $this->assertDirectoryDoesNotExist( $dir . '/archivio' );

        // Not the root: that is the directory, not its contents.
        $this->assertDirectoryExists( $dir );
    }

    public function testItDoesNothingOnADirectoryThatIsNotThere() : void {
        $dir = $this->tree();

        $this->assertSame(
            [],
            FilesHelper::removePathsNotIn( $dir . '/mai-esistita', [ '/index.html' ] )
        );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testATrailingSlashOnTheRootDoesNotShiftEveryPath() : void {
        $dir = $this->tree();

        // The caller passes getPath()'s value, which a filter may have
        // returned with a trailing slash: without rtrim, every path read would
        // lose its first character and nothing would match any more.
        $removed = FilesHelper::removePathsNotIn( $dir . '/', $this->allPathsExcept( [] ) );

        $this->assertSame( [], $removed );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testPruningIsOnByDefaultAndCanBeTurnedOff() : void {
        WP_Mock::onFilter( 'wp2static_prune_stale_files' )->with( true )->reply( true );
        $this->assertTrue( FilesHelper::pruningEnabled() );

        WP_Mock::onFilter( 'wp2static_prune_stale_files' )->with( true )->reply( false );
        $this->assertFalse( FilesHelper::pruningEnabled() );
    }

    public function testASmallShrinkIsBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 18, 1813 ) );
    }

    public function testHalfIsStillBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        // The threshold is inclusive: exactly half is still a site that halved,
        // not necessarily a broken step.
        $this->assertTrue( FilesHelper::shrinkIsPlausible( 50, 100 ) );
    }

    public function testAWholesaleDisappearanceIsNotBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        /*
         * This is the shape a failure upstream takes: a sitemap that stops
         * responding, a post type not yet registered when the job starts. From
         * here it is indistinguishable from a site that really was emptied, so
         * no choice is made — and not choosing means not deleting.
         */
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }

    public function testNothingIsNeverPlausible() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        // No division by zero, and no decision taken about nothing.
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 0, 0 ) );
    }

    public function testTheThresholdCanBeRaisedByFilter() : void {
        // Anyone who knows what they are doing — a migration, a site that
        // really was emptied — can raise it to 1 and remove the catch entirely.
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 1.0 );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }

    public function testAFilterThatReturnsNonsenseFallsBackToTheDefault() : void {
        // apply_filters() returns whatever the code hooking it decides: a
        // string cast to float would give 0.0 — that is, "never remove
        // anything" — and would do so without saying.
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 'meta' );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 18, 1813 ) );
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }
}
