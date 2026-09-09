<?php
/**
 * The add-on updater, and the one question a monorepo makes hard.
 *
 * `/releases/latest` — what the core's own Updater asks — is the newest release
 * of a repository. With ten add-ons in one repository that answer is wrong for
 * nine of them, and wrong in the worst way: WordPress would happily install
 * Azure over BunnyCDN, because the version number compares as newer and the zip
 * is a valid plugin.
 *
 * @package WP2Static
 */

namespace WP2Static\Tests;

use Mockery;
use WP2Static\Addon\Updater;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AddonUpdaterTest extends TestCase {

    const URI = 'https://github.com/lignazio/vibestatic-addons';

    public function setUp() : void {
        WP_Mock::setUp();
        Updater::reset();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * One release as GitHub lists it.
     *
     * @return array<string, mixed>
     */
    private function release( string $tag, bool $prerelease = false, bool $asset = true ) : array {
        return [
            'tag_name' => $tag,
            'html_url' => self::URI . '/releases/tag/' . $tag,
            'body' => 'Note di rilascio.',
            'prerelease' => $prerelease,
            'draft' => false,
            'assets' => $asset
                ? [ [ 'browser_download_url' => self::URI . "/releases/download/$tag/plugin.zip" ] ]
                : [],
        ];
    }

    /**
     * Stub WordPress, and have the API answer with this list of releases.
     *
     * @param list<array<string, mixed>> $releases Newest first, as GitHub sends them.
     */
    private function githubLists( array $releases ) : void {
        WP_Mock::userFunction( 'get_transient', [ 'return' => false ] );
        WP_Mock::userFunction( 'set_transient', [ 'return' => true ] );
        WP_Mock::userFunction( 'add_filter', [ 'return' => true ] );
        WP_Mock::userFunction( 'plugin_basename', [
            'return' => function ( $file ) {
                return basename( dirname( $file ) ) . '/' . basename( $file );
            },
        ] );
        WP_Mock::userFunction( 'get_file_data', [
            'return' => [ 'UpdateURI' => self::URI ],
        ] );
        WP_Mock::userFunction( 'wp_parse_url', [
            'return' => function ( $url, $component ) {
                return parse_url( $url, $component );
            },
        ] );
        WP_Mock::userFunction( 'untrailingslashit', [
            'return' => function ( $s ) {
                return rtrim( $s, '/' );
            },
        ] );
        WP_Mock::userFunction( 'is_wp_error', [ 'return' => false ] );
        WP_Mock::userFunction( 'wp_remote_get', [ 'return' => [ 'body' => '' ] ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_response_code', [ 'return' => 200 ] );
        WP_Mock::userFunction( 'wp_remote_retrieve_body', [
            'return' => (string) json_encode( $releases ),
        ] );
        WP_Mock::userFunction( 'wp_kses_post', [ 'return' => function ( $s ) {
            return $s;
        } ] );
        WP_Mock::userFunction( 'esc_html', [ 'return' => function ( $s ) {
            return $s;
        } ] );
    }

    /**
     * Register one add-on and ask what update it is offered.
     *
     * @return array<string, mixed>|false
     */
    private function updateOffered( string $slug, string $prefix ) {
        $file = "/plugins/vibestatic-addon-$slug/vibestatic-addon-$slug.php";

        Updater::register( $file, $prefix, ucfirst( $prefix ) );

        return Updater::checkForUpdate(
            false,
            [ 'UpdateURI' => self::URI ],
            "vibestatic-addon-$slug/vibestatic-addon-$slug.php"
        );
    }

    /**
     * **The test this class exists for.** Two add-ons, one repository, two
     * releases. Each must be offered its own and only its own.
     */
    public function testEachAddonIsOfferedOnlyItsOwnRelease() : void {
        $this->githubLists( [
            $this->release( 'azure-v2.0.0' ),
            $this->release( 'bunnycdn-v1.0.1' ),
            $this->release( 'github-v1.4.0' ),
        ] );

        $bunny = $this->updateOffered( 'bunnycdn', 'bunnycdn' );
        $azure = $this->updateOffered( 'azure', 'azure' );

        $this->assertIsArray( $bunny );
        $this->assertIsArray( $azure );

        $this->assertSame( '1.0.1', $bunny['version'] );
        $this->assertSame( '2.0.0', $azure['version'] );

        $this->assertStringContainsString( 'bunnycdn-v1.0.1', (string) $bunny['package'] );
        $this->assertStringContainsString( 'azure-v2.0.0', (string) $azure['package'] );
    }

    /**
     * An add-on with no release in the list is offered nothing, rather than
     * the newest thing in the repository.
     */
    public function testAnAddonWithNoReleaseIsOfferedNothing() : void {
        $this->githubLists( [
            $this->release( 'azure-v2.0.0' ),
        ] );

        $this->assertFalse( $this->updateOffered( 'bunnycdn', 'bunnycdn' ) );
    }

    /**
     * The prefix is anchored and carries the `-v`.
     *
     * Without that, `github` would match `github-pages-v1.0.0`, and a tag like
     * `bunnycdn-notes` would count as a release.
     */
    public function testAPrefixDoesNotMatchALongerName() : void {
        $this->githubLists( [
            $this->release( 'github-pages-v9.9.9' ),
            $this->release( 'github-notes' ),
            $this->release( 'github-v1.0.0' ),
        ] );

        $update = $this->updateOffered( 'github', 'github' );

        $this->assertIsArray( $update );
        $this->assertSame( '1.0.0', $update['version'] );
    }

    /**
     * A prerelease is not offered to a production site.
     */
    public function testAPrereleaseIsSkipped() : void {
        $this->githubLists( [
            $this->release( 'gcs-v2.0.0', true ),
            $this->release( 'gcs-v1.9.0' ),
        ] );

        $update = $this->updateOffered( 'gcs', 'gcs' );

        $this->assertIsArray( $update );
        $this->assertSame( '1.9.0', $update['version'] );
    }

    /**
     * A release with no zip attached is not an update.
     *
     * GitHub's own zipball unpacks into `lignazio-vibestatic-addons-<sha>`, so
     * WordPress would install the entire monorepo as a single plugin.
     */
    public function testAReleaseWithoutAZipIsSkipped() : void {
        $this->githubLists( [
            $this->release( 'gitlab-v2.0.0', false, false ),
            $this->release( 'gitlab-v1.0.0' ),
        ] );

        $update = $this->updateOffered( 'gitlab', 'gitlab' );

        $this->assertIsArray( $update );
        $this->assertSame( '1.0.0', $update['version'] );
    }

    /**
     * The filter name carries only the host, so `update_plugins_github.com` is
     * shared with every installed plugin whose Update URI is on GitHub.
     * Answering for one of those would redirect its updates to our releases.
     */
    public function testDoesNotAnswerForSomebodyElsesPlugin() : void {
        $this->githubLists( [ $this->release( 'bunnycdn-v1.0.1' ) ] );

        Updater::register(
            '/plugins/vibestatic-addon-bunnycdn/vibestatic-addon-bunnycdn.php',
            'bunnycdn',
            'BunnyCDN'
        );

        $this->assertFalse(
            Updater::checkForUpdate(
                false,
                [ 'UpdateURI' => 'https://github.com/qualcunaltro/plugin' ],
                'qualcunaltro-plugin/qualcunaltro-plugin.php'
            )
        );
    }
}
