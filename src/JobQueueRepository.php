<?php
/**
 * Access to the wp_wp2static_jobs table, the job queue.
 *
 * @package WP2Static
 */

namespace WP2Static;

class JobQueueRepository {

    /**
     * I quattro tipi di lavoro, nell'ordine in cui si susseguono.
     */
    const JOB_TYPES = [ 'detect', 'crawl', 'post_process', 'deploy' ];

    /**
     * @var \wpdb
     */
    private $db;

    /**
     * @var string
     */
    private $table;

    /**
     * @param \wpdb $db Connessione WordPress.
     */
    public function __construct( \wpdb $db ) {
        $this->db = $db;
        $this->table = $db->prefix . 'wp2static_jobs';
    }

    /**
     * Create the jobs table.
     */
    public function createTable() : void {
        $charset_collate = $this->db->get_charset_collate();
        $table = $this->table;

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            job_type VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL,
            duration SMALLINT(6) UNSIGNED NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // There was an improper unique index which we must be sure to remove
        $has_old_index = $this->db->query(
            (string) $this->db->prepare(
                'SHOW INDEX FROM %i WHERE KEY_NAME = %s',
                $this->table,
                'status'
            )
        );

        if ( 1 === $has_old_index ) {
            $this->db->query(
                (string) $this->db->prepare( 'DROP INDEX %i ON %i', 'status', $this->table )
            );
        }

        Controller::ensureIndex( $this->table, 'status2', [ 'status' ] );
    }

    /**
     * Add Job to queue
     *
     * @param string $job_type Type of job, ie detect, crawl, post_process, deploy.
     */
    public function addJob( string $job_type ) : void {
        // TODO: squash any of same job_types with 'waiting' status
        // setting this one to be the one that runs next
        $this->db->query(
            (string) $this->db->prepare(
                'INSERT INTO %i (job_type, status) VALUES (%s, %s)',
                $this->table,
                $job_type,
                'waiting'
            )
        );
    }

    /**
     *  Get all jobs, most recent first
     *
     *  @return object[] All jobs
     */
    public function getJobs() : array {
        return $this->db->get_results(
            $this->db->prepare( 'SELECT * FROM %i ORDER BY id DESC', $this->table )
        ) ?? [];
    }

    /**
     *  Check for any jobs in progress
     */
    public function jobsInProgress() : bool {
        return $this->countByStatus( 'processing' ) > 0;
    }

    /**
     *  Get all waiting jobs, oldest first
     *
     *  @return object[] All waiting jobs
     */
    public function getProcessableJobs() : array {
        return $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC',
                $this->table,
                'waiting'
            )
        ) ?? [];
    }

    /**
     * Get count of jobs organized by type
     *
     * @return int[] keys are job type and values are count
     */
    public function getJobCountByType() : array {
        $jobs = [];

        /** @var list<array{0: string, 1: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT job_type, count(*) FROM %i GROUP BY job_type',
                $this->table
            ),
            'ARRAY_N'
        ) ?? [];

        foreach ( $rows as $row ) {
            $jobs[ $row[0] ] = (int) $row[1];
        }

        return $jobs;
    }

    /**
     * Skip processing jobs where a more recent job of same type exists.
     *
     * @return int How many jobs were marked as skipped.
     */
    public function squashQueue() : int {
        $squashed = 0;

        foreach ( self::JOB_TYPES as $job_type ) {
            $waiting_jobs = $this->getWaitingJobsOfType( $job_type );

            /*
             * `count()`, not the direct comparison. The line used to read
             * `if ( $waiting_jobs < 2 )` with $waiting_jobs being an array: in
             * PHP an array compared against an integer always comes out
             * greater, so the guard was false in every case — even with zero
             * waiting jobs — and never stopped anything. Right below it the
             * same query was run again, identically, which is the clue to what
             * was meant: the first one to count, the second one to work.
             */
            if ( count( $waiting_jobs ) < 2 ) {
                continue;
            }

            // The most recent one survives, the rest are skipped.
            array_shift( $waiting_jobs );

            foreach ( $waiting_jobs as $waiting_job ) {
                $this->db->update(
                    $this->table,
                    [ 'status' => 'skipped' ],
                    [ 'id' => $waiting_job->id ]
                );

                $squashed++;
            }
        }

        return $squashed;
    }

    /**
     * @param string $job_type Job type.
     * @return list<object{id: int}> Waiting jobs of that type, most recent first.
     */
    private function getWaitingJobsOfType( string $job_type ) : array {
        /** @var list<object{id: int}> */
        return $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE job_type = %s AND status = %s ORDER BY created_at DESC',
                $this->table,
                $job_type,
                'waiting'
            )
        ) ?? [];
    }

    /**
     * @param int    $id     Id del lavoro.
     * @param string $status Nuovo stato.
     */
    public function setStatus( int $id, string $status ) : void {
        $this->db->update(
            $this->table,
            [ 'status' => $status ],
            [ 'id' => $id ]
        );
    }

    /**
     *  Get total count of jobs
     */
    public function getTotalJobs() : int {
        return (int) $this->db->get_var(
            $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table )
        );
    }

    /**
     *  Get count of waiting jobs
     */
    public function getWaitingJobsCount() : int {
        return $this->countByStatus( 'waiting' );
    }

    /**
     * @param string $status Stato da contare.
     */
    private function countByStatus( string $status ) : int {
        return (int) $this->db->get_var(
            $this->db->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s',
                $this->table,
                $status
            )
        );
    }

    /**
     * Empty the job queue.
     */
    public function truncate() : void {
        $this->db->query( (string) $this->db->prepare( 'TRUNCATE TABLE %i', $this->table ) );
    }

    /**
     *  Detect any 'processing' jobs that are not running and change status to 'failed'.
     *
     *  A job marked "processing" with nobody processing it is a half-dead
     *  process: you recognise it by the MySQL lock, which a live process would
     *  be holding.
     *
     *  @return array<string, int> How many were marked, by type.
     *  @throws \Throwable If one of the UPDATEs fails.
     */
    public function markFailedJobs() : array {
        $marked = [];

        $this->db->query( 'START TRANSACTION' );

        try {
            foreach ( self::JOB_TYPES as $type ) {
                $lock = "{$this->db->prefix}.wp2static_jobs.$type";

                /** @var object{free: int|string|null}|null $lock_row */
                $lock_row = $this->db->get_row(
                    $this->db->prepare( 'SELECT IS_FREE_LOCK(%s) AS free', $lock )
                );

                // get_row() can return null — dropped connection, rejected
                // query. This used to read `->free` straight off the result,
                // which in that case is a fatal error inside a background
                // process, i.e. an invisible one.
                if ( ! $lock_row || ! intval( $lock_row->free ) ) {
                    continue;
                }

                $failed_jobs = (int) $this->db->query(
                    (string) $this->db->prepare(
                        'UPDATE %i SET status = %s WHERE job_type = %s AND status = %s',
                        $this->table,
                        'failed',
                        $type,
                        'processing'
                    )
                );

                if ( $failed_jobs ) {
                    $marked[ $type ] = $failed_jobs;
                }
            }

            /*
             * The COMMIT used to sit INSIDE the loop: after the first job type
             * the transaction was already closed, and the three following
             * UPDATEs ran outside it — with a ROLLBACK, on error, that had
             * nothing left to undo. This is where the code meant to put it.
             */
            $this->db->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );

            throw $e;
        }

        return $marked;
    }
}
