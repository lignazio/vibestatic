<?php
/**
 * Accesso alla tabella wp_wp2static_jobs, la coda dei lavori.
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
     * Crea la tabella dei lavori.
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
     * @return int Quanti lavori sono stati marcati come saltati.
     */
    public function squashQueue() : int {
        $squashed = 0;

        foreach ( self::JOB_TYPES as $job_type ) {
            $waiting_jobs = $this->getWaitingJobsOfType( $job_type );

            /*
             * `count()`, non il confronto diretto. Prima la riga diceva
             * `if ( $waiting_jobs < 2 )` con $waiting_jobs che e' un array: in
             * PHP un array confrontato con un intero risulta sempre maggiore,
             * quindi la guardia era falsa in ogni caso — anche con zero lavori
             * in attesa — e non ha mai fermato niente. Subito sotto la stessa
             * query veniva rieseguita identica, ed e' il segno di cosa doveva
             * succedere: la prima serviva a contare, la seconda a lavorare.
             */
            if ( count( $waiting_jobs ) < 2 ) {
                continue;
            }

            // Il piu' recente sopravvive, gli altri si saltano.
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
     * @param string $job_type Tipo di lavoro.
     * @return list<object{id: int}> I lavori in attesa di quel tipo, dal piu' recente.
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
     * Svuota la coda dei lavori.
     */
    public function truncate() : void {
        $this->db->query( (string) $this->db->prepare( 'TRUNCATE TABLE %i', $this->table ) );
    }

    /**
     *  Detect any 'processing' jobs that are not running and change status to 'failed'.
     *
     *  Un lavoro «in lavorazione» che non ha nessuno che lo sta lavorando e' un
     *  processo morto a meta': lo si riconosce dal lock MySQL, che un processo
     *  vivo terrebbe occupato.
     *
     *  @return array<string, int> Quanti ne sono stati marcati, per tipo.
     *  @throws \Throwable Se una delle UPDATE fallisce.
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

                // get_row() puo' tornare null — connessione caduta, query
                // rifiutata. Prima si faceva `->free` direttamente sul
                // risultato, che in quel caso e' un fatal error dentro un
                // processo di sfondo, cioe' invisibile.
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
             * La COMMIT stava DENTRO il ciclo: dopo il primo tipo di lavoro la
             * transazione era gia' chiusa, e le tre UPDATE successive giravano
             * fuori — con un ROLLBACK, in caso di errore, che non aveva piu'
             * niente da annullare. Qui e' dove il codice diceva di volerla.
             */
            $this->db->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );

            throw $e;
        }

        return $marked;
    }
}
