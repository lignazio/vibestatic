<?php
/**
 * Netlify — bundled module.
 *
 * The slug stays `wp2static-addon-netlify`: it keys the row in the add-ons
 * table and the name of the options table.
 *
 * Adopted into the fork. The original is by Leon Stafford, released into the
 * public domain (Unlicense).
 *
 * @package WP2StaticNetlify
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_NETLIFY_PATH', __DIR__ . '/' );
define( 'WP2STATIC_NETLIFY_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_NETLIFY_PATH . 'autoload.php';

/*
 * How this module's options table gets created. Schema::install() calls it,
 * because that is the one place in the plugin where a table is made.
 */
WP2Static\Modules::registerInstaller(
    'wp2static-addon-netlify',
    [ 'WP2StaticNetlify\Controller', 'installTables' ]
);

WP2StaticNetlify\Controller::boot();
