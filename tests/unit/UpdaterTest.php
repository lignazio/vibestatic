<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UpdaterTest extends TestCase {

    const URI = 'https://github.com/lignazio/vibestatic';

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param array<string, mixed> $release Il corpo che GitHub restituirebbe.
     */
    private function githubReturns( array $release ) : void {
        WP_Mock::userFunction( 'get_transient', [ 'return' => false ] );
        WP_Mock::userFunction( 'set_transient', [ 'return' => true ] );
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
            'return' => (string) json_encode( $release ),
        ] );
        WP_Mock::userFunction( 'wp_kses_post', [ 'return' => function ( $s ) {
            return $s;
        } ] );
        WP_Mock::userFunction( 'esc_html', [ 'return' => function ( $s ) {
            return $s;
        } ] );
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseWithZip() : array {
        return [
            'tag_name' => 'v8.1.0',
            'html_url' => self::URI . '/releases/tag/v8.1.0',
            'body' => 'Note di rilascio.',
            'assets' => [
                [ 'browser_download_url' => self::URI . '/releases/download/v8.1.0/vibestatic-8.1.0.zip' ],
            ],
        ];
    }

    /**
     * Il guardiano che conta: il nome del filtro contiene solo l'host, quindi
     * `update_plugins_github.com` e' condiviso da OGNI plugin installato che
     * abbia un Update URI su GitHub. Rispondere a tutti dirotterebbe i loro
     * aggiornamenti verso le nostre release.
     */
    public function testDoesNotAnswerForSomebodyElsesPlugin() : void {
        WP_Mock::userFunction( 'untrailingslashit', [
            'return' => function ( $s ) {
                return rtrim( $s, '/' );
            },
        ] );

        $result = Updater::checkForUpdate(
            false,
            [ 'UpdateURI' => 'https://github.com/qualcunaltro/un-altro-plugin' ],
            'un-altro-plugin/un-altro-plugin.php'
        );

        $this->assertFalse( $result );
    }

    public function testLeavesAnEarlierAnswerAloneForSomebodyElsesPlugin() : void {
        WP_Mock::userFunction( 'untrailingslashit', [
            'return' => function ( $s ) {
                return rtrim( $s, '/' );
            },
        ] );

        $theirs = [ 'version' => '3.0.0', 'package' => 'https://example.com/loro.zip' ];

        $this->assertSame(
            $theirs,
            Updater::checkForUpdate(
                $theirs,
                [ 'UpdateURI' => 'https://github.com/qualcunaltro/un-altro-plugin' ],
                'un-altro-plugin/un-altro-plugin.php'
            )
        );
    }

    public function testReportsTheLatestReleaseForOurPlugin() : void {
        $this->githubReturns( $this->releaseWithZip() );

        $update = Updater::checkForUpdate(
            false,
            [ 'UpdateURI' => self::URI ],
            'vibestatic/vibestatic.php'
        );

        $this->assertIsArray( $update );
        // La `v` del tag non fa parte della versione: WordPress confronta
        // questo valore con l'header `Version:`, che la `v` non ce l'ha.
        $this->assertSame( '8.1.0', $update['version'] );
        $this->assertSame( self::URI, $update['id'] );
        $this->assertSame( 'vibestatic/vibestatic.php', $update['plugin'] );
        $this->assertStringEndsWith( 'vibestatic-8.1.0.zip', $update['package'] );
    }

    /**
     * Senza asset non si ripiega sullo zipball generato da GitHub: quello si
     * scompatta in `lignazio-vibestatic-<sha>`, quindi l'installer creerebbe
     * una seconda copia del plugin accanto alla prima invece di aggiornarla.
     */
    public function testAReleaseWithoutAZipIsNotAnUpdate() : void {
        $release = $this->releaseWithZip();
        $release['assets'] = [];

        $this->githubReturns( $release );

        $this->assertFalse(
            Updater::checkForUpdate( false, [ 'UpdateURI' => self::URI ], 'vibestatic/vibestatic.php' )
        );
    }

    public function testAnUnreachableGithubIsNotAnUpdate() : void {
        WP_Mock::userFunction( 'get_transient', [ 'return' => false ] );
        WP_Mock::userFunction( 'set_transient', [ 'return' => true ] );
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
        WP_Mock::userFunction( 'wp_remote_get', [ 'return' => [] ] );
        WP_Mock::userFunction( 'is_wp_error', [ 'return' => true ] );

        $this->assertFalse(
            Updater::checkForUpdate( false, [ 'UpdateURI' => self::URI ], 'vibestatic/vibestatic.php' )
        );
    }

    /**
     * Anche il fallimento si ricorda: senza, un repository che non c'e' o il
     * rate limit di GitHub — sessanta richieste all'ora per IP — farebbero
     * ripartire la chiamata a ogni controllo degli aggiornamenti.
     */
    public function testARememberedFailureDoesNotCallGithubAgain() : void {
        WP_Mock::userFunction( 'get_transient', [ 'return' => 'none' ] );
        WP_Mock::userFunction( 'untrailingslashit', [
            'return' => function ( $s ) {
                return rtrim( $s, '/' );
            },
        ] );

        $called = false;
        WP_Mock::userFunction( 'wp_remote_get', [
            'return' => function () use ( &$called ) {
                $called = true;
                return [];
            },
        ] );

        Updater::checkForUpdate( false, [ 'UpdateURI' => self::URI ], 'vibestatic/vibestatic.php' );

        $this->assertFalse( $called, 'wp_remote_get non deve essere chiamata.' );
    }

    public function testPluginInformationIgnoresOtherSlugs() : void {
        $this->assertFalse(
            Updater::pluginInformation( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] )
        );
    }

    public function testPluginInformationIgnoresOtherActions() : void {
        $this->assertFalse(
            Updater::pluginInformation( false, 'query_plugins', (object) [ 'slug' => 'vibestatic' ] )
        );
    }
}
