<?php
/**
 * sFTP — bundled module.
 *
 * This was a plugin of its own, with a header and an activation hook. It is now
 * loaded by the core (see WP2Static\Modules), for one reason above the others:
 * the release zip ships `src views languages vendor-prefixed`, so a separately
 * installed deployer never reached anybody who installed VibeStatic from a
 * release. The plugin could crawl and process a site and then had nowhere to
 * put it.
 *
 * The slug stays `wp2static-addon-sftp` — it keys the row in the add-ons table,
 * the options table's name, and the deploy-cache namespace.
 *
 * Adopted into the fork. The original is by Leon Stafford, released into the
 * public domain (Unlicense), last tagged 1.0-alpha-005. It had two faults that
 * worked against each other: it never called `wp2static_register_addon`, so it
 * could never be selected as the deployer, and it hooked `wp2static_deploy`
 * asking for one argument instead of two, so it uploaded on every deploy
 * regardless. Measured before the fix: with another deployer chosen and nothing
 * to publish, merely having it active produced 1802 attempted uploads.
 *
 * @package WP2StaticSFTP
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_SFTP_PATH', __DIR__ . '/' );
define( 'WP2STATIC_SFTP_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_SFTP_PATH . 'autoload.php';

/**
 * Load the module's translations.
 *
 * On `init`, as in the core: since WordPress 6.7, asking for a translation
 * before `after_setup_theme` triggers a `_doing_it_wrong`.
 */
function vibestatic_sftp_load_textdomain() : void {
    load_plugin_textdomain(
        'vibestatic-sftp',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

add_action( 'init', 'vibestatic_sftp_load_textdomain' );

/*
 * How this module's options table gets created. Schema::install() calls it,
 * because that is the one place in the plugin where a table is made; as a
 * plugin it was an activation hook, which a module does not have — and which
 * never fired on an update anyway.
 */
WP2Static\Modules::registerInstaller(
    'wp2static-addon-sftp',
    [ 'WP2StaticSFTP\Controller', 'installTables' ]
);

( new WP2StaticSFTP\Controller() )->run();
