<?php
/**
 * Plugin Name:       VibeStatic Add-on: Deploy over sFTP
 * Plugin URI:        https://github.com/lignazio/vibestatic
 * Description:       Uploads the generated site to a remote server over sFTP, sending only what changed.
 * Version:           2.0.0-dev
 * Requires PHP:      8.2
 * Author:            Ignazio Lucenti
 * Author URI:        https://lucenti.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibestatic-sftp
 * Domain Path:       /languages
 *
 * Adopted into the fork. The original is by Leon Stafford, released into the
 * public domain (Unlicense), last tagged 1.0-alpha-005.
 *
 * It had two faults that worked against each other. It never called
 * `wp2static_register_addon`, so it never appeared in the add-ons table and
 * could never be selected as the deployer; and it hooked `wp2static_deploy`
 * asking for one argument instead of two, so it could not see which deployer
 * had been selected and uploaded on every deploy regardless. Measured before
 * the fix: with another deployer chosen and nothing to publish, merely having
 * this add-on active produced 1802 attempted uploads and 1802 log rows.
 *
 * The slug stays `wp2static-addon-sftp`: it is the key its options table and
 * its deploy-cache namespace are tied to.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'WP2STATIC_SFTP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP2STATIC_SFTP_VERSION', '2.0.0-dev' );

require_once WP2STATIC_SFTP_PATH . 'autoload.php';

/**
 * Load the add-on's translations.
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

/**
 * Tell the user when phpseclib is missing, instead of failing silently.
 *
 * The original required `vendor/autoload.php` at the top of this file, so an
 * add-on installed without a build step took the whole site down with a fatal
 * error on activation — and a site that fatals on activation cannot be fixed
 * from the dashboard.
 */
function vibestatic_sftp_dependency_notice() : void {
    if ( wp2static_sftp_has_phpseclib() ) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__(
            'The VibeStatic sFTP add-on needs the phpseclib library. Run "composer install" in its plugin directory, or install the packaged zip.',
            'vibestatic-sftp'
        )
    );
}

add_action( 'admin_notices', 'vibestatic_sftp_dependency_notice' );

/*
 * A name of its own, not a generic one. Two of the twenty-one add-ons declare
 * the same `run_wp2static_addon_zip()` in the global namespace and fatally
 * error when both are active; this is the same hazard, avoided.
 */
function vibestatic_sftp_run() : void {
    $controller = new WP2StaticSFTP\Controller();
    $controller->run();
}

register_activation_hook(
    __FILE__,
    [ 'WP2StaticSFTP\Controller', 'activate' ]
);

register_deactivation_hook(
    __FILE__,
    [ 'WP2StaticSFTP\Controller', 'deactivate' ]
);

vibestatic_sftp_run();
