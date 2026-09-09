<?php
/**
 * `WP2Static\Addon\Updater`, with nothing in it — the wordpress.org package's
 * copy, put in place of the real one by `tools/build_release.sh --wporg`.
 *
 * The real class lists a GitHub repository's releases and offers each add-on
 * its own. Guideline 8 of the plugin directory forbids exactly that to a plugin
 * hosted there: "serving updates or otherwise installing plugins, themes, or
 * add-ons from servers other than WordPress.org's". So it cannot ship.
 *
 * Deleting the file outright was the first attempt, and testing the package
 * showed what that costs: every add-on published at 1.0.0 calls
 * `Addon\Updater::register()` from its own `plugins_loaded`, and a missing class
 * there is `Uncaught Error: Class "WP2Static\Addon\Updater" not found` — a white
 * screen, during `plugins_loaded`, with no admin left to fix it from. Ten
 * add-ons, one for each, on any site that installed the core from the
 * directory.
 *
 * Hence a shim rather than a hole. From 1.1.0 an add-on carries an Updater of
 * its own and never calls this; this exists for the ones already installed, and
 * it answers by doing nothing at all. It makes no request, registers no filter
 * and serves no update — there is nothing here for guideline 8 to be about.
 *
 * @package WP2Static
 */

namespace WP2Static\Addon;

class Updater {

    /**
     * Accept the call an add-on at 1.0.0 makes, and do nothing with it.
     *
     * @param string $plugin_file The add-on's main file. Unused.
     * @param string $prefix      Its release tag prefix. Unused.
     * @param string $name        What it calls itself. Unused.
     */
    public static function register( string $plugin_file, string $prefix, string $name ) : void {
    }

    /**
     * For the tests of the add-ons, which reset the registry between cases.
     */
    public static function reset() : void {
    }
}
