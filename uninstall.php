<?php
/**
 * Disinstallazione: toglie tutto quello che il plugin ha messo.
 *
 * Gira senza l'autoloader del plugin, quindi qui non si possono usare le sue
 * classi: i percorsi si ricostruiscono con le funzioni di WordPress.
 *
 * @package WP2Static
 */

// exit uninstall if not called by WP
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

/** @var \wpdb $wpdb */
global $wpdb;

$tables_to_drop = [
    'wp2static_core_options',
    'wp2static_crawl_cache',
    'wp2static_deploy_cache',
    'wp2static_jobs',
    'wp2static_log',
    'wp2static_urls',
    // Mancava, ed e' una tabella del core: dopo la disinstallazione restava
    // l'elenco degli addon registrati, con i loro stati di abilitazione.
    'wp2static_addons',
    // Non viene più creata: era la tabella che teneva quali annunci Strattic
    // l'utente aveva chiuso. Resta nell'elenco perché va rimossa dalle
    // installazioni che l'hanno già.
    'wp2static_notices',
];

foreach ( $tables_to_drop as $table ) {
    $table_name = $wpdb->prefix . $table;

    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
}

delete_option( 'vibestatic_schema_version' );

/*
 * Le cartelle sotto uploads. Erano il «TODO: delete crawl_cache,
 * processed_site and zip if exist» lasciato dagli autori: senza questo, di un
 * sito da millecinquecento pagine restavano due copie complete su disco dopo
 * aver disinstallato il plugin che le aveva scritte.
 *
 * I nomi sono quelli prodotti da StaticSite e ProcessedSite. Si cancella solo
 * quello che riconosciamo, per nome esatto e sotto uploads: una cancellazione
 * ricorsiva in fase di disinstallazione e' il posto peggiore in cui essere
 * generosi con i percorsi.
 */
$uploads = wp_upload_dir();
$uploads_path = trailingslashit( $uploads['basedir'] );

/**
 * Cancella ricorsivamente una cartella.
 *
 * Il plugin ne ha gia' una, `FilesHelper::deleteDirWithFiles()`, e non si usa
 * qui di proposito: scrive nel log — cioe' in una tabella che tre righe piu'
 * sopra e' stata cancellata — e solleva un'eccezione se la cartella non c'e'.
 * Durante una disinstallazione servono entrambe le cose al contrario.
 *
 * @param string $path Percorso assoluto.
 */
function wp2static_uninstall_rmdir( string $path ) : void {
    if ( ! is_dir( $path ) ) {
        return;
    }

    /** @var iterable<\SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            rmdir( $item->getPathname() );

            continue;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $item->getPathname() );
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir( $path );
}

foreach ( [ 'wp2static-crawled-site', 'wp2static-processed-site' ] as $directory ) {
    wp2static_uninstall_rmdir( $uploads_path . $directory );
}
