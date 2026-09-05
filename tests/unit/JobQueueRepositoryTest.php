<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class JobQueueRepositoryTest extends TestCase {

    public function setUp() : void {
        // Needed even without using WP_Mock directly: Mockery::close() closes
        // the global container, which is the same one WP_Mock uses. Without
        // opening and closing it here, this test ends up checking expectations
        // another file left behind.
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

        // Three 'crawl' jobs waiting, nothing for the other three types.
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
        // 9 is the most recent and survives.
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
         * This is the test that would have caught the defect: the guard read
         * `if ( $waiting_jobs < 2 )` on an array, and in PHP an array compared
         * against an integer always comes out greater. It never stopped
         * anything.
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

        // get_results() returns null when the query fails: count() and
        // array_shift() used to be called on it, which in PHP 8 are two
        // TypeErrors.
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

        // The same SELECT used to run twice per type: eight queries instead of
        // four, and the second was only needed because the guard between them
        // did not work.
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

        // One COMMIT, at the end. It used to sit inside the loop: after the
        // first type the transaction was already closed and the three following
        // UPDATEs ran outside it, with a ROLLBACK that had nothing left to
        // undo.
        $this->assertSame( 'START TRANSACTION', $statements[0] );
        $this->assertSame( 'COMMIT', end( $statements ) );
        $this->assertSame( 1, count( array_keys( $statements, 'COMMIT', true ) ) );
    }

    public function testMarkFailedJobsSkipsTypesWhoseLockIsHeld() : void {
        $wpdb = $this->db();

        // Lock held = there is a live process working on it: those jobs have
        // not failed, they are running.
        $wpdb->shouldReceive( 'get_row' )->andReturn( (object) [ 'free' => 0 ] );
        $wpdb->shouldReceive( 'query' )->andReturn( 1 );

        $this->assertSame( [], ( new JobQueueRepository( $wpdb ) )->markFailedJobs() );
    }

    public function testMarkFailedJobsSurvivesANullLockRow() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_row' )->andReturn( null );
        $wpdb->shouldReceive( 'query' )->andReturn( 1 );

        // `->free` used to be read off the result without checking it: a fatal
        // error inside a background process, and so invisible.
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
