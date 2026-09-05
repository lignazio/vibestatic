<?php
/*
    PostProcessor

    Processes each file in StaticSite, saving to ProcessedSite
*/

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class PostProcessor {

    /**
     * PostProcessor constructor
     */
    public function __construct() {
    }

    /**
     * Toglie dal sito processato quello che il sito crawlato non ha piu`.
     *
     * Il ciclo qui sopra non cancella mai: copia e riscrive, quindi un file
     * uscito dal sito crawlato restava nel processato per sempre, e
     * `DeployCache::plan()` lo vedeva «invariato» e lo ripubblicava a ogni
     * giro. Era il pezzo mancante fra un crawl che gia` sapeva dimenticare e
     * un deploy che gia` sapeva rimuovere.
     *
     * Come nel Crawler, la garanzia e` che ci si arriva solo a giro finito:
     * l'elenco e` quello che l'iteratore ha visto per intero. Un
     * post-processing interrotto non arriva qui.
     *
     * E come nel Crawler, un elenco vuoto non cancella niente — un sito
     * crawlato vuoto e` un crawl mai fatto, non un sito che non esiste piu`.
     *
     * @param string[] $processed_paths Percorsi appena scritti.
     */
    private function pruneProcessedSite( array $processed_paths ) : void {
        if ( ! FilesHelper::pruningEnabled() ) {
            return;
        }

        $removed = ProcessedSite::prune( $processed_paths );

        if ( $removed ) {
            WsLog::l(
                sprintf(
                    'Pruned processed site: %d file(s) no longer in the crawled site.',
                    count( $removed )
                )
            );
        }
    }

    /**
     * Process StaticSite
     *
     * Iterates on each file, not directory
     *
     * @param string $static_site_path Static site path
     * @throws WP2StaticException
     */
    public function processStaticSite(
        string $static_site_path
    ) : void {
        WsLog::l(
            'Processing crawled site.'
        );

        if ( ! is_dir( $static_site_path ) ) {
            WsLog::l(
                'No static site directory to process.'
            );

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $static_site_path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $processed_paths = [];

        foreach ( $iterator as $filename => $file_object ) {
            /**
             * @var string $filename
             */

            $save_path = str_replace( $static_site_path, '', $filename );

            // copy file to ProcessedSite dir, then process it
            // this allows external processors to have their way with it
            ProcessedSite::add( $filename, $save_path );

            $file_processor = new FileProcessor();

            $file_processor->processFile( ProcessedSite::getPath() . $save_path );

            /*
             * `ProcessedSite::add()` scrive in `getPath() . "/$save_path"` e
             * $save_path comincia gia' con la barra: il file finisce al
             * percorso con una barra sola, ma la stringa ne ha due. Qui serve
             * la forma normalizzata, che e' quella che rilegge chi scandisce
             * la cartella.
             */
            $processed_paths[] = '/' . ltrim( $save_path, '/' );
        }

        WsLog::l( 'Finished processing crawled site.' );

        $this->pruneProcessedSite( $processed_paths );

        do_action( 'wp2static_post_process_complete', ProcessedSite::getPath() );
    }
}
