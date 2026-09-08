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
