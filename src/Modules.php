<?php
/**
 * The add-ons the plugin ships with, loaded as modules rather than installed as
 * plugins of their own.
 *
 * **The reason is not tidiness: the release zip could not publish anything.**
 * `tools/build_release.sh` packages `src views languages vendor-prefixed`, and
 * the adopted add-ons were separate plugins living outside that list. Whoever
 * installed VibeStatic from the zip could crawl a site, process it, and then
 * had nowhere to put it. That is not a packaging preference, it is an
 * incomplete release.
 *
 * Nothing here changes what a third-party add-on can call. The thirty hooks,
 * the eight tables, the `WP2Static\` namespace, the page slugs and the
 * `admin_post_wp2static_*` actions are untouched: `wp2static_register_addon` is
 * still how an add-on announces itself, and a module announces itself the same
 * way. This is about how *ours* are installed, not about how somebody else's
 * hooks in.
 *
 * @package WP2Static
 */

namespace WP2Static;

class Modules {

    /**
     * @var bool Whether boot() has already run in this request.
     */
    private static $booted = false;

    /**
     * @var string[] Slugs found already loaded by somebody else.
     */
    private static $superseded = [];

    /**
     * @var array<string, callable> Slug => the module's table installer.
     */
    private static $installers = [];

    /**
     * Slug => directory under `addons/`.
     *
     * The slug is the one the add-on has always had, and it is not ours to
     * change: it keys the row in `wp2static_addons`, the name of the options
     * table, and the deploy-cache namespace. Renaming it would disconnect an
     * existing installation from its own deploy cache, which on the next deploy
     * means re-uploading the whole site.
     *
     * @return array<string, string>
     */
    public static function available() : array {
        return [
            'wp2static-addon-directory-deployment' => 'directory-deployment',
            'wp2static-addon-sftp' => 'sftp',
        ];
    }

    /**
     * Absolute path of a module's directory, with a trailing slash.
     */
    public static function path( string $directory ) : string {
        return VIBESTATIC_PATH . 'addons/' . $directory . '/';
    }

    /**
     * Load every bundled module.
     *
     * **Only in the admin, on the command line, or during cron**, and that is a
     * deliberate change rather than an inherited one. Each module announces
     * itself with `wp2static_register_addon`, which is a database write; as
     * separate plugins they did it on every request the site served, front end
     * included, and bundling six deployers would have multiplied that by six.
     * A deployer has nothing to do on a page view: deploys are started from the
     * admin, from WP-CLI, or from WP-Cron, and all three are covered here.
     *
     * On `plugins_loaded` rather than at file scope, because the guard below
     * needs the other plugins to have been loaded already: plugin files are
     * included in alphabetical order, so `vibestatic` runs before
     * `wp2static-addon-*`, and a check made at our own load time would always
     * find nothing.
     */
    public static function load() : void {
        if ( ! is_admin()
            && ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) )
            && ! wp_doing_cron()
        ) {
            return;
        }

        self::boot();

        if ( ! self::$superseded ) {
            return;
        }

        add_action( 'admin_notices', [ self::class, 'renderSupersededNotice' ] );
    }

    /**
     * Include every bundled module, once.
     *
     * Separate from load() and without its gate, because activation does not
     * go through load() at all: `register_activation_hook` fires in a request
     * where `plugins_loaded` has already passed, so the modules would not be
     * in memory and Schema::install() would create the core's tables and none
     * of theirs — silently, and only once, since install() then records the
     * version as done.
     */
    public static function boot() : void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        foreach ( self::available() as $slug => $directory ) {
            /*
             * Already loaded means somebody has an older copy of this add-on
             * installed as a plugin in its own right. Loading it a second time
             * would register every hook twice — and for a deployer that means
             * deploying twice, once per registration, which is the kind of
             * fault that looks like a slow deploy rather than like a bug.
             */
            if ( self::isAlreadyLoaded( $directory ) ) {
                self::$superseded[] = $slug;

                continue;
            }

            $module = self::path( $directory ) . 'module.php';

            if ( ! is_readable( $module ) ) {
                continue;
            }

            require_once $module;
        }
    }

    /**
     * Create the modules' options tables.
     *
     * Called from Schema::install(), which is where every table in this project
     * is created. As plugins they each did this in their own
     * `register_activation_hook`, which a module never gets — and which never
     * fired on an update anyway, the reason Schema exists at all.
     *
     * Multisite is handled by the caller: `Controller::activate()` already
     * loops over the network's sites, so the loop each add-on carried a copy of
     * — the one with the blog-id query built by `sprintf` instead of
     * `prepare` — is gone rather than duplicated a further four times.
     */
    public static function installTables() : void {
        self::boot();

        foreach ( self::$installers as $installer ) {
            $installer();
        }
    }

    /**
     * A module says how its tables are created.
     *
     * Called from `module.php`, so a module that did not load registers
     * nothing and installTables() simply has one less thing to do. The
     * alternative — a table of class names here in the core — meant naming
     * every module twice and asking `is_callable()` a question that is always
     * true from where the analysis stands and not at all always true at run
     * time.
     */
    public static function registerInstaller( string $slug, callable $installer ) : void {
        self::$installers[ $slug ] = $installer;
    }

    /**
     * The tables to drop when the plugin is uninstalled.
     *
     * `uninstall.php` runs without the autoloader, so it cannot ask a module
     * for its own name: the list is here, next to the modules it describes, and
     * copied by hand into the one place that cannot read it.
     *
     * @return string[] Table names without the site prefix.
     */
    public static function tables() : array {
        return [
            'wp2static_addon_directory_deployment_options',
            'wp2static_addon_sftp_options',
        ];
    }

    /**
     * Whether a module's code is already in memory, put there by someone else.
     */
    private static function isAlreadyLoaded( string $directory ) : bool {
        switch ( $directory ) {
            case 'directory-deployment':
                return class_exists( 'WP2StaticDirectoryDeployer\Controller', false );
            case 'sftp':
                return class_exists( 'WP2StaticSFTP\Controller', false );
            default:
                return false;
        }
    }

    /**
     * Say that a separately installed copy is shadowing a bundled module.
     *
     * It does not deactivate anything by itself: the copy may be somebody
     * else's build of the same add-on rather than an older one of ours, and
     * switching off a plugin the user installed is their decision, not a side
     * effect of updating this one.
     */
    public static function renderSupersededNotice() : void {
        $superseded = self::$superseded;

        if ( ! $superseded ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %s: comma-separated list of add-on slugs. */
                    _n(
                        'VibeStatic now ships this add-on itself: %s. The separately installed copy is the one running — deactivate it, so the bundled one takes over.',
                        'VibeStatic now ships these add-ons itself: %s. The separately installed copies are the ones running — deactivate them, so the bundled ones take over.',
                        count( $superseded ),
                        'vibestatic'
                    ),
                    implode( ', ', $superseded )
                )
            )
        );
    }
}
