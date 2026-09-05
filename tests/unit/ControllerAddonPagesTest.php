<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Sta al posto del Controller di un addon: `is_callable()` su
 * `[ 'Classe', 'metodo' ]` e' vero solo se la classe esiste davvero, ed e'
 * proprio il controllo che si vuole verificare.
 */
class FixtureAddonController {

    public static function renderPage() : void {
    }

    public static function renderOtherPage() : void {
    }
}

/**
 * Le pagine che gli addon chiedono di aggiungere.
 *
 * `wp2static_add_menu_items` e' morto nel core il 9 maggio 2020 (commit
 * 0b1db4e3) e nessuno l'ha piu' lanciato, mentre sftp, s3 e netlify hanno
 * continuato a registrarcisi: quei tre addon si installano, si attivano, si
 * agganciano al deploy e non hanno nessun posto dove mettere le credenziali.
 *
 * Quello che torna dal filtro lo decide un addon di terzi, quindi puo' essere
 * qualunque cosa: questi test coprono i casi in cui non e' quello che il
 * contratto prometteva.
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
     * @param mixed $filter_result Quello che restituiscono gli addon.
     * @return list<array<int, mixed>> Le chiamate ad add_submenu_page().
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
        // Lo slug è `wp2static-<chiave>`: è il contratto di allora, e quello
        // che gli addon si aspettano ancora.
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
     * Un addon che restituisce qualcosa che non è un array non deve poter
     * spegnere il menu di tutto il plugin.
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
}
