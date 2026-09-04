<?php
/**
 * Copia il sito generato in una cartella della stessa macchina.
 *
 * Riscritto per copiare solo quello che e' cambiato. Prima si svuotava la
 * destinazione e si ricopiava tutto a ogni deploy: milleottocento file per
 * pubblicarne uno modificato, e nel mezzo una finestra in cui il sito
 * pubblicato non esisteva. Adesso il core dice cosa e' cambiato — vedi
 * WP2Static\DeployPlan — e qui si copiano quei file, si cancellano quelli
 * spariti e non si tocca il resto.
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

use WP2Static\DeployCache;
use WP2Static\WsLog;

class Deployer {

    /**
     * Lo spazio dei nomi con cui i file finiscono nella DeployCache del core.
     *
     * Non e' `default`: la cache e' condivisa fra i deployer, e due deployer
     * diversi che pubblicano lo stesso sito in due posti diversi devono poter
     * dire ciascuno cosa ha gia' messo dove.
     */
    const DEFAULT_NAMESPACE = 'wp2static-addon-directory-deployment';

    /**
     * @param string $processed_site_path Cartella del sito processato.
     */
    public function uploadFiles( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        $target = (string) Controller::getValue( 'directoryDeploymentTargetDirectory' );

        if ( '' === $target ) {
            WsLog::l(
                'You must specify the target folder in WP2Static > Addons >' .
                ' Directory Deployment > Configure'
            );

            return;
        }

        if ( ! is_dir( $target ) ) {
            WsLog::l( 'Target folder does not exist: ' . $target );

            return;
        }

        $target = rtrim( $target, '/' );

        /*
         * Svuotare la destinazione resta possibile, ma non e' piu' la strada
         * normale: cancella anche i file che non sono cambiati, e nel frattempo
         * il sito pubblicato non c'e'. Serve solo per ripartire da zero, ed e'
         * per questo che dopo averlo fatto si svuota anche la cache — altrimenti
         * il piano direbbe «tutto invariato» su una cartella vuota.
         */
        if ( 0 !== intval( Controller::getValue( 'directoryDeploymentDeleteBeforeDeployment' ) ) ) {
            WsLog::l( 'Cleaning ' . $target );

            rrmdir( $target );
            mkdir( $target, 0755, true );

            DeployCache::truncate( self::DEFAULT_NAMESPACE );
        }

        $plan = DeployCache::plan( self::DEFAULT_NAMESPACE );

        WsLog::l( $plan->summary() );

        $copied = 0;

        foreach ( $plan->toDeploy() as $path ) {
            if ( $this->copyFile( $processed_site_path . $path, $target . $path ) ) {
                DeployCache::addFile( $path, self::DEFAULT_NAMESPACE );

                $copied++;
            }
        }

        $removed = $this->removeFiles( $plan->toDelete(), $target );

        // La cache si aggiorna DOPO: se il deploy si ferma a meta', quello che
        // non e' stato copiato deve restare da copiare al giro successivo.
        DeployCache::rmPaths( $plan->toDelete(), self::DEFAULT_NAMESPACE );

        WsLog::l( "Directory deployment complete: $copied copied, $removed removed." );

        $this->copyAdditionalSource( $target );
    }

    /**
     * @param string $from Percorso assoluto del file di partenza.
     * @param string $to   Percorso assoluto di destinazione.
     */
    private function copyFile( string $from, string $to ) : bool {
        $directory = dirname( $to );

        if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            WsLog::l( 'Could not create directory: ' . $directory );

            return false;
        }

        if ( ! copy( $from, $to ) ) {
            WsLog::l( 'Could not copy: ' . $from );

            return false;
        }

        return true;
    }

    /**
     * Cancella i file spariti e le cartelle rimaste vuote.
     *
     * @param string[] $paths  Percorsi relativi da rimuovere.
     * @param string   $target Radice della destinazione.
     * @return int Quanti ne sono stati rimossi davvero.
     */
    private function removeFiles( array $paths, string $target ) : int {
        $removed = 0;
        $directories = [];

        foreach ( $paths as $path ) {
            $file = $target . $path;

            if ( is_file( $file ) && unlink( $file ) ) {
                $directories[ dirname( $file ) ] = true;

                $removed++;
            }
        }

        /*
         * Una cartella rimasta vuota e` un residuo visibile: su un server che
         * elenca le directory diventa una pagina vuota indicizzabile. Si tolgono
         * dalla piu` profonda alla piu` alta, e solo se vuote — `rmdir` fallisce
         * da se` sulle altre.
         */
        krsort( $directories );

        foreach ( array_keys( $directories ) as $directory ) {
            if ( 0 === strpos( $directory, $target ) && $directory !== $target ) {
                @rmdir( $directory );
            }
        }

        return $removed;
    }

    /**
     * La cartella accessoria: file che non vengono dal sito processato e che
     * l'utente vuole comunque a destinazione. Non passa dal piano, perche' il
     * piano descrive il sito generato: qui si copia e basta.
     *
     * @param string $target Radice della destinazione.
     */
    private function copyAdditionalSource( string $target ) : void {
        $extra = (string) Controller::getValue( 'directoryDeploymentAdditionalSourceDirectory' );

        if ( '' === $extra ) {
            return;
        }

        if ( ! is_dir( $extra ) ) {
            WsLog::l( 'Extra folder does not exist: ' . $extra );

            return;
        }

        WsLog::l( 'Copying ' . $extra . ' to ' . $target );

        xcopy( $extra, $target );
    }
}
