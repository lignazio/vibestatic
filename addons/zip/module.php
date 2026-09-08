<?php
/**
 * ZIP — bundled module.
 *
 * This was a plugin of its own. It is now loaded by the core (see
 * WP2Static\Modules), like the other bundled deployers, so that a release zip
 * arrives with somewhere to put a generated site.
 *
 * The slug stays `wp2static-addon-zip`: it keys the row in the add-ons table
 * and the value the core passes to `wp2static_deploy`.
 *
 * Adopted into the fork. The original is by Leon Stafford, released into the
 * public domain (Unlicense), last at 1.0.1. Three parts of it had never worked:
 * the "Delete ZIP" button was a fatal error every time, uninstalling called
 * `unlink()` with no arguments, and both the refresh link and the redirect
 * after deleting pointed at a page slug that does not exist.
 *
 * It registers no options table, so unlike the other modules it has nothing to
 * hand WP2Static\Modules::registerInstaller().
 *
 * @package WP2StaticZip
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_ZIP_PATH', __DIR__ . '/' );
define( 'WP2STATIC_ZIP_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_ZIP_PATH . 'autoload.php';

( new WP2StaticZip\Controller() )->run();
