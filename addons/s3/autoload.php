<?php
/**
 * The module's autoloader.
 *
 * Its namespace is mapped here in a few lines rather than through a
 * `vendor/autoload.php` of its own: this module has no third-party
 * dependencies, and the original required a build step purely to map its own
 * namespace — then threw an uncaught exception at plugin-load time when that
 * step had not been run, which is a white screen rather than a message.
 *
 * @package WP2StaticS3
 */

/*
 * A file that runs something when it is included has to be able to refuse being
 * included on its own. This one does — an spl_autoload_register(), and in one
 * module a require of its functions — and a file that merely declares a class
 * does not, which is why the guard is here and not in front of all sixty of
 * them.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

spl_autoload_register(
    function ( string $class ) : void {
        $prefix = 'WP2StaticS3\\';

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
