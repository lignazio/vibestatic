<?php
/**
 * Netlify — bundled module.
 *
 * @package WP2StaticNetlify
 */

namespace WP2StaticNetlify;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-netlify';

    const TABLE = 'wp2static_addon_netlify_options';

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return self::SLUG;
    }

    /**
     * Not translated, and that is deliberate: it is a product name, and
     * `wp2static_register_addon` writes it into the add-ons table on every
     * registration. Translating it would make the row's contents depend on
     * whichever language the last admin request happened to run in.
     */
    public function name() : string {
        return 'Netlify';
    }

    public function description() : string {
        return 'Uploads the generated site to Netlify, sending only what it does not already hold';
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic/blob/vibestatic/addons/netlify/README.md';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                self::TABLE,
                [
                    'siteID' => [ 'string', '' ],
                    'accessToken' => [ 'password', '' ],
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
            'siteID' => [
                __( 'Site ID', 'vibestatic' ),
                __( 'Found under Site configuration in the Netlify dashboard.', 'vibestatic' ),
            ],
            'accessToken' => [
                __( 'Personal access token', 'vibestatic' ),
                __(
                    'Stored encrypted. Create one under User settings / Applications in Netlify.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static netlify', [ CLI::class, 'netlify' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        \WP2Static\WsLog::l( 'Netlify Addon deploying' );

        ( new Deployer() )->uploadFiles( $processed_site_path );
    }

    /**
     * Create and seed this module's options table.
     *
     * Called by WP2Static\Modules::installTables(), from Schema::install(),
     * which is the one place in the plugin where a table is made. A module
     * never gets a `register_activation_hook` of its own, so the base class's
     * activate() — which is for an add-on installed as a plugin — is not the
     * route here.
     */
    public static function installTables() : void {
        self::instance()->options()->install();
    }
}
