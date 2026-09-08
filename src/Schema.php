<?php
/**
 * Creating and updating the tables.
 *
 * Tables used to be created only inside `register_activation_hook`, which fires
 * on activation and never again. Updating the plugin — from the WordPress
 * dashboard, from Composer, by replacing the files — does not fire that hook: a
 * column added in a new version would never reach sites that already had the
 * plugin, and the new code would query an old table. It is also why the
 * createTable() methods carry the patches that drop obsolete columns and
 * recreate indexes: that was the only place they could ever run.
 *
 * Now there is a version number. When it changes, the tables are rebuilt —
 * dbDelta is idempotent, so rebuilding an already-current schema costs nothing
 * and does not touch the data.
 *
 * @package WP2Static
 */

namespace WP2Static;

class Schema {

    /**
     * Bump this by one whenever a table definition changes.
     *
     * Forget to, and the change only reaches fresh installations, so the defect
     * shows up much later and somewhere else.
     *
     * 2 — the `removeWordPressCruft` option. Not a new column: a row that
     *     `seedOptions()` has to insert on sites that already have the plugin.
     * 3 — the bundled modules' options tables. They used to be created by each
     *     add-on's own activation hook; a module does not have one.
     */
    const VERSION = 3;

    /**
     * @var string Where the applied version is remembered.
     */
    const OPTION = 'vibestatic_schema_version';

    /**
     * Create or update every table, and record the version.
     */
    public static function install() : void {
        WsLog::createTable();
        CoreOptions::init();
        CrawlCache::createTable();
        CrawlQueue::createTable();
        DeployCache::createTable();
        JobQueue::createTable();
        Addons::createTable();

        /*
         * The bundled modules' own tables. They are only reachable once the
         * modules have been loaded — `plugins_loaded`, so before the `init`
         * this runs on — and Modules::installTables() skips whatever is not
         * there rather than fataling, which is what happens if the plugin is
         * activated with the addons/ directory missing from a partial install.
         */
        Modules::installTables();

        update_option( self::OPTION, (string) self::VERSION, false );
    }

    /**
     * True when the schema in place is not the one this code expects.
     */
    public static function needsUpdate() : bool {
        return get_option( self::OPTION ) !== (string) self::VERSION;
    }

    /**
     * Update the schema if it needs it.
     *
     * Runs on `init`, but only in the admin and from the command line: the
     * comparison costs one read of an autoloaded option, which is nothing, but
     * dbDelta is not — and letting a random visitor's request trigger it means
     * making that visitor pay for the upgrade.
     */
    public static function updateIfNeeded() : void {
        if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
            return;
        }

        if ( ! self::needsUpdate() ) {
            return;
        }

        WsLog::l(
            'Updating database schema to version ' . self::VERSION
        );

        self::install();
    }
}
