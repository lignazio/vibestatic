<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

/**
 * La Deploy Cache risponde a una domanda sola — «questo file e' gia' quello
 * che sta a destinazione?» — ed e' la domanda su cui poggia il deploy
 * incrementale. Prima non era verificabile perche' il percorso della cartella
 * post-processata arrivava da una chiamata statica dentro il metodo; ora entra
 * dal costruttore, e qui e' un filesystem virtuale.
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
        // Non e' un dettaglio: la stessa tabella la scrivono gli addon, e
        // passare all'md5 del percorso relativo invaliderebbe in silenzio ogni
        // cache esistente — cioe' un deploy completo al primo aggiornamento.
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

        // Se il file non c'e' non c'e' niente da confrontare: interrogare il
        // database sarebbe una domanda senza risposta possibile.
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
        // Chi ha gia' il contenuto in memoria non deve rileggerlo dal disco:
        // e' il caso del deployer, che lo ha appena caricato per spedirlo.
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

        // Un TRUNCATE TABLE qui butterebbe via anche le cache degli altri
        // deployer configurati sullo stesso sito.
        $this->assertSame(
            "DELETE FROM `wp_wp2static_deploy_cache` WHERE namespace = 's3'",
            $captured
        );
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
