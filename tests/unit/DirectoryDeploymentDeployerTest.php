<?php

namespace WP2StaticDirectoryDeployer;

use Mockery;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use WP2Static\DeployPlan;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The deployer this project publishes with, and until now the only adopted
 * add-on without a test.
 *
 * What is worth pinning down here is not that a copy copies. It is the three
 * things the incremental deploy rests on: that only what the plan lists is
 * touched, that what has gone is removed along with the directories it leaves
 * empty, and that the cache is written only after the destination has actually
 * changed — because a cache that runs ahead of the filesystem turns an
 * interrupted deploy into a permanently half-published site, silently.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DirectoryDeploymentDeployerTest extends TestCase {

    /**
     * @var vfsStreamDirectory
     */
    private $fs;

    /**
     * @var string
     */
    private $processed;

    /**
     * @var string
     */
    private $target;

    public function setUp() : void {
        WP_Mock::setUp();

        $this->fs = vfsStream::setup( 'deploy' );
        $this->processed = vfsStream::url( 'deploy/processed' );
        $this->target = vfsStream::url( 'deploy/target' );
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * Stands in for everything the deployer reads from outside itself: the
     * three options, the plan, and the log.
     *
     * It sets no expectation on addFile() or rmPaths(): each test declares
     * those itself. A permissive expectation here and a specific one in the
     * test would be two expectations matching the same call, and which of them
     * Mockery picks is not something a test should depend on.
     *
     * @param array<string, string> $options Option name => value.
     * @param DeployPlan|null       $plan    The plan, or null to expect no
     *                                       plan is ever asked for.
     * @return Mockery\MockInterface The DeployCache alias, to assert on.
     */
    private function mockEnvironment( array $options, ?DeployPlan $plan = null ) {
        $options += [
            'directoryDeploymentTargetDirectory' => $this->target,
            'directoryDeploymentDeleteBeforeDeployment' => '0',
            'directoryDeploymentAdditionalSourceDirectory' => '',
        ];

        /*
         * The Deployer reads through Controller::instance()->options() now that
         * the module sits on WP2Static\Addon\Controller, so the mock is two
         * objects rather than a bag of static getValue() calls: an Options that
         * answers get()/bool(), and a Controller that hands it over.
         *
         * bool() rather than get() for the delete flag, because that is what
         * the Deployer asks — and the previous shape, `0 !== intval( ... )`,
         * is the one the Options type system replaced.
         */
        $addon_options = Mockery::mock( 'WP2Static\Addon\Options' );

        foreach ( $options as $name => $value ) {
            $addon_options->shouldReceive( 'get' )
                ->with( $name )
                ->andReturn( (string) $value );

            $addon_options->shouldReceive( 'bool' )
                ->with( $name )
                ->andReturn( '' !== (string) $value && '0' !== (string) $value );
        }

        $controller = Mockery::mock( 'alias:WP2StaticDirectoryDeployer\Controller' );
        $controller->shouldReceive( 'instance' )->andReturn( $controller );
        $controller->shouldReceive( 'options' )->andReturn( $addon_options );

        Mockery::mock( 'alias:WP2Static\WsLog' )
            ->shouldReceive( 'l' )
            ->andReturnNull();

        $cache = Mockery::mock( 'alias:WP2Static\DeployCache' );

        if ( null === $plan ) {
            $cache->shouldNotReceive( 'plan' );

            return $cache;
        }

        $cache->shouldReceive( 'plan' )
            ->with( Deployer::DEFAULT_NAMESPACE )
            ->andReturn( $plan );

        return $cache;
    }

    /**
     * Only what the plan lists gets copied.
     *
     * The unchanged file is the assertion that matters: it exists in the
     * processed site and is absent from the plan, and the whole point of the
     * feature is that it is not written again. A deployer that walked the
     * directory instead of the plan would pass every other check in this file.
     */
    public function testItCopiesOnlyThePlannedFiles() : void {
        vfsStream::create(
            [
                'processed' => [
                    'index.html' => 'home',
                    'about' => [ 'index.html' => 'about' ],
                    'unchanged.html' => 'old but still current',
                ],
                'target' => [],
            ],
            $this->fs
        );

        $plan = new DeployPlan(
            [ '/index.html', '/about/index.html' ],
            [],
            1
        );

        $cache = $this->mockEnvironment( [], $plan );
        $cache->shouldReceive( 'addFile' )
            ->once()
            ->with( '/index.html', Deployer::DEFAULT_NAMESPACE );
        $cache->shouldReceive( 'addFile' )
            ->once()
            ->with( '/about/index.html', Deployer::DEFAULT_NAMESPACE );
        $cache->shouldReceive( 'rmPaths' )->andReturnNull();

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertSame( 'home', file_get_contents( $this->target . '/index.html' ) );
        $this->assertSame(
            'about',
            file_get_contents( $this->target . '/about/index.html' )
        );
        $this->assertFileDoesNotExist( $this->target . '/unchanged.html' );
    }

    /**
     * What has gone is removed, and so is every directory it leaves empty.
     *
     * The nesting is the point. Only the directory that directly held the file
     * used to be considered, so unpublishing the single post under
     * `/2019/08/post/` removed that one directory and left `/2019/08` and
     * `/2019` behind — empty directories that stay online, and that a server
     * with directory listings turns into indexable empty pages.
     */
    public function testItRemovesWhatHasGoneAndTheDirectoriesLeftEmpty() : void {
        vfsStream::create(
            [
                'processed' => [ 'keep.html' => 'still here' ],
                'target' => [
                    'keep.html' => 'still here',
                    '2019' => [ '08' => [ 'post' => [ 'index.html' => 'gone' ] ] ],
                ],
            ],
            $this->fs
        );

        $plan = new DeployPlan( [], [ '/2019/08/post/index.html' ], 1 );

        $cache = $this->mockEnvironment( [], $plan );
        $cache->shouldReceive( 'rmPaths' )->andReturnNull();

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertFileDoesNotExist( $this->target . '/2019/08/post/index.html' );
        $this->assertDirectoryDoesNotExist( $this->target . '/2019/08/post' );
        $this->assertDirectoryDoesNotExist( $this->target . '/2019/08' );
        $this->assertDirectoryDoesNotExist( $this->target . '/2019' );

        // The walk up stops at the destination root, and leaves its other
        // contents alone.
        $this->assertDirectoryExists( $this->target );
        $this->assertFileExists( $this->target . '/keep.html' );
    }

    /**
     * A directory that still holds something is not removed.
     */
    public function testItKeepsDirectoriesThatAreNotEmpty() : void {
        vfsStream::create(
            [
                'processed' => [],
                'target' => [
                    'blog' => [
                        'gone.html' => 'gone',
                        'sibling.html' => 'still here',
                    ],
                ],
            ],
            $this->fs
        );

        $plan = new DeployPlan( [], [ '/blog/gone.html' ], 0 );

        $cache = $this->mockEnvironment( [], $plan );
        $cache->shouldReceive( 'rmPaths' )->andReturnNull();

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertFileDoesNotExist( $this->target . '/blog/gone.html' );
        $this->assertDirectoryExists( $this->target . '/blog' );
        $this->assertFileExists( $this->target . '/blog/sibling.html' );
    }

    /**
     * The cache is told about the removals only once they have happened.
     *
     * The order is the whole safety of an interrupted deploy: a file dropped
     * from the cache but still at the destination is a file that no later
     * deploy will ever remove, because from the cache's point of view it was
     * dealt with.
     */
    public function testItClearsTheCacheOnlyAfterTheFilesAreGone() : void {
        vfsStream::create(
            [
                'processed' => [],
                'target' => [ 'gone.html' => 'gone' ],
            ],
            $this->fs
        );

        $file = $this->target . '/gone.html';
        $existed_when_cache_was_cleared = null;

        $plan = new DeployPlan( [], [ '/gone.html' ], 0 );

        $cache = $this->mockEnvironment( [], $plan );
        $cache->shouldReceive( 'rmPaths' )
            ->once()
            ->andReturnUsing(
                function () use ( $file, &$existed_when_cache_was_cleared ) {
                    $existed_when_cache_was_cleared = file_exists( $file );
                }
            );

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertFalse(
            $existed_when_cache_was_cleared,
            'rmPaths() ran while the file was still at the destination.'
        );
    }

    /**
     * With no target configured it does nothing at all — it does not even ask
     * for a plan, which is the observable difference between "nothing to do"
     * and "not configured".
     */
    public function testItDoesNothingWithoutATargetDirectory() : void {
        vfsStream::create( [ 'processed' => [ 'index.html' => 'home' ] ], $this->fs );

        $this->mockEnvironment( [ 'directoryDeploymentTargetDirectory' => '' ] );

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertDirectoryDoesNotExist( $this->target );
    }

    /**
     * Same when the target is configured but is not there: a typo in the path
     * must not become an empty published site.
     */
    public function testItDoesNothingWhenTheTargetDoesNotExist() : void {
        vfsStream::create( [ 'processed' => [ 'index.html' => 'home' ] ], $this->fs );

        $this->mockEnvironment(
            [ 'directoryDeploymentTargetDirectory' => vfsStream::url( 'deploy/typo' ) ]
        );

        ( new Deployer() )->uploadFiles( $this->processed );

        $this->assertDirectoryDoesNotExist( vfsStream::url( 'deploy/typo' ) );
    }

    /**
     * And when the processed site is missing, which is what a deploy fired
     * before anything was generated looks like.
     */
    public function testItDoesNothingWithoutAProcessedSite() : void {
        vfsStream::create( [ 'target' => [ 'index.html' => 'published' ] ], $this->fs );

        $this->mockEnvironment( [] );

        ( new Deployer() )->uploadFiles( vfsStream::url( 'deploy/never-generated' ) );

        // The destination is left exactly as it was: nothing added, and — the
        // half that would hurt — nothing removed.
        $this->assertSame(
            'published',
            file_get_contents( $this->target . '/index.html' )
        );
    }
}
