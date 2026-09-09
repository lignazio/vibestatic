<?php
/**
 * The add-on's autoloader.
 *
 * The original required `vendor/autoload.php`, that is, a `composer install`
 * inside the add-on's directory to map one namespace onto one directory. It has
 * no third-party dependencies: fifteen lines do the same thing without asking
 * whoever installs it to run a build step.
 *
 * @package WP2StaticDirectoryDeployer
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

// Functions are not autoloaded by name: this file is always included.
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
