<?php
/**
 * Creazione e aggiornamento delle tabelle.
 *
 * Prima le tabelle si creavano solo dentro `register_activation_hook`, che
 * scatta all'attivazione e mai piu'. Aggiornando il plugin — dal pannello di
 * WordPress, da Composer, sostituendo i file — quel gancio non scatta: una
 * colonna aggiunta in una versione nuova non sarebbe mai arrivata sui siti
 * gia' installati, e il codice nuovo avrebbe interrogato una tabella vecchia.
 * E' anche il motivo per cui dentro le createTable() ci sono i rattoppi che
 * tolgono colonne obsolete e ricreano indici: erano l'unico posto in cui
 * potevano girare.
 *
 * Adesso c'e' un numero di versione. Quando cambia, le tabelle si rifanno —
 * dbDelta e' idempotente, quindi rifarle su uno schema gia' aggiornato non
 * costa niente e non tocca i dati.
 *
 * @package WP2Static
 */

namespace WP2Static;

class Schema {

    /**
     * Da alzare di uno ogni volta che cambia la definizione di una tabella.
     *
     * Se non la si alza, la modifica arriva solo sulle installazioni nuove, e
     * il difetto si vede molto dopo e altrove.
     */
    const VERSION = 1;

    /**
     * @var string Dove si ricorda la versione applicata.
     */
    const OPTION = 'vibestatic_schema_version';

    /**
     * Crea o aggiorna tutte le tabelle, e registra la versione.
     */
    public static function install() : void {
        WsLog::createTable();
        CoreOptions::init();
        CrawlCache::createTable();
        CrawlQueue::createTable();
        DeployCache::createTable();
        JobQueue::createTable();
        Addons::createTable();

        update_option( self::OPTION, (string) self::VERSION, false );
    }

    /**
     * Vero quando lo schema sul posto non e' quello che questo codice si aspetta.
     */
    public static function needsUpdate() : bool {
        return get_option( self::OPTION ) !== (string) self::VERSION;
    }

    /**
     * Aggiorna lo schema se serve.
     *
     * Gira su `plugins_loaded`, ma solo in admin e da riga di comando: il
     * confronto costa una lettura di un'opzione autoloaded, cioe' niente, ma
     * dbDelta no — e farlo partire dalla richiesta di un visitatore qualunque
     * vuol dire far pagare a lui l'aggiornamento.
     */
    public static function updateIfNeeded() : void {
        if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
            return;
        }

        if ( ! self::needsUpdate() ) {
            return;
        }

        WsLog::l(
            'Updating database schema to version ' . self::VERSION
        );

        self::install();
    }
}
