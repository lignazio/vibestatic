<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class JobQueueRepositoryTest extends TestCase {

    public function setUp() : void {
        // Serve anche senza usare WP_Mock direttamente: Mockery::close() chiude
        // il contenitore globale, che e' lo stesso di WP_Mock. Senza aprirlo e
        // chiuderlo qui, questo test si trova a verificare le aspettative
        // lasciate indietro da un altro file.
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
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

    /**
     * @param int $id
     */
    private function job( $id ) : object {
        return (object) [
            'id' => $id,
            'status' => 'waiting',
        ];
    }

    public function testSquashKeepsTheMostRecentAndSkipsTheRest() : void {
        $wpdb = $this->db();

        // Tre 'crawl' in attesa, niente per gli altri tre tipi.
        $wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function ( $sql ) {
                if ( false === strpos( $sql, "'crawl'" ) ) {
                    return [];
                }

                return [ $this->job( 9 ), $this->job( 5 ), $this->job( 2 ) ];
            }
        );

        $updated = [];

        $wpdb->shouldReceive( 'update' )->andReturnUsing(
            function ( $table, $data, $where ) use ( &$updated ) {
                $updated[] = [ $where['id'], $data['status'] ];
                return 1;
            }
        );

        $squashed = ( new JobQueueRepository( $wpdb ) )->squashQueue();

        $this->assertSame( 2, $squashed );
        // Il 9 e' il piu' recente e sopravvive.
        $this->assertSame(
            [
                [ 5, 'skipped' ],
                [ 2, 'skipped' ],
            ],
            $updated
        );
    }

    public function testSquashLeavesASingleWaitingJobAlone() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->andReturn( [ $this->job( 1 ) ] );

        /*
         * E' il test che avrebbe colto il difetto: la guardia diceva
         * `if ( $waiting_jobs < 2 )` su un array, e in PHP un array confrontato
         * con un intero risulta sempre maggiore. Non ha mai fermato niente.
         */
        $wpdb->shouldNotReceive( 'update' );

        $this->assertSame( 0, ( new JobQueueRepository( $wpdb ) )->squashQueue() );
    }

    public function testSquashWithAnEmptyQueueDoesNothing() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->andReturn( [] );
        $wpdb->shouldNotReceive( 'update' );

        $this->assertSame( 0, ( new JobQueueRepository( $wpdb ) )->squashQueue() );
    }

    public function testSquashSurvivesANullResult() : void {
        $wpdb = $this->db();

        // get_results() torna null quando la query fallisce: prima ci si faceva
        // sopra count() e array_shift(), che in PHP 8 sono due TypeError.
        $wpdb->shouldReceive( 'get_results' )->andReturn( null );
        $wpdb->shouldNotReceive( 'update' );

        $this->assertSame( 0, ( new JobQueueRepository( $wpdb ) )->squashQueue() );
    }

    public function testSquashAsksOnceForEachJobTypeInsteadOfTwice() : void {
        $wpdb = $this->db();
        $queries = 0;

        $wpdb->shouldReceive( 'get_results' )->andReturnUsing(
            function () use ( &$queries ) {
                $queries++;
                return [];
            }
        );

        ( new JobQueueRepository( $wpdb ) )->squashQueue();

        // Prima la stessa SELECT veniva eseguita due volte per tipo: otto
        // interrogazioni al posto di quattro, e la seconda serviva solo perche'
        // la guardia in mezzo non funzionava.
        $this->assertSame( 4, $queries );
    }

    public function testMarkFailedJobsCommitsOnceAfterEveryType() : void {
        $wpdb = $this->db();
        $statements = [];

        $wpdb->shouldReceive( 'get_row' )->andReturn( (object) [ 'free' => 1 ] );
        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$statements ) {
                $statements[] = $sql;
                return 2;
            }
        );

        $marked = ( new JobQueueRepository( $wpdb ) )->markFailedJobs();

        $this->assertSame(
            [
                'detect' => 2,
                'crawl' => 2,
                'post_process' => 2,
                'deploy' => 2,
            ],
            $marked
        );

        // Una sola COMMIT, e in fondo. Prima stava dentro il ciclo: dopo il
        // primo tipo la transazione era gia' chiusa e le tre UPDATE successive
        // giravano fuori, con un ROLLBACK che non aveva piu' niente da
        // annullare.
        $this->assertSame( 'START TRANSACTION', $statements[0] );
        $this->assertSame( 'COMMIT', end( $statements ) );
        $this->assertSame( 1, count( array_keys( $statements, 'COMMIT', true ) ) );
    }

    public function testMarkFailedJobsSkipsTypesWhoseLockIsHeld() : void {
        $wpdb = $this->db();

        // Lock occupato = c'e' un processo vivo che ci sta lavorando: quei
        // lavori non sono falliti, sono in corso.
        $wpdb->shouldReceive( 'get_row' )->andReturn( (object) [ 'free' => 0 ] );
        $wpdb->shouldReceive( 'query' )->andReturn( 1 );

        $this->assertSame( [], ( new JobQueueRepository( $wpdb ) )->markFailedJobs() );
    }

    public function testMarkFailedJobsSurvivesANullLockRow() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_row' )->andReturn( null );
        $wpdb->shouldReceive( 'query' )->andReturn( 1 );

        // Prima si leggeva `->free` sul risultato senza controllarlo: un fatal
        // error dentro un processo di sfondo, quindi invisibile.
        $this->assertSame( [], ( new JobQueueRepository( $wpdb ) )->markFailedJobs() );
    }

    public function testJobCountByTypeReturnsIntegers() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [ [ 'crawl', '3' ], [ 'deploy', '1' ] ]
        );

        $this->assertSame(
            [
                'crawl' => 3,
                'deploy' => 1,
            ],
            ( new JobQueueRepository( $wpdb ) )->getJobCountByType()
        );
    }

    public function testJobsInProgressAsksForTheProcessingStatus() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'get_var' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return '1';
            }
        );

        $this->assertTrue( ( new JobQueueRepository( $wpdb ) )->jobsInProgress() );
        $this->assertSame(
            "SELECT COUNT(*) FROM `wp_wp2static_jobs` WHERE status = 'processing'",
            $captured
        );
    }

    public function testTheFacadeUsesTheInjectedRepository() : void {
        $repository = Mockery::mock( JobQueueRepository::class );
        $repository->shouldReceive( 'getWaitingJobsCount' )->once()->andReturn( 4 );

        JobQueue::setRepository( $repository );

        try {
            $this->assertSame( 4, JobQueue::getWaitingJobs() );
        } finally {
            JobQueue::setRepository( null );
        }
    }
}
