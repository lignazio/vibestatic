<?php

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveArrayIterator;
use RecursiveDirectoryIterator;

class FilesHelper {

    /**
     * Recursively delete a directory
     *
     * @throws WP2StaticException
     */
    public static function deleteDirWithFiles( string $dir ) : void {
        if ( is_dir( $dir ) ) {
            $dir_files = scandir( $dir );

            if ( ! $dir_files ) {
                $err = 'Trying to delete nonexistent dir: ' . $dir;
                WsLog::l( $err );
                throw new WP2StaticException( esc_html( $err ) );
            }

            $files = array_diff( $dir_files, [ '.', '..' ] );

            foreach ( $files as $file ) {
                ( is_dir( "$dir/$file" ) ) ?
                self::deleteDirWithFiles( "$dir/$file" ) :
                unlink( "$dir/$file" );
            }

            rmdir( $dir );
        }
    }

    /**
     * Quanta parte del sito puo` sparire in un giro solo.
     *
     * Serve perche` un guasto a monte non si presenta come un errore: si
     * presenta come un elenco piu` corto. La sitemap che non risponde, il
     * custom post type non ancora registrato quando il job parte, la cartella
     * uploads montata in ritardo — nessuno di questi lancia niente, e da valle
     * non si distinguono da un utente che ha davvero cancellato meta` sito.
     * Sopra questa quota non si sceglie: non si cancella e si dice perche`,
     * che e` l'unica risposta che non puo` spubblicare un sito vivo per
     * sbaglio.
     *
     * Meta` perche` sotto ci sta ogni fallimento plausibile di un singolo
     * rilevatore — su WordPress la parte grossa dell'elenco sono gli asset di
     * `wp-includes` e del tema, che vengono dal filesystem e non da una
     * richiesta HTTP — e sopra ci sta solo il caso in cui e` sparito un pezzo
     * intero della catena.
     */
    const MAX_SHRINK_FRACTION = 0.5;

    /**
     * Se un sito che si accorcia di tanto sia da credere.
     *
     * Una soglia sola per tutti e tre i punti in cui si dimentica qualcosa,
     * perche` sono la stessa domanda: due soglie separate vorrebbero dire che
     * alzarne una lascia le altre a bloccare, e il blocco a meta` e` peggio di
     * entrambe le risposte intere.
     *
     * Su un totale a zero non c'e` niente di cui dubitare, e la divisione non
     * si fa.
     */
    public static function shrinkIsPlausible( int $going, int $total ) : bool {
        if ( $total < 1 ) {
            return false;
        }

        // Vedi Crawler::__construct(): un filtro restituisce quello che decide
        // chi lo aggancia, e qui una stringa o un null diventerebbero zero —
        // cioe' «non togliere mai niente», in silenzio.
        $filtered = apply_filters(
            'wp2static_max_stale_fraction',
            self::MAX_SHRINK_FRACTION
        );

        $max_fraction = is_numeric( $filtered )
            ? (float) $filtered
            : self::MAX_SHRINK_FRACTION;

        return ( $going / $total ) <= $max_fraction;
    }

    /**
     * Se il sito pubblicato debba poter rimpicciolire.
     *
     * Governa i tre punti in cui qualcosa viene dimenticato — la coda di
     * crawl, il sito crawlato, il sito processato — perche` sono la stessa
     * decisione presa tre volte, e spegnerne uno solo lascerebbe le cartelle
     * disallineate fra loro.
     *
     * Esiste perche` una potatura sbagliata spubblica un sito vivo, ed e`
     * l'unica categoria di danno che questo codice puo` fare: chi si trova
     * nella situazione ha bisogno di fermarla senza dover sapere quali
     * percorsi salvare uno per uno.
     */
    public static function pruningEnabled() : bool {
        return (bool) apply_filters( 'wp2static_prune_stale_files', true );
    }

