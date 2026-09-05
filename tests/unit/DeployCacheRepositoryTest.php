<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * The Deploy Cache answers one question — "is this file already the one at the
 * destination?" — and that is the question the incremental deploy rests on. It
 * used to be untestable because the path to the post-processed directory came
 * from a static call inside the method; it now arrives through the constructor,
 * and here it is a virtual filesystem.
 */
final class DeployCacheRepositoryTest extends TestCase {

    /**
     * @var vfsStreamDirectory
     */
    private $fs;

    /**
     * @var string
     */
    private $processed_path;

    public function setUp() : void {
        $this->fs = vfsStream::setup( 'processed' );
        $this->processed_path = vfsStream::url( 'processed' ) . '/';
    }

    public function tearDown() : void {
        Mockery::close();
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function db() {
        $wpdb = Mockery::mock( '\WPDB' );
        $wpdb->prefix = 'wp_';
        wp2static_test_mock_prepare( $wpdb );

        return $wpdb;
    }

    private function repo( \Mockery\MockInterface $wpdb ) : DeployCacheRepository {
        return new DeployCacheRepository( $wpdb, $this->processed_path );
    }

    public function testFileHashIsTheMd5OfTheContents() : void {
        vfsStream::newFile( 'index.html' )->at( $this->fs )->setContent( '<h1>ciao</h1>' );

        $this->assertSame(
            md5( '<h1>ciao</h1>' ),
            $this->repo( $this->db() )->fileHash( 'index.html' )
        );
    }

    public function testFileHashIsNullWhenTheFileIsMissing() : void {
        $this->assertNull( $this->repo( $this->db() )->fileHash( 'mai-esistito.html' ) );
    }

    public function testPathHashUsesTheAbsolutePath() : void {
        // Not a detail: add-ons write to the same table, and switching to the
        // md5 of the relative path would silently invalidate every existing
        // cache — that is, a full deploy on the first update.
        $this->assertSame(
            md5( $this->processed_path . 'about/index.html' ),
            $this->repo( $this->db() )->pathHash( 'about/index.html' )
        );
    }

    public function testFileIsCachedWhenPathAndContentHashBothMatch() : void {
        vfsStream::create( [ 'about' => [ 'index.html' => 'invariato' ] ], $this->fs );

        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'get_var' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 'un-path-hash';
                }
            );

        $cached = $this->repo( $wpdb )->isFileCached( 'about/index.html' );

