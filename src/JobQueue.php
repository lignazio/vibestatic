<?php
/**
 * Facciata statica della coda dei lavori.
 *
 * Le query stanno in JobQueueRepository. Qui restano i nomi pubblici e i
 * messaggi di log, che sono quello che l'utente legge nella pagina Logs.
 *
 * @package WP2Static
 */

namespace WP2Static;

class JobQueue {

    /**
     * @var JobQueueRepository|null
     */
    private static $repository = null;

    /**
     * @param JobQueueRepository|null $repository Null per tornare al default.
     */
    public static function setRepository( ?JobQueueRepository $repository ) : void {
        self::$repository = $repository;
    }

    /**
     * @return JobQueueRepository Costruito su `global $wpdb` se non iniettato.
     */
    public static function repository() : JobQueueRepository {
        if ( ! self::$repository ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            self::$repository = new JobQueueRepository( $wpdb );
        }

        return self::$repository;
    }

    /**
     * Crea la tabella dei lavori.
     */
    public static function createTable() : void {
        self::repository()->createTable();
    }

    /**
     * Add Job to queue
     *
     * @param string $job_type Type of job
     * ie detect, crawl, post_process, deploy
     */
    public static function addJob( string $job_type ) : void {
        WsLog::l( 'Adding job: ' . $job_type );

        self::repository()->addJob( $job_type );
    }

    /**
     *  Get all jobs
     *
     *  @return object[] All jobs
     */
    public static function getJobs() : array {
        return self::repository()->getJobs();
    }

    /**
     *  Check for any jobs in progress
     *
     *  @return bool All waiting jobs
     */
    public static function jobsInProgress() : bool {
        return self::repository()->jobsInProgress();
    }

    /**
     *  Get all waiting jobs
     *
     *  @return mixed[] All waiting jobs
     */
    public static function getProcessableJobs() : array {
        return self::repository()->getProcessableJobs();
    }

    /**
     * Get count of jobs organized by type
     *
     * @return int[] keys are job type and values are count
     */
    public static function getJobCountByType() : array {
        return self::repository()->getJobCountByType();
    }

    /**
     * Skip processing jobs where a more recent job of same type exists.
     */
    public static function squashQueue() : void {
        $squashed = self::repository()->squashQueue();

        if ( $squashed ) {
            $s = 1 === $squashed ? '' : 's';

            WsLog::l( "$squashed superseded job$s skipped." );
        }
    }

    /**
     * @param int    $id     Id del lavoro.
     * @param string $status Nuovo stato.
     */
    public static function setStatus( int $id, string $status ) : void {
        self::repository()->setStatus( $id, $status );
    }

    /**
     *  Get total count of jobs
     *
     *  @return int Total jobs
     */
    public static function getTotalJobs() : int {
        return self::repository()->getTotalJobs();
    }

    /**
     *  Get count of waiting jobs
     *
     *  Alias storico di getWaitingJobsCount(): resta perche' e' API.
     *
     *  @return int Waiting jobs
     */
    public static function getWaitingJobs() : int {
        return static::getWaitingJobsCount();
    }

    /**
     *  Get count of waiting jobs
     *
     *  @return int Waiting jobs
     */
    public static function getWaitingJobsCount() : int {
        return self::repository()->getWaitingJobsCount();
    }

    /**
     *  Clear JobQueue via truncate or deletion
     */
    public static function truncate() : void {
        WsLog::l( 'Deleting all jobs from JobQueue' );

        self::repository()->truncate();

        if ( self::getTotalJobs() > 0 ) {
            WsLog::l( 'failed to truncate JobQueue: try deleting instead' );
        }
    }

    /**
     *  Detect any 'processing' jobs that are not running and change status to 'failed'.
     *
     *  @throws \Throwable Se una delle UPDATE fallisce.
     */
    public static function markFailedJobs() : void {
        foreach ( self::repository()->markFailedJobs() as $type => $count ) {
            $s = 1 === $count ? '' : 's';

            WsLog::l( "$count processing $type job$s marked as failed." );
        }
    }
}
