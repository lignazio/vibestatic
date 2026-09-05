<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * La coda di crawl e' la definizione di «cosa e' il sito»: quello che non c'e'
 * non viene crawlato, e quello che non viene crawlato finisce spubblicato.
 * Toglierne una riga di troppo e' l'unico modo che questo codice ha di far
 * sparire una pagina viva, quindi le due funzioni che decidono si guardano da
 * sole, senza database intorno.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class URLDetectorPruneTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testItNamesOnlyTheQueuedUrlsDetectionNoLongerClaims() : void {
        $stale = URLDetector::staleQueueEntries(
            [
                11 => '/',
                12 => '/chi-siamo/',
                13 => '/wp-content/plugins/vibestatic',
            ],
            [ '/', '/chi-siamo/' ]
        );

        // La chiave e' l'id della riga, perche' e' con quello che si cancella.
        $this->assertSame( [ 13 => '/wp-content/plugins/vibestatic' ], $stale );
    }

    public function testAnEncodedDetectedUrlMatchesItsDecodedQueueRow() : void {
        /*
         * CrawlQueueRepository::addUrls() salva `rawurldecode( $url )` nella
         * colonna `url`, mentre i rilevatori di file producono URL codificati.
         * Confrontare le due forme cosi' come sono farebbe leggere come
         * «sparito» ogni file con uno spazio o un accento nel nome — cioe' una
         * media library italiana intera, a ogni rilevazione.
         */
        $stale = URLDetector::staleQueueEntries(
            [ 21 => '/wp-content/uploads/2026/foto d\'estate.jpg' ],
            [ '/wp-content/uploads/2026/foto%20d%27estate.jpg' ]
        );

        $this->assertSame( [], $stale );
    }

    public function testNothingIsStaleWhenDetectionRepeatsItself() : void {
        $queued = [ 1 => '/', 2 => '/robots.txt', 3 => '/favicon.ico' ];

        $this->assertSame(
            [],
            URLDetector::staleQueueEntries( $queued, array_values( $queued ) )
        );
    }
}