        $this->assertTrue( $cached );
        $this->assertStringContainsString(
            "path_hash = '" . md5( $this->processed_path . 'about/index.html' ) . "'",
            $captured
        );
        $this->assertStringContainsString( "file_hash = '" . md5( 'invariato' ) . "'", $captured );
        $this->assertStringContainsString( "namespace = 'default'", $captured );
    }

    public function testFileIsNotCachedWhenTheContentChanged() : void {
        vfsStream::newFile( 'index.html' )->at( $this->fs )->setContent( 'nuovo contenuto' );

        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

        $this->assertFalse( $this->repo( $wpdb )->isFileCached( 'index.html' ) );
    }

    public function testMissingFileIsNeverCachedAndCostsNoQuery() : void {
        $wpdb = $this->db();

        // If the file is not there, there is nothing to compare: querying the
        // database would be a question with no possible answer.
        $wpdb->shouldNotReceive( 'get_var' );

        $this->assertFalse( $this->repo( $wpdb )->isFileCached( 'mai-esistito.html' ) );
    }

    public function testAddFileStoresPathAndHashes() : void {
        vfsStream::newFile( 'feed.xml' )->at( $this->fs )->setContent( '<rss/>' );

        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 1;
                }
            );

        $this->repo( $wpdb )->addFile( 'feed.xml', 'netlify' );

        $this->assertStringContainsString( '`wp_wp2static_deploy_cache`', $captured );
        $this->assertStringContainsString( "'" . md5( '<rss/>' ) . "'", $captured );
        $this->assertStringContainsString( "'feed.xml'", $captured );
        $this->assertStringContainsString( "'netlify'", $captured );
    }

    public function testAddFileWritesNothingWhenTheFileIsMissing() : void {
        $wpdb = $this->db();
        $wpdb->shouldNotReceive( 'query' );

        $this->repo( $wpdb )->addFile( 'mai-esistito.html' );

        $this->assertTrue( true );
    }

    public function testAnExplicitHashSkipsReadingTheFile() : void {
        // A caller that already has the content in memory should not re-read
        // it from disk: that is the deployer, which just loaded it to send.
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 1;
                }
            );

        $this->repo( $wpdb )->addFile( 'file-che-non-c-e.html', 'default', 'hash-passato-a-mano' );

        $this->assertStringContainsString( "'hash-passato-a-mano'", $captured );
    }

    public function testTruncateIsScopedToOneNamespace() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturnUsing(
                function ( $sql ) use ( &$captured ) {
                    $captured = $sql;
                    return 1;
                }
            );

        $this->repo( $wpdb )->truncate( 's3' );

        // A TRUNCATE TABLE here would throw away the caches of every other
        // deployer configured on the same site as well.
        $this->assertSame(
            "DELETE FROM `wp_wp2static_deploy_cache` WHERE namespace = 's3'",
            $captured
        );
    }

    public function testThePlanSeparatesNewChangedUnchangedAndGone() : void {
        vfsStream::create(
            [
                'nuovo.html' => 'mai visto',
                'cambiato.html' => 'contenuto nuovo',
                'invariato.html' => 'sempre uguale',
            ],
            $this->fs
        );

        $wpdb = $this->db();

        // What already counts as published: one unchanged, one with a stale
        // hash, and one that is no longer in the processed site.
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'path' => '/invariato.html',
                    'file_hash' => md5( 'sempre uguale' ),
                ],
                (object) [
                    'path' => '/cambiato.html',
                    'file_hash' => md5( 'contenuto vecchio' ),
                ],
                (object) [
                    'path' => '/sparito.html',
                    'file_hash' => md5( 'qualcosa' ),
                ],
            ]
        );

        $plan = $this->repo( $wpdb )->plan(
            [ '/nuovo.html', '/cambiato.html', '/invariato.html' ]
        );

        $to_deploy = $plan->toDeploy();
        sort( $to_deploy );

        $this->assertSame( [ '/cambiato.html', '/nuovo.html' ], $to_deploy );
        $this->assertSame( [ '/sparito.html' ], $plan->toDelete() );
        $this->assertSame( 1, $plan->unchanged() );
        $this->assertFalse( $plan->isEmpty() );
        $this->assertSame(
            'Deploy plan: 2 to upload, 1 to remove, 1 unchanged.',
            $plan->summary()
        );
    }

    public function testThePlanCostsOneQueryNoMatterHowManyFiles() : void {
        $files = [];
        $paths = [];

        for ( $i = 0; $i < 50; $i++ ) {
            $files[ "pagina-$i.html" ] = "contenuto $i";
            $paths[] = "/pagina-$i.html";
        }

        vfsStream::create( $files, $this->fs );

        $wpdb = $this->db();
        $queries = 0;

        $wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$queries ) {
                $queries++;
                return [];
            }
        );
        // If the plan asked the database one file at a time, `isFileCached()`
        // would do fifty get_vars. On a real site that is eighteen hundred
        // queries — the quickest way to make the incremental deploy slower than
        // a full one.
        $wpdb->shouldNotReceive( 'get_var' );

        $plan = $this->repo( $wpdb )->plan( $paths );

        $this->assertSame( 1, $queries );
        $this->assertCount( 50, $plan->toDeploy() );
    }

    public function testNothingToDoIsAnEmptyPlan() : void {
        vfsStream::create( [ 'index.html' => 'uguale' ], $this->fs );

        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'path' => '/index.html',
                    'file_hash' => md5( 'uguale' ),
                ],
            ]
        );

        $plan = $this->repo( $wpdb )->plan( [ '/index.html' ] );

        $this->assertTrue( $plan->isEmpty() );
        $this->assertSame(
            'Deploy plan: 0 to upload, 0 to remove, 1 unchanged.',
            $plan->summary()
        );
    }

    public function testAnUnreadableFileIsNeitherDeployedNorCountedAsUnchanged() : void {
        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        // Calling it "unchanged" would be a lie, and putting it among the
        // uploads would fail the deploy on a file that cannot be read.
        $plan = $this->repo( $wpdb )->plan( [ '/mai-esistito.html' ] );

        $this->assertSame( [], $plan->toDeploy() );
        $this->assertSame( 0, $plan->unchanged() );
    }

    public function testRmPathsForgetsByPathHashAndNamespace() : void {
        $wpdb = $this->db();
        $deleted = [];

        $wpdb->shouldReceive( 'delete' )->andReturnUsing(
            function ( $table, $where ) use ( &$deleted ) {
                $deleted[] = $where;
                return 1;
            }
        );

        $this->repo( $wpdb )->rmPaths( [ '/sparito.html' ], 's3' );

        $this->assertSame(
            [
                [
                    'path_hash' => md5( $this->processed_path . '/sparito.html' ),
                    'namespace' => 's3',
                ],
            ],
            $deleted
        );
    }

    public function testTruncateAllClearsEveryNamespace() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return 1;
            }
        );

        $this->repo( $wpdb )->truncateAll();

        // No WHERE: the only way a button that says "delete the Deploy Cache"
        // actually deletes the Deploy Cache. It used to call truncate with the
        // default namespace, leaving every other deployer's cache standing.
        $this->assertSame( 'TRUNCATE TABLE `wp_wp2static_deploy_cache`', $captured );
    }

    public function testTheFacadeUsesTheInjectedRepository() : void {
        $repository = Mockery::mock( DeployCacheRepository::class );
        $repository->shouldReceive( 'isFileCached' )
            ->once()
            ->with( '/index.html', 'default', null )
            ->andReturn( true );

        DeployCache::setRepository( $repository );

        try {
            $this->assertTrue( DeployCache::fileisCached( '/index.html' ) );
        } finally {
            DeployCache::setRepository( null );
        }
    }

    public function testGetTotalsIsKeyedByNamespace() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'namespace' => 'default',
                    'count' => '1822',
                ],
                (object) [
                    'namespace' => 's3',
                    'count' => '4',
                ],
            ]
        );

        $this->assertSame(
            [
                'default' => '1822',
                's3' => '4',
            ],
            $this->repo( $wpdb )->getTotals()
        );
    }
}
