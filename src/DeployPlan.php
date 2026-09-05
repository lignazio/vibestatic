<?php
/**
 * What a deploy would change, before doing it.
 *
 * Three lists and a count: the files to upload, the ones to remove at the
 * destination because they no longer exist, and how many were identical.
 *
 * It serves two purposes. The first is the honest report: without a number
 * before the deploy there is no way to trust an incremental deploy, and
 * distrust leads to pressing "upload everything", which cancels the feature
 * out. The second is deletion: no deployer knew which files had gone, so either
 * the destination was emptied every time or dead URLs stayed online.
 *
 * @package WP2Static
 */

namespace WP2Static;

class DeployPlan {

    /**
     * @var string[] Percorsi da caricare: nuovi o cambiati.
     */
    private $to_deploy;

    /**
     * @var string[] Percorsi da rimuovere a destinazione.
     */
    private $to_delete;

    /**
     * @var int Quanti file sono identici a quelli gia' pubblicati.
     */
    private $unchanged;

    /**
     * @param string[] $to_deploy Paths that are new or changed.
     * @param string[] $to_delete Paths gone from the processed site.
     * @param int      $unchanged How many files did not change.
     */
    public function __construct( array $to_deploy, array $to_delete, int $unchanged ) {
        $this->to_deploy = $to_deploy;
        $this->to_delete = $to_delete;
        $this->unchanged = $unchanged;
    }

    /**
     * @return string[]
     */
    public function toDeploy() : array {
        return $this->to_deploy;
    }

    /**
     * @return string[]
     */
    public function toDelete() : array {
        return $this->to_delete;
    }

    public function unchanged() : int {
        return $this->unchanged;
    }

    /**
     * True when there is nothing to do.
     */
    public function isEmpty() : bool {
        return ! $this->to_deploy && ! $this->to_delete;
    }

    /**
     * One line for the log, in the same shape as the crawl's.
     */
    public function summary() : string {
        return sprintf(
            'Deploy plan: %d to upload, %d to remove, %d unchanged.',
            count( $this->to_deploy ),
            count( $this->to_delete ),
            $this->unchanged
        );
    }
}
