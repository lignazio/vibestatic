<?php

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class DetectPluginAssets {

    /**
     * Detect Plugin asset URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        $files = [];

        $plugins_path = SiteInfo::getPath( 'plugins' );
        $plugins_url = SiteInfo::getUrl( 'plugins' );

        if ( is_dir( $plugins_path ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $plugins_path,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            /**
             * @var string[] $active_plugins
             */
            $active_plugins = get_option( 'active_plugins' );
            /**
             * @var string[] $active_sitewide_plugins
             */
            $active_sitewide_plugins = get_option( 'active_sitewide_plugins' );

            if ( is_multisite() ) {
                $active_plugins = array_unique(
                    array_merge(
                        $active_plugins,
                        array_keys( $active_sitewide_plugins )
                    )
                );
            }

            $active_plugin_dirs = array_map(
                function ( $active_plugin ) {
                    return explode( '/', $active_plugin )[0];
                },
                $active_plugins
            );

            // Normalizzato una volta sola, con le stesse regole applicate ai
            // percorsi dei file poco piu' sotto (Windows).
            $plugins_prefix = rtrim( str_replace( '\\', '/', $plugins_path ), '/' ) . '/';

            foreach ( $iterator as $filename => $file_object ) {
                /**
                 * @var string $filename
                 */

                $path_crawlable =
                    FilesHelper::filePathLooksCrawlable( $filename );

                if ( ! $path_crawlable ) {
                    continue;
                }

                // Standardise all paths to use / (Windows support)
                $filename = str_replace( '\\', '/', $filename );

                /*
                 * Il confronto era
                 * `str_replace( $active_plugin_dirs, '', $filename ) !== $filename`,
                 * cioe' «il nome di un plugin attivo compare da qualche parte
                 * nel percorso ASSOLUTO». Non e' la stessa domanda di «questo
                 * file sta dentro la cartella di un plugin attivo», e la
                 * differenza si vede appena il percorso del sito contiene per
                 * caso il nome di un plugin attivo: da li' in poi passa
                 * qualunque file di qualunque plugin, compresi quelli
                 * disattivati — cioe' codice che il proprietario del sito ha
                 * deliberatamente spento e che finisce comunque pubblicato.
                 *
                 * Misurato qui: la cartella di lavoro si chiama `wp2static`,
                 * quindi ogni percorso assoluto conteneva quella stringa, e i
                 * quattordici file di Akismet — plugin non attivo — venivano
                 * esportati a ogni deploy.
                 *
                 * La domanda giusta e' sul primo segmento dopo `plugins/`.
                 */
                if ( 0 !== strpos( $filename, $plugins_prefix ) ) {
                    continue;
                }

                $plugin_dir = explode( '/', substr( $filename, strlen( $plugins_prefix ) ) )[0];

                if ( ! in_array( $plugin_dir, $active_plugin_dirs, true ) ) {
                    continue;
                }

                $detected_filename =
                    str_replace(
                        $plugins_path,
                        $plugins_url,
                        $filename
                    );

                $detected_filename =
                    str_replace(
                        get_home_url(),
                        '',
                        $detected_filename
                    );

                if ( is_string( $detected_filename ) ) {
                    array_push(
                        $files,
                        $detected_filename
                    );
                }
            }
        }

        return $files;
    }
}
