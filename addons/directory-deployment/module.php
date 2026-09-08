<?php
/**
 * Directory Deployment — bundled module.
 *
 * This was a plugin of its own, with a header, an activation hook and a
 * `Text Domain` line. It is now loaded by the core (see WP2Static\Modules), for
 * one reason above the others: the release zip ships `src views languages
 * vendor-prefixed`, so a separately installed deployer never reached anybody
 * who installed VibeStatic from a release. The plugin could crawl and process a
 * site and then had nowhere to put it.
 *
 * The slug stays `wp2static-addon-directory-deployment` — it keys the row in
 * the add-ons table, the options table's name, and the deploy-cache namespace.
 *
 * Adopted into the fork. The original is by Adam Twardoch, released into the
 * public domain (Unlicense); its last commit, "wip further renaming" of 29
 * August 2021, left a half-finished rename with three independent faults — the
 * add-on would not activate, and if it did it would not deploy.
 *
 * @package WP2StaticDirectoryDeployer
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_PATH', __DIR__ . '/' );
define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_DIRECTORY_DEPLOYMENT_PATH . 'autoload.php';

/**
 * Load the module's translations.
 *
 * On `init`, as in the core: since WordPress 6.7, asking for a translation
 * before `after_setup_theme` triggers a `_doing_it_wrong`.
 */
function vibestatic_directory_deployment_load_textdomain() : void {
    load_plugin_textdomain(
        'vibestatic-directory-deployment',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

add_action( 'init', 'vibestatic_directory_deployment_load_textdomain' );

/*
 * How this module's options table gets created. Schema::install() calls it,
 * because that is the one place in the plugin where a table is made; as a
 * plugin it was an activation hook, which a module does not have — and which
 * never fired on an update anyway.
 */
WP2Static\Modules::registerInstaller(
    'wp2static-addon-directory-deployment',
    [ 'WP2StaticDirectoryDeployer\Controller', 'installTables' ]
);

( new WP2StaticDirectoryDeployer\Controller() )->run();
