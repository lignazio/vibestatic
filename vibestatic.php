<?php
/**
 * Plugin Name: VibeStatic
 * Plugin URI:  https://github.com/lignazio/vibestatic
 * Description: Static site generation for WordPress, with incremental deployment.
 * Version:     7.2
 * Author:      Ignazio Lucenti
 * Author URI:  https://lucenti.studio
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vibestatic
 *
 * VibeStatic è un fork di WP2Static, creato da Leon Stafford e poi mantenuto da
 * Strattic by Elementor fino al 2024. L'originale è rilasciato nel pubblico
 * dominio (Unlicense); questo fork esce sotto GPLv2 o successiva.
 *
 * Il nome nuovo vale per quello che si legge e per quello che sta su disco.
 * Non vale per niente che un addon possa chiamare: il namespace PHP resta
 * `WP2Static\`, i trenta hook restano `wp2static_*`, le otto tabelle restano
 * `wp_wp2static_*`, e gli slug delle pagine admin restano quelli — sono URL, e
 * sono il genitore sotto cui gli addon appendono le proprie pagine. Ventuno
 * addon esistenti continuano a funzionare senza essere toccati, ed è la
 * promessa su cui si regge il fork.
 *
 * @package     WP2Static
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'VIBESTATIC_VERSION', '7.2' );
define( 'VIBESTATIC_PATH', plugin_dir_path( __FILE__ ) );

/*
 * I due nomi vecchi restano definiti. Nessun addon fra i ventuno li usa — l'ho
 * verificato — ma sono costanti pubblicate, e tenerle in vita costa due righe.
 */
define( 'WP2STATIC_VERSION', VIBESTATIC_VERSION );
define( 'WP2STATIC_PATH', VIBESTATIC_PATH );

/*
 * Due autoloader, non uno. `vendor/` contiene la mappa PSR-4 del plugin —
 * WP2Static\ verso src/ — mentre le dipendenze di terze parti vivono in
 * `vendor-prefixed/`, sotto il namespace WP2Static\Vendor\, generate da
 * Strauss al momento del build.
 *
 * Il prefisso non e' cosmesi: WordPress carica tutti i plugin nello stesso
 * processo, e due plugin che imbarcano versioni diverse di Guzzle si
 * distruggono a vicenda — il primo caricato vince e l'altro riceve una classe
 * che non e' quella che si aspetta. E' lo stesso problema che il fork
 * leonstafford/wp2staticguzzle risolveva rinominando i file a mano, con la
 * differenza che qui la rinomina e' automatica e l'origine e' Guzzle upstream,
 * aggiornabile e con le patch di sicurezza.
 */
foreach ( [ 'vendor/autoload.php', 'vendor-prefixed/autoload.php' ] as $vibestatic_autoloader ) {
    if ( ! file_exists( VIBESTATIC_PATH . $vibestatic_autoloader ) ) {
        continue;
    }

    require_once VIBESTATIC_PATH . $vibestatic_autoloader;
}

unset( $vibestatic_autoloader );

if ( ! class_exists( 'WP2Static\Controller' ) ) {
    if ( file_exists( VIBESTATIC_PATH . 'src/WP2StaticException.php' ) ) {
        require_once VIBESTATIC_PATH . 'src/WP2StaticException.php';

        throw new WP2Static\WP2StaticException(
            'VibeStatic non trova le sue dipendenze: sembra installato dal' .
            ' codice sorgente senza averlo compilato. Lancia `composer install`' .
            ' nella cartella del plugin, oppure installa lo zip di una release.'
        );
    }
}

WP2Static\Controller::init( __FILE__ );

/**
 * Define Settings link for plugin
 *
 * Il nome era `plugin_action_links`, nello spazio globale: cioè il nome più
 * generico possibile per una funzione che ogni plugin di WordPress ha ragione
 * di voler definire. Due plugin che lo facessero insieme darebbero un fatal
 * error di ridichiarazione, e non a chi ha scritto il secondo.
 *
 * @param string[] $links array of links
 * @return string[] modified array of links
 */
function vibestatic_plugin_action_links( $links ) {
    $settings_link =
        '<a href="admin.php?page=wp2static">' .
        __( 'Settings', 'vibestatic' ) .
        '</a>';
    array_unshift( $links, $settings_link );

    return $links;
}

add_filter(
    'plugin_action_links_' .
    plugin_basename( __FILE__ ),
    'vibestatic_plugin_action_links'
);

/**
 * Prevent WP scripts from loading which aren't useful
 * on a statically exported site
 */
function vibestatic_deregister_scripts(): void {
    wp_dequeue_script( 'wp-embed' );
    wp_deregister_script( 'wp-embed' );
    wp_dequeue_script( 'comment-reply' );
    wp_deregister_script( 'comment-reply' );
}

add_action( 'wp_footer', 'vibestatic_deregister_scripts' );

// TODO: move into own plugin for WP cleanup, don't belong in core
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

if ( defined( 'WP_CLI' ) ) {
    WP_CLI::add_command( 'vibestatic', WP2Static\CLI::class );

    /*
     * `wp wp2static` continua a rispondere. E' in script di deploy e in cron di
     * chi il plugin lo usava gia', e romperlo per un rinominamento sarebbe un
     * danno gratuito. Non e' deprecato e non stampa avvisi: e' un secondo nome
     * per lo stesso comando.
     */
    WP_CLI::add_command( 'wp2static', WP2Static\CLI::class );
}
