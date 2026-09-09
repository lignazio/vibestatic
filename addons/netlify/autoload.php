<?php
/**
 * The module's autoloader.
 *
 * Its namespace is mapped here in a few lines rather than through a
 * `vendor/autoload.php` of its own. The one third-party thing it needs is an
 * HTTP client, and it uses the core's prefixed Guzzle rather than installing a
 * second copy: the original declared `guzzlehttp/guzzle: "*"`, an unconstrained
 * wildcard on its only runtime dependency.
 *
 * @package WP2StaticNetlify
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
        $prefix = 'WP2StaticNetlify\\';

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
