<?php
/**
 * The add-on's autoloader.
 *
 * Its own namespace is mapped here in a few lines rather than through
 * `vendor/autoload.php`, so that the add-on loads without a build step. What
 * does need vendor/ is phpseclib, which is a real third-party dependency and
 * cannot be conjured: if it is missing, this says so plainly instead of dying
 * on a failed require, which is what the original did.
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

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Whether phpseclib is available.
 *
 * Checked where it is needed rather than at load: an add-on that fatally errors
 * on activation cannot be deactivated from the dashboard, which leaves whoever
 * installed it with a site they have to fix over SSH.
 */
function wp2static_sftp_has_phpseclib() : bool {
    return class_exists( 'phpseclib\Net\SFTP' );
}
