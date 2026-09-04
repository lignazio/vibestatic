<?php
/**
 * Autoloader dell'addon.
 *
 * L'originale richiedeva `vendor/autoload.php`, cioe' un `composer install`
 * dentro la cartella dell'addon per mappare un namespace solo verso una
 * cartella sola. Non ha dipendenze di terze parti: quindici righe fanno la
 * stessa cosa senza chiedere un passo di build a chi lo installa.
 *
 * @package WP2StaticDirectoryDeployer
 */

// Le funzioni non si autocaricano per nome: questo file si include sempre.
require_once __DIR__ . '/src/functions.php';

spl_autoload_register(
    function ( string $class ) : void {
        $prefix = 'WP2StaticDirectoryDeployer\\';

        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
        $file = __DIR__ . '/src/' . $relative . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);
