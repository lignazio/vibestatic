<?php
/**
 * The add-on's autoloader.
 *
 * Its own namespace is mapped here in a few lines rather than through
 * `vendor/autoload.php`, so the module loads without a build step of its own.
 * phpseclib is not its business any more: it is a production dependency of the
 * core, prefixed by Strauss into vendor-prefixed/, and the core's autoloader
 * has already been registered by the time a module is loaded.
 *
 * @package WP2StaticSFTP
 */

spl_autoload_register(
    function ( string $class ) : void {
        $prefix = 'WP2StaticSFTP\\';

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
