<?php
/**
 * Le due funzioni di filesystem dell'addon.
 *
 * Stavano in cima a Deployer.php, sotto la dichiarazione di namespace ma fuori
 * dalla classe. Qui hanno un file loro, che l'autoloader carica sempre: le
 * funzioni non si autocaricano per nome come le classi.
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

/**
 * Cancella una cartella e tutto quello che contiene.
 *
 * @param string $path Percorso assoluto.
 */
function rrmdir( string $path ) : void {
    if ( '' === trim( pathinfo( $path, PATHINFO_BASENAME ), '.' ) ) {
        return;
    }

    if ( ! is_dir( $path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink( $path );

        return;
    }

    $entries = glob( $path . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE | GLOB_NOSORT );

    // Il nome della funzione va qualificato: senza __NAMESPACE__, array_map
    // cerca una `rrmdir` globale che non esiste, e il deploy muore proprio
    // mentre svuota la destinazione.
    array_map( __NAMESPACE__ . '\\rrmdir', $entries ? $entries : [] );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    @rmdir( $path );
}

/**
 * Copia ricorsiva di una cartella.
 *
 * @param string $source      Cartella di partenza.
 * @param string $destination Cartella di destinazione.
 * @param int    $permissions Permessi delle cartelle create.
 */
function xcopy( string $source, string $destination, int $permissions = 0755 ) : bool {
    if ( is_link( $source ) ) {
        return symlink( (string) readlink( $source ), $destination );
    }

    if ( is_file( $source ) ) {
        return copy( $source, $destination );
    }

    if ( ! is_dir( $source ) ) {
        return false;
    }

    /*
     * Copiare una cartella dentro se stessa e` una ricorsione infinita.
     * L'originale ci arrivava calcolando l'md5 dell'intero albero a ogni
     * voce — cioe` rileggendo ogni file una volta per ogni file — per poi
     * confrontarlo con quello della cartella corrente. Il confronto giusto e`
     * fra i due percorsi, e costa niente.
     */
    $real_source = realpath( $source );
    $real_destination = realpath( $destination );

    if ( $real_source && $real_destination && 0 === strpos( $real_destination, $real_source ) ) {
        \WP2Static\WsLog::l( "Refusing to copy $source into itself" );

        return false;
    }

    if ( ! is_dir( $destination ) && ! mkdir( $destination, $permissions, true )
        && ! is_dir( $destination ) ) {
        return false;
    }

    $entries = scandir( $source );

    foreach ( $entries ? $entries : [] as $entry ) {
        if ( '.' === $entry || '..' === $entry ) {
            continue;
        }

        xcopy( "$source/$entry", "$destination/$entry", $permissions );
    }

    return true;
}
