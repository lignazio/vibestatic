<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Stands in for an add-on's Controller: `is_callable()` on
 * `[ 'Class', 'method' ]` is only true if the class really exists, and that is
 * precisely the check being verified.
 */
class FixtureAddonController {

    public static function renderPage() : void {
    }

    public static function renderOtherPage() : void {
    }
}

/**
 * The pages add-ons ask to have registered.
 *
 * `wp2static_add_menu_items` died in the core on 9 May 2020 (commit 0b1db4e3)
 * and nothing fired it again, while sftp, s3 and netlify kept registering on
 * it: those three add-ons install, activate, hook into the deploy, and have
 * nowhere to put their credentials.
 *
 * What comes back from the filter is decided by a third-party add-on, so it can
 * be anything: these tests cover the cases where it is not what the contract
 * promised.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ControllerAddonPagesTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param mixed $filter_result What the add-ons return.
     * @return list<array<int, mixed>> The calls made to add_submenu_page().
     */
    private function pagesRegisteredWhenAddonsReturn( $filter_result ) : array {
        WP_Mock::onFilter( 'wp2static_add_menu_items' )
            ->with( [] )
            ->reply( $filter_result );

        WP_Mock::userFunction( '__', [ 'return' => function ( $text ) {
            return $text;
        } ] );

        $registered = [];
        WP_Mock::userFunction( 'add_submenu_page', [
            'return' => function ( ...$args ) use ( &$registered ) {
                $registered[] = $args;
                return '';
            },
        ] );

        Controller::registerAddonPages();

        return $registered;
    }

    public function testAnAddonPageIsRegisteredUnderTheExpectedSlug() : void {
        $pages = $this->pagesRegisteredWhenAddonsReturn(
            [ 'sftp' => [ FixtureAddonController::class, 'renderPage' ] ]
        );

        $this->assertCount( 1, $pages );
        // The slug is `wp2static-<key>`: the contract from back then, and what
        // add-ons still expect.
        $this->assertSame( 'wp2static-sftp', $pages[0][4] );
        $this->assertSame( 'wp2static', $pages[0][0] );
        $this->assertSame( 'manage_options', $pages[0][3] );
    }

    public function testSeveralAddonsEachGetTheirPage() : void {
        $pages = $this->pagesRegisteredWhenAddonsReturn(
            [
                'sftp' => [ FixtureAddonController::class, 'renderPage' ],
                's3' => [ FixtureAddonController::class, 'renderOtherPage' ],
            ]
        );

        $this->assertSame(
            [ 'wp2static-sftp', 'wp2static-s3' ],
            [ $pages[0][4], $pages[1][4] ]
        );
    }

    /**
     * An add-on returning something that is not an array must not be able to
     * take down the whole plugin's menu.
     */
    public function testSomethingThatIsNotAnArrayIsIgnored() : void {
        $this->assertSame( [], $this->pagesRegisteredWhenAddonsReturn( 'niente' ) );
    }

    public function testAnEntryThatIsNotCallableIsSkipped() : void {
        $pages = $this->pagesRegisteredWhenAddonsReturn(
            [
                'rotto' => 'WP2StaticNonEsiste\Controller::metodoCheNonCe',
                'sftp' => [ FixtureAddonController::class, 'renderPage' ],
            ]
        );

        $this->assertCount( 1, $pages );
        $this->assertSame( 'wp2static-sftp', $pages[0][4] );
    }

    public function testNoAddonsMeansNoPages() : void {
        $this->assertSame( [], $this->pagesRegisteredWhenAddonsReturn( [] ) );
    }

    /**
     * Pretend WordPress has registered exactly these pages.
     *
     * `$_registered_pages` is keyed by hookname, which is what
     * `add_submenu_page()` writes and what `wp-admin/admin.php` reads before it
     * decides to answer "Sorry, you are not allowed to access this page".
     *
     * @param array<string, string> $pages page slug => parent slug.
     */
    private function registerPages( array $pages ) : void {
        WP_Mock::userFunction( 'get_plugin_page_hookname', [
            'return' => function ( $page, $parent ) {
                return ( '' === $parent ? 'admin_page_' : $parent . '_page_' ) . $page;
            },
        ] );

        $GLOBALS['_registered_pages'] = [];

        foreach ( $pages as $page => $parent ) {
            $hook = ( '' === $parent ? 'admin_page_' : $parent . '_page_' ) . $page;
            $GLOBALS['_registered_pages'][ $hook ] = true;
        }
    }

    /**
     * The `wp2static_add_menu_items` convention: the page is the slug with
     * `wp2static-addon-` taken off and `wp2static-` put back on.
     */
    public function testTheConfigureLinkFindsAHookRegisteredPage() : void {
        $this->registerPages( [ 'wp2static-netlify' => 'wp2static' ] );

        $this->assertSame(
            'wp2static-netlify',
            Controller::addonSettingsPage( 'wp2static-addon-netlify' )
        );
    }

    /**
     * The hidden-page convention, which directory-deployment and zip use: the
     * page is the whole slug. The first attempt at this fix transformed the
     * slug unconditionally and so left these two still pointing at a page that
     * does not exist.
     */
    public function testTheConfigureLinkFindsAHiddenPageUnderTheWholeSlug() : void {
        $this->registerPages( [ 'wp2static-addon-zip' => '' ] );

        $this->assertSame(
            'wp2static-addon-zip',
            Controller::addonSettingsPage( 'wp2static-addon-zip' )
        );
    }

    /**
     * No page registered, no link. A gear leading to an error page is worse
     * than no gear, and a third-party add-on need not have a settings page at
     * all.
     */
    public function testAnAddonWithNoPageGetsNoLink() : void {
        $this->registerPages( [ 'wp2static-netlify' => 'wp2static' ] );

        $this->assertNull( Controller::addonSettingsPage( 'wp2static-addon-bunnycdn' ) );
    }

    /**
     * With both registered the hidden page wins, because that is the one the
     * add-on asked for by its own name.
     */
    public function testTheWholeSlugIsPreferredWhenBothExist() : void {
        $this->registerPages( [
            'wp2static-addon-zip' => '',
            'wp2static-zip' => 'wp2static',
        ] );

        $this->assertSame(
            'wp2static-addon-zip',
            Controller::addonSettingsPage( 'wp2static-addon-zip' )
        );
    }

    /**
     * A slug that does not start with `wp2static-addon-` is left alone rather
     * than mangled: the only candidates are itself and `wp2static-` in front.
     */
    public function testASlugWithoutTheUsualPrefixIsNotMangled() : void {
        $this->registerPages( [ 'strano' => '' ] );

        $this->assertSame( 'strano', Controller::addonSettingsPage( 'strano' ) );
    }

    /**
     * Before `admin_menu` has run there is no register at all. Reading a global
     * that is not there must answer "no page", not warn.
     */
    public function testNoRegisterAtAllMeansNoPage() : void {
        WP_Mock::userFunction( 'get_plugin_page_hookname', [
            'return' => function ( $page, $parent ) {
                return ( '' === $parent ? 'admin_page_' : $parent . '_page_' ) . $page;
            },
        ] );

        unset( $GLOBALS['_registered_pages'] );

        $this->assertNull( Controller::addonSettingsPage( 'wp2static-addon-netlify' ) );
    }
}
