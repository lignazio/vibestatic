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
         * The root directory is named after the active plugin, deliberately: it
         * is the condition that surfaces the defect. On a real site it is the
         * case of an installation living under a path that happens to contain a
         * plugin's name — here the working directory was called `wp2static` and
         * so was the active plugin, and that is how Akismet's fourteen files,
         * from a switched-off plugin, ended up in every deploy.
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
            'wp_parse_url',
            [
                'return' => function ( $url, $component = -1 ) {
                    return parse_url( (string) $url, (int) $component );
                },
            ]
        );
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

    /**
     * The URL WordPress hands back for the plugins directory. Its scheme is
     * decided by `set_url_scheme()` at runtime, so it is a parameter here
     * rather than a fixture: the site is the same, the answer is not.
     */
    private function mockSiteInfo( string $plugins_url ) : void {
        Mockery::mock( 'overload:\WP2Static\SiteInfo' )
            ->shouldReceive( 'getPath' )->andReturn( $this->plugins_path . '/' )
            ->shouldReceive( 'getUrl' )->andReturn( $plugins_url );
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testOnlyActivePluginsAreDetected() : void {
        $this->mockSiteInfo( 'https://foo.com/wp-content/plugins/' );

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
        $this->mockSiteInfo( 'https://foo.com/wp-content/plugins/' );

        /*
         * The comparison used to be `str_replace( $active_dirs, '', $path ) !==
         * $path`, that is, "the name of an active plugin appears somewhere in
         * the absolute path" — not "this file is inside an active plugin's
         * directory". With the root named after an active plugin, as here, the
         * old condition is true for every file of every plugin, and the
         * switched-off plugin's css gets through.
         *
         * That it gets through is not a cosmetic detail: a deactivated plugin is
         * code the site owner decided not to run, and publishing it makes it
         * available to anyone.
         */
        foreach ( DetectPluginAssets::detect() as $url ) {
            $this->assertStringNotContainsString( 'spento', $url );
        }
    }

    public function testTheSchemeOfThePluginsURLNeverReachesTheQueue() : void {
        /*
         * The site is https, but `plugins_url()` answers http because
         * `is_ssl()` is false outside the web server — which is the normal
         * case for an export driven from WP-CLI or from cron. What has to come
         * out is a path either way: an absolute URL here is taken downstream
         * for a path, and the files land outside the crawled site.
         */
        $this->mockSiteInfo( 'http://foo.com/wp-content/plugins/' );

        $this->assertSame(
            [ '/wp-content/plugins/attivo/assets/style.css' ],
            DetectPluginAssets::detect()
        );
    }
}
