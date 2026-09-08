<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * The bundled modules' loader.
 *
 * Two of its rules are not obvious from reading it, and both have a cost if
 * they break: it must not load anything on a front-end request — each module
 * announces itself with a database write, and as separate plugins they did that
 * on every page a visitor asked for — and it must not load a module a second
 * time when an older copy is already installed as a plugin, because for a
 * deployer a second registration means deploying twice.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ModulesTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();

        /*
         * The two constants the plugin file defines. A module is only ever
         * loaded by the core, so in a real request they are already there;
         * here the loader is exercised on its own and has to be given them.
         */
        if ( ! defined( 'VIBESTATIC_PATH' ) ) {
            define( 'VIBESTATIC_PATH', dirname( __DIR__, 2 ) . '/' );
        }

        if ( ! defined( 'VIBESTATIC_VERSION' ) ) {
            define( 'VIBESTATIC_VERSION', '8.0.0' );
        }
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * Every module named in the map is on disk, with the file the loader
     * requires.
     *
     * It reads as a triviality and is not: the map is what the release ships,
     * so a slug left behind after a rename is a deployer that silently is not
     * there.
     */
    public function testEveryDeclaredModuleExistsOnDisk() : void {
        $this->assertNotEmpty( Modules::available() );

        foreach ( Modules::available() as $slug => $directory ) {
            $this->assertFileExists(
                Modules::path( $directory ) . 'module.php',
                "$slug has no module.php"
            );
        }
    }

    /**
     * The slug is the key of the add-ons row, the options table's name and the
     * deploy-cache namespace. `wp2static-`, not `vibestatic-`: renaming it
     * would disconnect an installation from its own deploy cache, and the next
     * deploy would re-upload the whole site.
     */
    public function testSlugsKeepTheirHistoricalPrefix() : void {
        foreach ( array_keys( Modules::available() ) as $slug ) {
            $this->assertStringStartsWith( 'wp2static-addon-', $slug );
        }
    }

    /**
     * A visitor's page view loads nothing.
     */
    public function testItLoadsNothingOnAFrontEndRequest() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => false ] );
        WP_Mock::userFunction( 'wp_doing_cron', [ 'return' => false ] );
        WP_Mock::userFunction( 'add_action', [ 'times' => 0 ] );

        Modules::load();

        $this->assertFalse( class_exists( 'WP2StaticSFTP\Controller', false ) );
        $this->assertFalse(
            class_exists( 'WP2StaticDirectoryDeployer\Controller', false )
        );
    }

    /**
     * WP-Cron is not the admin, and it is where a scheduled deploy runs: gating
     * on is_admin() alone would leave the scheduled deploy without a deployer.
     */
    public function testItLoadsDuringCron() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => false ] );
        WP_Mock::userFunction( 'wp_doing_cron', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_action', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_filter', [ 'return' => true ] );
        WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
        WP_Mock::userFunction( 'load_plugin_textdomain', [ 'return' => true ] );
        WP_Mock::userFunction( 'plugin_basename', [ 'return' => 'vibestatic' ] );

        Mockery::mock( 'alias:WP2Static\WsLog' )->shouldReceive( 'l' );

        Modules::load();

        $this->assertTrue( class_exists( 'WP2StaticSFTP\Controller', false ) );
        $this->assertTrue(
            class_exists( 'WP2StaticDirectoryDeployer\Controller', false )
        );
    }

    /**
     * A module that has tables says how they are made, so Schema has something
     * to call — and one that has none registers nothing.
     *
     * Both halves matter. A module with settings and no installer is one whose
     * options table never gets created, and that surfaces much later as a
     * deployer that cannot save anything; a module with no settings at all —
     * ZIP has none — must not be forced to pretend otherwise.
     */
    public function testEachLoadedModuleRegistersATableInstaller() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => true ] );
        WP_Mock::userFunction( 'wp_doing_cron', [ 'return' => false ] );
        WP_Mock::userFunction( 'add_action', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_filter', [ 'return' => true ] );
        WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
        WP_Mock::userFunction( 'load_plugin_textdomain', [ 'return' => true ] );
        WP_Mock::userFunction( 'plugin_basename', [ 'return' => 'vibestatic' ] );

        Mockery::mock( 'alias:WP2Static\WsLog' )->shouldReceive( 'l' );

        Modules::load();

        $registered = new \ReflectionProperty( Modules::class, 'installers' );
        $registered->setAccessible( true );

        /** @var array<string, callable> $installers */
        $installers = $registered->getValue();

        $this->assertNotEmpty( $installers );

        foreach ( $installers as $slug => $installer ) {
            $this->assertArrayHasKey(
                $slug,
                Modules::available(),
                "$slug registered an installer but is not a known module"
            );
            $this->assertIsCallable( $installer, "$slug registered a non-callable" );
        }
    }

    /**
     * The baseline does not hide a hook pointing at a method that is not there.
     *
     * This is here because it happened. A method was removed while the
     * `add_filter` naming it stayed, and nothing said so until WordPress
     * reached that filter and stopped the whole admin with "class does not have
     * a method addSubmenuPage" — PHP checks a callback when it is called, not
     * when it is registered.
     *
     * PHPStan does catch it, as `Parameter #2 $callback of function add_filter
     * expects callable(): mixed, array{...} given`. What it could not do was
     * report it, because an entry of exactly that shape was sitting in the
     * baseline: the suppression written for a noisy stub signature was also
     * suppressing the one case where the message means something. So the rule
     * is not "keep the callables tidy", it is "this error never goes back into
     * the baseline" — and a baseline is regenerated by a command, so the rule
     * has to be checked by one too.
     */
    public function testTheBaselineDoesNotHideBrokenHookCallbacks() : void {
        $baseline = file_get_contents( VIBESTATIC_PATH . 'phpstan-baseline.neon' );

        $this->assertIsString( $baseline );

        $this->assertStringNotContainsString(
            'callback of function add_',
            $baseline,
            'A suppressed add_action/add_filter callable is also a suppressed missing method.'
        );
    }

    /**
     * The tables uninstall.php drops are the ones the modules create.
     *
     * uninstall.php runs without the autoloader, so it cannot ask for this list
     * and carries a copy of it. Two lists kept in step by hand is exactly the
     * arrangement that drifts, so it is checked here: what drifts is the sFTP
     * host, username and password surviving an uninstall.
     */
    public function testUninstallDropsEveryModuleTable() : void {
        $uninstall = file_get_contents( VIBESTATIC_PATH . 'uninstall.php' );

        $this->assertIsString( $uninstall );

        foreach ( Modules::tables() as $table ) {
            $this->assertStringContainsString(
                "'$table'",
                $uninstall,
                "uninstall.php does not drop $table"
            );
        }
    }
}