    /**
     * Toglie da una cartella i file che non sono nell'elenco, e le cartelle
     * che restano vuote.
     *
     * I percorsi, sia quelli dell'elenco sia quelli restituiti, sono relativi
     * alla radice e cominciano con `/`: la stessa forma che scrivono
     * `StaticSite::add()` e `ProcessedSite::add()` e che legge il piano di
     * deploy. Il confronto e` sul percorso cosi` come sta su disco, non su
     * `realpath()`, perche` e` cosi` che il file e` stato scritto.
     *
     * **Un elenco vuoto non cancella niente.** Vuoto vuol dire «non lo so»,
     * non «il sito e` vuoto», e fra le due letture c'e` un sito pubblicato che
     * sparisce. Chi vuole davvero svuotare ha `StaticSite::delete()` e
     * `ProcessedSite::delete()`, che esistono apposta.
     *
     * @param string   $dir  Radice da ripulire.
     * @param string[] $keep Percorsi relativi da tenere.
     * @return string[] Percorsi rimossi.
     */
    public static function removePathsNotIn( string $dir, array $keep ) : array {
        if ( ! $keep || ! is_dir( $dir ) ) {
            return [];
        }

        $dir = rtrim( $dir, '/' );
        $keep_index = array_fill_keys( $keep, true );

        /*
         * La scansione si chiude prima di cancellare: cancellare mentre
         * l'iteratore cammina sulla stessa cartella lascia il suo stato
         * indietro rispetto al disco, e quello che salta e` una voce a caso.
         */
        $to_remove = [];
        $scanned = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $filename => $file_object ) {
            /**
             * @var string $filename
             */

            $path = substr( $filename, strlen( $dir ) );

            $scanned++;

            if ( ! isset( $keep_index[ $path ] ) ) {
                $to_remove[ $filename ] = $path;
            }
        }

        /*
         * L'ultima sicura, ed e' su una quantita' diversa da quella che ha
         * gia' guardato la rilevazione: li' era la coda, qui sono i file. Le
         * due non coincidono se la coda si e' accorciata per una via che non
         * passa dal confronto con la rilevazione — svuotata a mano dalla
         * pagina Caches, per esempio, e poi solo in parte riempita. Questa e'
         * la funzione che cancella davvero: e' l'ultimo punto in cui ci si puo'
         * ancora fermare.
         */
        if ( ! self::shrinkIsPlausible( count( $to_remove ), $scanned ) ) {
            WsLog::l(
                sprintf(
                    'Refusing to prune %s: %d of its files have nothing that explains them, ' .
                    'which looks more like a step that did not finish than a smaller site. ' .
                    'Nothing was removed.',
                    $dir,
                    count( $to_remove )
                )
            );

            return [];
        }

        $removed = [];
        $emptied = [];

        foreach ( $to_remove as $filename => $path ) {
            if ( unlink( (string) $filename ) ) {
                $removed[] = $path;
                $emptied[ dirname( (string) $filename ) ] = true;
            }
        }

        /*
         * Dalla piu` profonda alla piu` alta, e risalendo finche` `rmdir`
         * accetta: una cartella puo` restare vuota perche` si e` svuotata la
         * sola sottocartella che conteneva, e in quel caso il suo nome non e`
         * mai passato di qui. `rmdir` fallisce da se` su una cartella piena,
         * quindi non serve controllarlo prima.
         */
        krsort( $emptied );

        foreach ( array_keys( $emptied ) as $directory ) {
            while ( 0 === strpos( $directory, $dir . '/' ) && @rmdir( $directory ) ) {
                $directory = dirname( $directory );
            }
        }

        sort( $removed );

