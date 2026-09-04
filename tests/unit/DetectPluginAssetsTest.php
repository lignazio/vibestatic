<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DetectPluginAssetsTest extends TestCase {

    /**
     * @var string
     */
    private $plugins_path;

    public function setUp() : void {
        WP_Mock::setUp();

        /*
         * La cartella radice si chiama come il plugin attivo, ed è
         * deliberato: è la condizione che fa emergere il difetto. Su un sito
         * vero è il caso di chi ha l'installazione sotto un percorso che
         * contiene per caso il nome di un plugin — qui la cartella di lavoro si
         * chiamava `wp2static` e il plugin attivo pure, ed è così che i
         * quattordici file di Akismet, plugin spento, finivano in ogni deploy.
         */
        $fs = vfsStream::setup( 'attivo' );

        vfsStream::create(
            [
                'plugins' => [
                    'attivo' => [
                        'assets' => [ 'style.css' => 'body{}' ],
                        'attivo.php' => '<?php',
                    ],
                    'spento' => [
                        'assets' => [ 'segreto.css' => 'body{}' ],
                        'spento.php' => '<?php',
                    ],
                ],
            ],
            $fs
        );

        $this->plugins_path = vfsStream::url( 'attivo' ) . '/plugins';

        Mockery::mock( 'overload:\WP2Static\SiteInfo' )
            ->shouldReceive( 'getPath' )->andReturn( $this->plugins_path . '/' )
            ->shouldReceive( 'getUrl' )->andReturn( 'https://foo.com/wp-content/plugins/' );

        Mockery::mock( 'overload:\WP2Static\FilesHelper' )
            ->shouldReceive( 'filePathLooksCrawlable' )
            ->andReturnUsing(
                function ( $file ) {
                    return (bool) preg_match( '/\.css$/', (string) $file );
                }
            );

        WP_Mock::userFunction( 'is_multisite', [ 'return' => false ] );
        WP_Mock::userFunction( 'get_home_url', [ 'return' => 'https://foo.com' ] );
        WP_Mock::userFunction(
            'get_option',
            [
                'args' => 'active_plugins',
                'return' => [ 'attivo/attivo.php' ],
            ]
        );
        WP_Mock::userFunction(
            'get_option',
            [
                'args' => 'active_sitewide_plugins',
                'return' => [],
            ]
        );
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testOnlyActivePluginsAreDetected() : void {
        $detected = DetectPluginAssets::detect();

        $this->assertSame(
            [ 'https://foo.com/wp-content/plugins/attivo/assets/style.css' ],
            array_map(
                fn( $u ) => 'https://foo.com' . $u,
                $detected
            )
        );
    }

    public function testADeactivatedPluginIsNeverPublished() : void {
        /*
         * Il confronto era `str_replace( $dirs_attivi, '', $percorso ) !==
         * $percorso`, cioe' «il nome di un plugin attivo compare da qualche
         * parte nel percorso assoluto» — non «questo file sta dentro la
         * cartella di un plugin attivo». Con la radice chiamata `attivo`, come
         * qui, la vecchia condizione e' vera per ogni file di ogni plugin, e il
         * css del plugin spento passa.
         *
         * Che passi non e' un dettaglio estetico: un plugin disattivato e'
         * codice che il proprietario del sito ha deciso di non far girare, e
         * pubblicarlo lo mette a disposizione di chiunque.
         */
        foreach ( DetectPluginAssets::detect() as $url ) {
            $this->assertStringNotContainsString( 'spento', $url );
        }
    }
}
