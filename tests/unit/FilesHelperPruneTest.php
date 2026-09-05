<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * La potatura e' l'unica cosa in questo plugin che cancella file di un sito
 * pubblicato. Ogni test qui sotto descrive un modo di sbagliarla, e il modo
 * peggiore non e' tenere un file di troppo: e' cancellarne uno che serviva.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FilesHelperPruneTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();

        Mockery::mock( 'overload:\WP2Static\WsLog' )
            ->shouldReceive( 'l' )->andReturnNull()
            ->shouldReceive( 'w' )->andReturnNull();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * Tredici file, perche' la sicura sulla quota si misura su quanti ce ne
     * sono: con un albero piccolo ogni cancellazione legittima varrebbe da
     * sola piu' di meta' della cartella, e ogni test finirebbe per esercitare
     * il rifiuto invece di quello che vuole misurare.
     */
    private function tree() : string {
        $vfs = vfsStream::setup( 'root' );

        $lavori = [];

        foreach ( range( 1, 8 ) as $n ) {
            $lavori[ "progetto-$n" ] = [ 'index.html' => "progetto $n" ];
        }

        vfsStream::create(
            [
                'index.html' => 'home',
                'chi-siamo' => [ 'index.html' => 'chi siamo' ],
                'lavori' => $lavori,
                'archivio' => [ '2019' => [ 'gennaio' => [ 'index.html' => 'vecchio' ] ] ],
                'file con spazi e accènti.txt' => 'ciao',
            ],
            $vfs
        );

        return $vfs->url();
    }

    /**
     * Tutti i percorsi dell'albero, per costruire un elenco «tieni tutto
     * tranne questi» senza riscriverlo a mano a ogni test.
     *
     * @param string[] $without Percorsi da togliere dall'elenco.
     * @return string[]
     */
    private function allPathsExcept( array $without ) : array {
        $all = [
            '/index.html',
            '/chi-siamo/index.html',
            '/archivio/2019/gennaio/index.html',
            '/file con spazi e accènti.txt',
        ];

        foreach ( range( 1, 8 ) as $n ) {
            $all[] = "/lavori/progetto-$n/index.html";
        }

        return array_values( array_diff( $all, $without ) );
    }

    public function testItRemovesOnlyWhatIsNotInTheList() : void {
        $dir = $this->tree();

        $removed = FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept( [ '/lavori/progetto-2/index.html' ] )
        );

        $this->assertSame( [ '/lavori/progetto-2/index.html' ], $removed );
        $this->assertFileDoesNotExist( $dir . '/lavori/progetto-2/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-1/index.html' );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testAnEmptyListRemovesNothing() : void {
        $dir = $this->tree();

        /*
         * E' la sicura piu' importante di tutte. Un elenco vuoto arriva da una
         * coda mai riempita o da un crawl mai fatto — «non lo so» — e leggerlo
         * come «il sito e' vuoto» cancellerebbe tutto. Chi vuole davvero
         * svuotare ha StaticSite::delete().
         */
        $this->assertSame( [], FilesHelper::removePathsNotIn( $dir, [] ) );
        $this->assertFileExists( $dir . '/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-2/index.html' );
    }

    public function testItRefusesAPruneThatWouldTakeMostOfTheDirectory() : void {
        $dir = $this->tree();

        /*
         * Dodici file su tredici. La rilevazione ha gia' una sicura sulla coda,
         * ma la coda puo' accorciarsi anche per vie che non passano di li' —
         * svuotata a mano dalla pagina Caches e poi riempita solo in parte.
         * Questa e' la funzione che cancella davvero, ed e' l'ultimo punto in
         * cui ci si puo' ancora fermare.
         */
        $removed = FilesHelper::removePathsNotIn( $dir, [ '/index.html' ] );

        $this->assertSame( [], $removed );
        $this->assertFileExists( $dir . '/archivio/2019/gennaio/index.html' );
        $this->assertFileExists( $dir . '/lavori/progetto-1/index.html' );
        $this->assertFileExists( $dir . '/chi-siamo/index.html' );
    }

    public function testItRemovesTheDirectoriesLeftEmpty() : void {
        $dir = $this->tree();

        FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept(
                [
                    '/chi-siamo/index.html',
                    '/lavori/progetto-1/index.html',
                    '/lavori/progetto-2/index.html',
                    '/lavori/progetto-3/index.html',
                ]
            )
        );

        // Una cartella vuota rimasta in giro diventa, su un server che elenca
        // le directory, una pagina vuota indicizzabile.
        $this->assertDirectoryDoesNotExist( $dir . '/chi-siamo' );
        $this->assertDirectoryDoesNotExist( $dir . '/lavori/progetto-1' );

        // Ma non quella che ha ancora qualcosa dentro.
        $this->assertDirectoryExists( $dir . '/lavori' );
        $this->assertFileExists( $dir . '/lavori/progetto-4/index.html' );
    }

    public function testItRemovesAParentThatEmptiedOnlyBecauseItsChildDid() : void {
        $dir = $this->tree();

        /*
         * Un file solo, in fondo a tre cartelle annidate. Fra i genitori
         * diretti del file cancellato c'e' solo `gennaio`: `2019` e `archivio`
         * si svuotano perche' si e' svuotato quello che contenevano, e vanno
         * raggiunti risalendo.
         */
        FilesHelper::removePathsNotIn(
            $dir,
            $this->allPathsExcept( [ '/archivio/2019/gennaio/index.html' ] )
        );

        $this->assertDirectoryDoesNotExist( $dir . '/archivio/2019/gennaio' );
        $this->assertDirectoryDoesNotExist( $dir . '/archivio/2019' );
        $this->assertDirectoryDoesNotExist( $dir . '/archivio' );

        // La radice no: quella e' la cartella, non il suo contenuto.
        $this->assertDirectoryExists( $dir );
    }

    public function testItDoesNothingOnADirectoryThatIsNotThere() : void {
        $dir = $this->tree();

        $this->assertSame(
            [],
            FilesHelper::removePathsNotIn( $dir . '/mai-esistita', [ '/index.html' ] )
        );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testATrailingSlashOnTheRootDoesNotShiftEveryPath() : void {
        $dir = $this->tree();

        // Chi chiama passa il valore di getPath(), che un filtro puo' aver
        // restituito con la barra finale: senza rtrim, ogni percorso letto
        // perderebbe la prima lettera e nessuno corrisponderebbe piu'.
        $removed = FilesHelper::removePathsNotIn( $dir . '/', $this->allPathsExcept( [] ) );

        $this->assertSame( [], $removed );
        $this->assertFileExists( $dir . '/index.html' );
    }

    public function testPruningIsOnByDefaultAndCanBeTurnedOff() : void {
        WP_Mock::onFilter( 'wp2static_prune_stale_files' )->with( true )->reply( true );
        $this->assertTrue( FilesHelper::pruningEnabled() );

        WP_Mock::onFilter( 'wp2static_prune_stale_files' )->with( true )->reply( false );
        $this->assertFalse( FilesHelper::pruningEnabled() );
    }

    public function testASmallShrinkIsBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 18, 1813 ) );
    }

    public function testHalfIsStillBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        // La soglia e' inclusiva: meta' esatta e' ancora un sito che si e'
        // dimezzato, non necessariamente un passo rotto.
        $this->assertTrue( FilesHelper::shrinkIsPlausible( 50, 100 ) );
    }

    public function testAWholesaleDisappearanceIsNotBelieved() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        /*
         * E' la forma che ha un guasto a monte: la sitemap che non risponde, il
         * post type non ancora registrato quando il job parte. Da qui non si
         * distingue da un sito davvero svuotato, quindi non si sceglie — e non
         * scegliere vuol dire non cancellare.
         */
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }

    public function testNothingIsNeverPlausible() : void {
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 0.5 );

        // Nessuna divisione per zero, e nessuna decisione presa su niente.
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 0, 0 ) );
    }

    public function testTheThresholdCanBeRaisedByFilter() : void {
        // Chi sa cosa sta facendo — una migrazione, un sito che si e' davvero
        // svuotato — puo' portarla a 1 e togliere la sicura del tutto.
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 1.0 );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }

    public function testAFilterThatReturnsNonsenseFallsBackToTheDefault() : void {
        // apply_filters() restituisce quello che decide chi lo aggancia: una
        // stringa castata a float darebbe 0.0, cioe' «non togliere mai
        // niente», e lo farebbe senza dirlo.
        WP_Mock::onFilter( 'wp2static_max_stale_fraction' )->with( 0.5 )->reply( 'meta' );

        $this->assertTrue( FilesHelper::shrinkIsPlausible( 18, 1813 ) );
        $this->assertFalse( FilesHelper::shrinkIsPlausible( 1800, 1813 ) );
    }
}
