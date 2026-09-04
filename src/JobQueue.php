<?php

namespace WP2Static;

class JobQueue {

    public static function createTable() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
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
        $has_old_index = $wpdb->query(
            $wpdb->prepare( 'SHOW INDEX FROM %i WHERE KEY_NAME = %s', $table_name, 'status' )
        );
        if ( 1 === $has_old_index ) {
            $wpdb->query( $wpdb->prepare( 'DROP INDEX %i ON %i', 'status', $table_name ) );
        }

        Controller::ensureIndex( $table_name, 'status2', [ 'status' ] );
    }

    /**
     * Add Job to queue
     *
     * @param string $job_type Type of job
     * ie detect, crawl, post_process, deploy
     */
    public static function addJob( string $job_type ) : void {
        WsLog::l( 'Adding job: ' . $job_type );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        // TODO: squash any of same job_types with 'waiting' status
        // setting this one to be the one that runs next

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (job_type, status) VALUES (%s, %s)',
                $table_name,
                $job_type,
                'waiting'
            )
        );
    }

    /**
     *  Get all jobs
     *
     *  @return string[] All jobs
     */
    public static function getJobs() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $urls = [];

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', $table_name )
        );

        foreach ( $rows as $row ) {
            $urls[] = $row;
        }

        return $urls;
    }

    /**
     *  Check for any jobs in progress
     *
     *  @return bool All waiting jobs
     */
    public static function jobsInProgress() : bool {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $jobs = [];

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $jobs_in_progress = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s',
                $table_name,
                'processing'
            )
        );

        return $jobs_in_progress > 0;
    }

    /**
     *  Get all waiting jobs
     *
     *  @return mixed[] All waiting jobs
     */
    public static function getProcessableJobs() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $jobs = [];

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC',
                $table_name,
                'waiting'
            )
        );

        foreach ( $rows as $row ) {
            $jobs[] = $row;
        }

        return $jobs;
    }

    /**
     * Get count of jobs organized by type
     *
     * @return int[] keys are job type and values are count
     */
    public static function getJobCountByType() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $jobs = [];

        $table_name = $wpdb->prefix . 'wp2static_jobs';
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT job_type, count(*) FROM %i GROUP BY job_type', $table_name ),
            'ARRAY_N'
        );
        foreach ( $rows as $row ) {
            $jobs[ $row[0] ] = $row[1];
        }

        return $jobs;
    }

    /*
        Skip processing jobs where a more recent job of same type exists

    */
    public static function squashQueue() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        // TODO: loop for each job_type
        $job_types = [
            'detect',
            'crawl',
            'post_process',
            'deploy',
        ];

        foreach ( $job_types as $job_type ) {
            // get all jobs for a type where status is 'waiting'
            $waiting_jobs = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE job_type = %s AND status = %s ORDER BY created_at DESC',
                    $table_name,
                    $job_type,
                    'waiting'
                )
            );

            // abort if less than 2 jobs of same type in waiting status
            if ( $waiting_jobs < 2 ) {
                WsLog::l( 'less than 2 jobs for this type, continuing' );
                WsLog::l( (string) count( $waiting_jobs ) );
                continue;
            }

            // select all
            $waiting_jobs = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE job_type = %s AND status = %s ORDER BY created_at DESC',
                    $table_name,
                    $job_type,
                    'waiting'
                )
            );

            // remove latest one
            array_shift( $waiting_jobs );

            // set all but most recent one to 'skipped'
            foreach ( $waiting_jobs as $waiting_job ) {
                $wpdb->update(
                    $table_name,
                    [ 'status' => 'skipped' ],
                    [ 'id' => $waiting_job->id ]
                );

            }
        }
    }

    public static function setStatus( int $id, string $status ) : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $wpdb->update(
            $table_name,
            [ 'status' => $status ],
            [ 'id' => $id ]
        );
    }

    /**
     *  Get total count of jobs
     *
     *  @return int Total jobs
     */
    public static function getTotalJobs() : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $total_jobs = $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
        );

        return $total_jobs;
    }

    public static function getWaitingJobs() : int {
        return static::getWaitingJobsCount();
    }

    /**
     *  Get count of waiting jobs
     *
     *  @return int Waiting jobs
     */
    public static function getWaitingJobsCount() : int {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $total_jobs = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s',
                $table_name,
                'waiting'
            )
        );

        return $total_jobs;
    }

    /**
     *  Clear JobQueue via truncate or deletion
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting all jobs from JobQueue' );

        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );

        $total_jobs = self::getTotalJobs();

        if ( $total_jobs > 0 ) {
            WsLog::l( 'failed to truncate JobQueue: try deleting instead' );
        }
    }

    /**
     *  Detect any 'processing' jobs that are not running and change status to 'failed'.
     *
     *  @throws \Throwable
     */
    public static function markFailedJobs() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $job_types = [ 'detect', 'crawl', 'post_process', 'deploy' ];
        $table_name = $wpdb->prefix . 'wp2static_jobs';

        $wpdb->query( 'START TRANSACTION' );

        foreach ( $job_types as $type ) {
            try {
                $lock = "{$wpdb->prefix}.wp2static_jobs.$type";
                $free = intval(
                    $wpdb->get_row(
                        $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s) AS free', $lock )
                    )->free
                );

                if ( $free ) {
                    $failed_jobs = $wpdb->query(
                        $wpdb->prepare(
                            'UPDATE %i SET status = %s WHERE job_type = %s AND status = %s',
                            $table_name,
                            'failed',
                            $type,
                            'processing'
                        )
                    );
                    if ( $failed_jobs ) {
                        $s = $failed_jobs === 1 ? '' : 's';
                        WsLog::l( "$failed_jobs processing $type job$s marked as failed." );
                    }
                }

                $wpdb->query( 'COMMIT' );
            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                throw $e;
            }
        }
    }
}
