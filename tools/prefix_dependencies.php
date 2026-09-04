<?php
/**
 * Lancia Strauss, che riscrive le dipendenze di terze parti da `vendor/` a
 * `vendor-prefixed/` sotto il namespace WP2Static\Vendor\.
 *
 * Perche' passare da uno script invece di mettere `strauss` direttamente in
 * post-install-cmd: quel comando gira anche durante `composer install --no-dev`,
 * e li' Strauss e' una dipendenza di sviluppo che e' appena stata rimossa. Un
 * riferimento diretto al binario farebbe fallire l'installazione di produzione
 * con un errore che non c'entra niente con la causa.
 *
 * Quando Strauss non c'e', `vendor-prefixed/` resta com'era: e' esattamente il
 * comportamento che serve nel build, dove la cartella e' gia' stata generata
 * dall'installazione completa che precede.
 *
 * @package WP2Static
 */

declare( strict_types = 1 );

$binary = __DIR__ . '/../vendor/bin/strauss';

if ( ! file_exists( $binary ) ) {
    fwrite(
        STDERR,
        "Strauss non installato: vendor-prefixed/ lasciata invariata.\n"
    );
    exit( 0 );
}

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $binary );

passthru( $command, $exit_code );

if ( 0 !== $exit_code ) {
    exit( $exit_code );
}

/*
 * Strauss genera anche `vendor/composer/autoload_aliases.php`: 1891 righe che
 * registrano un autoloader il quale, alla richiesta di `GuzzleHttp\Client`,
 * crea un alias verso la copia prefissata. Serve a far funzionare il codice di
 * sviluppo che usa ancora i nomi originali, e in Strauss 0.29 non si puo'
 * disattivare da configurazione: viene acceso d'ufficio quando
 * delete_vendor_packages e' true (StraussConfig::isCreateAliases()).
 *
 * Dentro WordPress quel file rimette in piedi esattamente il problema che la
 * prefissazione serve a risolvere, e peggiorato: il plugin tornerebbe a
 * dichiarare il namespace GuzzleHttp\, e un altro plugin che carica il suo
 * Guzzle dopo di noi riceverebbe la NOSTRA versione. Non piu' una collisione
 * subita, una causata.
 *
 * Il require in vendor/autoload.php e' dentro un file_exists(), quindi
 * cancellare il file basta e non serve toccare codice generato.
 */
$aliases = __DIR__ . '/../vendor/composer/autoload_aliases.php';

if ( file_exists( $aliases ) ) {
    unlink( $aliases );
    echo "Rimosso autoload_aliases.php: i nomi originali restano non risolvibili, come deve essere.\n";
}
