<?php

/**
 * Plugin Name:       VibeStatic Add-on: Deploy to local directory
 * Plugin URI:        https://github.com/lignazio/vibestatic
 * Description:       Deploys the generated site to a directory on the same machine, copying only what changed.
 * Version:           2.0.0-dev
 * Requires PHP:      8.2
 * Author:            Ignazio Lucenti
 * Author URI:        https://lucenti.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibestatic-directory-deployment
 * Domain Path:       /languages
 *
 * Its own text domain, not the core's: this is a plugin in its own right, with
 * its own header and its own directory, and whoever translates it does not
 * necessarily translate VibeStatic too.
 *
 * Adopted into the fork. The original is by Adam Twardoch, released into the
 * public domain (Unlicense); its last commit, "wip further renaming" of 29
 * August 2021, left a half-finished rename with three independent faults — the
 * add-on would not activate, and if it did it would not deploy.
 *
 * The slug stays `wp2static-addon-directory-deployment`: it is the key the
 * add-on is registered under in the add-ons table and the key its options are
 * saved under. Changing it would orphan the configuration of anyone already
 * using it.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_VERSION', '2.0.0-dev' );

require_once WP2STATIC_DIRECTORY_DEPLOYMENT_PATH . 'autoload.php';

/**
 * Load the add-on's translations.
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

function run_wp2static_addon_copy() : void {
    $controller = new WP2StaticDirectoryDeployer\Controller();
    $controller->run();
}

register_activation_hook(
    __FILE__,
    [ 'WP2StaticDirectoryDeployer\Controller', 'activate' ]
);

register_deactivation_hook(
    __FILE__,
    [ 'WP2StaticDirectoryDeployer\Controller', 'deactivate' ]
);

run_wp2static_addon_copy();