        return $removed;
    }

    /**
     * Get public URLs for all files in a local directory.
     *
     * @param string $dir
     * @param array<string> $filenames_to_ignore
     * @param array<string> $file_extensions_to_ignore
     * @return string[] list of relative, urlencoded URLs
     */
    public static function getListOfLocalFilesByDir(
        string $dir,
        array $filenames_to_ignore,
        array $file_extensions_to_ignore
    ) : array {
        $site_path = SiteInfo::getPath( 'site' );

        if ( ! is_string( $site_path ) ) {
            return [];
        }

        $files = [];

        if ( is_dir( $dir ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $filename => $file_object ) {
                /**
                 * @var string $filename
                 */

                $path_crawlable = self::pathLooksCrawlable(
                    $filename,
                    $filenames_to_ignore,
                    $file_extensions_to_ignore
                );

                if ( $path_crawlable ) {
                    $url = str_replace( $site_path, '/', $filename );

                    if ( is_string( $url ) ) {
                        $files[] = $url;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Ensure a given filepath has an allowed filename and extension.
     *
     * @param string $file_name
     * @param array<string> $filenames_to_ignore
     * @param array<string> $file_extensions_to_ignore
     * @return bool  True if the given file does not have a disallowed filename
     *               or extension.
     */
    public static function pathLooksCrawlable(
        string $file_name,
        array $filenames_to_ignore,
        array $file_extensions_to_ignore
    ) : bool {
        $filename_matches = 0;

        str_ireplace( $filenames_to_ignore, '', $file_name, $filename_matches );

        // If we found matches we don't need to go any further
        if ( $filename_matches ) {
            return false;
        }

        /*
          Prepare the file extension list for regex:
          - Add prepending (escaped) \ for a literal . at the start of
            the file extension
          - Add $ at the end to match end of string
          - Add i modifier for case insensitivity
        */
        foreach ( $file_extensions_to_ignore as $extension ) {
            if ( preg_match( "/\\{$extension}$/i", $file_name ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure a given filepath has an allowed filename and extension.
     *
     * @return bool  True if the given file does not have a disallowed filename
     *               or extension.
     */
    public static function filePathLooksCrawlable( string $file_name ) : bool {
        $filenames_to_ignore = CoreOptions::getLineDelimitedBlobValue( 'filenamesToIgnore' );

        $filenames_to_ignore =
            apply_filters(
                'wp2static_filenames_to_ignore',
                $filenames_to_ignore
            );

        $file_extensions_to_ignore = CoreOptions::getLineDelimitedBlobValue(
            'fileExtensionsToIgnore'
        );

        $file_extensions_to_ignore =
            apply_filters(
                'wp2static_file_extensions_to_ignore',
                $file_extensions_to_ignore
            );

        return self::pathLooksCrawlable(
            $file_name,
            $filenames_to_ignore,
            $file_extensions_to_ignore
        );
    }

    /**
     * Clean all detected URLs before use. Accepts relative and absolute URLs
     * both with and without starting or trailing slashes.
     *
     * @param string[] $urls list of absolute or relative URLs
     * @return string[]|null[] list of relative URLs
     * @throws WP2StaticException
     */
    public static function cleanDetectedURLs( array $urls ) : array {
        $home_url = SiteInfo::getUrl( 'home' );

        if ( ! is_string( $home_url ) ) {
            $err = 'Home URL not defined ';
            WsLog::l( $err );
            throw new WP2StaticException( esc_html( $err ) );
        }

        $cleaned_urls = array_map(
            // trim hashes/query strings
            function ( $url ) use ( $home_url ) {
                if ( ! $url ) {
                    return;
                }

                // NOTE: 2 x str_replace's significantly faster than
                // 1 x str_replace with search/replace arrays of 2 length
                $url = str_replace(
                    $home_url,
                    '/',
                    $url
                );

                $url = str_replace(
                    '//',
                    '/',
                    $url
                );

                if ( ! is_string( $url ) ) {
                    return;
                }

                $url = strtok( $url, '#' );

                if ( ! $url ) {
                    return;
                }

                $url = strtok( $url, '?' );

                if ( ! $url ) {
                    return;
                }

                return $url;
            },
            $urls
        );

        if ( empty( $cleaned_urls ) ) {
            $err = 'No valid URLs left after cleaning';
            WsLog::l( $err );
            return [];
        }

        return $cleaned_urls;
    }
}
