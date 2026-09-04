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
 *
 * Adottato nel fork. L'originale e` di Adam Twardoch, rilasciato nel pubblico
 * dominio (Unlicense); il suo ultimo commit, «wip further renaming» del 29
 * agosto 2021, aveva lasciato un rinominamento a meta` con tre guasti
 * indipendenti — l'addon non si attivava, e se si attivava non deployava.
 *
 * Lo slug resta `wp2static-addon-directory-deployment`: e` la chiave con cui
 * l'addon e` registrato nella tabella degli addon e con cui sono salvate le sue
 * opzioni. Cambiarlo orfanerebbe la configurazione di chi lo usa gia`.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP2STATIC_DIRECTORY_DEPLOYMENT_VERSION', '2.0.0-dev' );

require_once WP2STATIC_DIRECTORY_DEPLOYMENT_PATH . 'autoload.php';

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

