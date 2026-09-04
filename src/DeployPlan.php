<?php
/**
 * Cosa cambierebbe un deploy, prima di farlo.
 *
 * Tre liste e un conteggio: i file da caricare, quelli da rimuovere a
 * destinazione perche' non esistono piu', e quanti sono rimasti identici.
 *
 * Serve a due cose. La prima e' il rapporto onesto: senza un numero prima del
 * deploy non c'e' modo di fidarsi di un deploy incrementale, e la sfiducia
 * porta a premere «ricarica tutto», che annulla la funzionalita'. La seconda e'
 * la cancellazione: nessun deployer sapeva quali file fossero spariti, quindi o
 * si svuotava la destinazione ogni volta o gli URL morti restavano online.
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
     * @param string[] $to_deploy Percorsi nuovi o cambiati.
     * @param string[] $to_delete Percorsi spariti dal sito processato.
     * @param int      $unchanged Quanti file non sono cambiati.
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
     * Vero quando non c'e' niente da fare.
     */
    public function isEmpty() : bool {
        return ! $this->to_deploy && ! $this->to_delete;
    }

    /**
     * Una riga per il log, nella stessa forma di quella del crawl.
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
