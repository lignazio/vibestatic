<?php
/**
 * sFTP — bundled module.
 *
 * @package WP2StaticSFTP
 */

namespace WP2StaticSFTP;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-sftp';

    const TABLE = 'wp2static_addon_sftp_options';

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return self::SLUG;
    }

    public function name() : string {
        return 'sFTP';
    }

    public function description() : string {
        return 'Uploads the generated site to a remote server over sFTP, sending only what changed';
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic#sftp';
    }

    /**
     * `private_key` is a path on this server, not the key itself, so it is not
     * a secret in the sense the encrypted options are. `password` and
     * `passphrase` are.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                self::TABLE,
                [
                    'host' => [ 'string', '' ],
                    'port' => [ 'int', '22' ],
                    'username' => [ 'string', '' ],
                    'password' => [ 'password', '' ],
                    'private_key' => [ 'string', '' ],
                    'passphrase' => [ 'password', '' ],
                    'remote_root' => [ 'string', '' ],
                    'dir_permissions' => [ 'string', '0755' ],
                    'file_permissions' => [ 'string', '0644' ],
                    'owner' => [ 'string', '' ],
                    'group' => [ 'string', '' ],
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
            'host' => [
                __( 'Remote host', 'vibestatic' ),
                __( 'Host name of the sFTP server.', 'vibestatic' ),
            ],
            'port' => [
                __( 'Port', 'vibestatic' ),
                __( 'Defaults to 22 when left empty.', 'vibestatic' ),
            ],
            'username' => [ __( 'Username', 'vibestatic' ), '' ],
            'password' => [
                __( 'Password', 'vibestatic' ),
                __(
                    'Leave empty when authenticating with a private key, or to keep the saved one.',
                    'vibestatic'
                ),
            ],
            'private_key' => [
                __( 'Private key path', 'vibestatic' ),
                __(
                    'Path on this server to the private key. Takes precedence over the password.',
                    'vibestatic'
                ),
            ],
            'passphrase' => [
                __( 'Private key passphrase', 'vibestatic' ),
                __( 'Stored encrypted. Leave blank to keep the saved one.', 'vibestatic' ),
            ],
            'remote_root' => [
                __( 'Remote root path', 'vibestatic' ),
                __( 'Where the site is uploaded to. Empty means the login directory.', 'vibestatic' ),
            ],
            'dir_permissions' => [ __( 'Remote directory permissions', 'vibestatic' ), '' ],
            'file_permissions' => [ __( 'Remote file permissions', 'vibestatic' ), '' ],
            'owner' => [ __( 'Remote owner', 'vibestatic' ), '' ],
            'group' => [ __( 'Remote group', 'vibestatic' ), '' ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static sftp', [ CLI::class, 'sftp' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        \WP2Static\WsLog::l( 'sFTP Addon deploying' );

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
