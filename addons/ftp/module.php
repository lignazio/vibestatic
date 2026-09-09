<?php
/**
 * FTP — bundled module.
 *
 * Written from scratch rather than adopted. The upstream add-on could not be
 * repaired: it is built against the pre-7.x add-on API — five hooks, none of
 * which the core still fires, no `wp2static_register_addon`, no
 * `wp2static_deploy` — and its main class extends a `SitePublisher` that does
 * not exist, while `FTPClient implements Countable` with a three-parameter
 * `count()` that is a fatal error the moment the class is linked. It also read
 * `$_POST['ajax_action']` at file scope on every front-end request, with no
 * nonce and no capability, to open FTP connections with the stored
 * credentials. There was nothing there to keep.
 *
 * The slug stays `wp2static-addon-ftp`: it keys the row in the add-ons table,
 * the options table's name and the deploy-cache namespace.
 *
 * @package WP2StaticFTP
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_FTP_PATH', __DIR__ . '/' );
define( 'WP2STATIC_FTP_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_FTP_PATH . 'autoload.php';

WP2Static\Modules::registerInstaller(
    'wp2static-addon-ftp',
    [ 'WP2StaticFTP\Controller', 'installTables' ]
);

WP2StaticFTP\Controller::boot();
