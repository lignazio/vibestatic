<?php
/**
 * FTP — bundled module.
 *
 * @package WP2StaticFTP
 */

namespace WP2StaticFTP;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-ftp';

    const TABLE = 'wp2static_addon_ftp_options';

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return self::SLUG;
    }

    public function name() : string {
        return 'FTP';
    }

    public function description() : string {
        return 'Uploads the generated site over FTP or FTPS, sending only what changed';
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic#ftp';
    }

    /**
     * `use_tls` defaults to on. Plain FTP sends the password, and then every
     * byte of the site, in the clear: it stays available because shared
     * hosting sometimes offers nothing else, but it has to be asked for.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                self::TABLE,
                [
                    'host' => [ 'string', '' ],
                    'port' => [ 'int', '21' ],
                    'username' => [ 'string', '' ],
                    'password' => [ 'password', '' ],
                    'remote_root' => [ 'string', '' ],
                    'use_tls' => [ 'bool', '1' ],
                ]
            );
        }

        return $this->options;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function fields() : array {
        return [
            'host' => [ __( 'Host', 'vibestatic' ), '' ],
            'port' => [
                __( 'Port', 'vibestatic' ),
                __( '21 unless the host says otherwise.', 'vibestatic' ),
            ],
            'username' => [ __( 'Username', 'vibestatic' ), '' ],
            'password' => [
                __( 'Password', 'vibestatic' ),
                __( 'Stored encrypted. Leave blank to keep the saved one.', 'vibestatic' ),
            ],
            'remote_root' => [
                __( 'Remote root', 'vibestatic' ),
                __(
                    'Where the site goes on the server, for example /public_html. Leave empty for the directory you land in on login.',
                    'vibestatic'
                ),
            ],
            'use_tls' => [
                __( 'Encrypt the connection (FTPS)', 'vibestatic' ),
                __(
                    'Leave this on. With it off, the password and then every byte of the site travel in the clear over whatever network is in between. It is here because some shared hosting still offers nothing else.',
                    'vibestatic'
                ),
            ],
        ];
    }

    /**
     * What this PHP build cannot do, said on the page rather than discovered
     * at deploy time.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function notices() : array {
        $notices = [];

        if ( ! function_exists( 'ftp_connect' ) ) {
            $notices[] = [
                'error',
                __(
                    'This server has no FTP support in PHP. Install the ext-ftp extension, or use the sFTP module, which needs nothing beyond what the plugin already ships.',
                    'vibestatic'
                ),
            ];
        }

        if ( ! function_exists( 'ftp_ssl_connect' ) ) {
            $notices[] = [
                'warning',
                __(
                    'This PHP build has no FTPS support, so the encryption setting below will have no effect.',
                    'vibestatic'
                ),
            ];
        }

        return $notices;
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static ftp', [ CLI::class, 'ftp' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        if ( ! function_exists( 'ftp_connect' ) ) {
            \WP2Static\WsLog::l(
                'This server has no FTP support in PHP: install the ext-ftp extension,' .
                ' or use the sFTP module instead.'
            );

            return;
        }

        \WP2Static\WsLog::l( 'FTP Addon deploying' );

        ( new Deployer() )->deploy( $processed_site_path );
    }

    /**
     * Create and seed this module's options table.
     *
     * Called by WP2Static\Modules::installTables(), from Schema::install().
     */
    public static function installTables() : void {
        self::instance()->options()->install();
    }
}
