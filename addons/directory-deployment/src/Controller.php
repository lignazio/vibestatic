<?php
/**
 * Directory Deployment — bundled module.
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-directory-deployment';

    const TABLE = 'wp2static_addon_directory_deployment_options';

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return self::SLUG;
    }

    public function name() : string {
        return 'Directory Deployment';
    }

    public function description() : string {
        return 'Deploys the generated site to a directory on the same machine, copying only what changed';
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic/blob/vibestatic/addons/directory-deployment/README.md';
    }

    /**
     * Emptying the target before every deployment is **off**, and stays off.
     *
     * It used to default to on, which meant a fresh install pointed at a
     * document root deleted the published site on the first run — and on every
     * run after it, incremental deployment included, since an empty destination
     * makes the plan report everything as new.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                self::TABLE,
                [
                    'directoryDeploymentTargetDirectory' => [ 'string', '' ],
                    'directoryDeploymentDeleteBeforeDeployment' => [ 'bool', '0' ],
                    'directoryDeploymentAdditionalSourceDirectory' => [ 'string', '' ],
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
            'directoryDeploymentTargetDirectory' => [
                __( 'Target directory (absolute path)', 'vibestatic' ),
                '',
            ],
            'directoryDeploymentDeleteBeforeDeployment' => [
                __( 'Delete target directory before deployment', 'vibestatic' ),
                __(
                    'Leave this off and each deployment copies only the files that changed, removing the ones that are gone. Turn it on and the target directory is emptied before every deployment: that is for starting over, not for daily use — while it empties, the published site is not there.',
                    'vibestatic'
                ),
            ],
            'directoryDeploymentAdditionalSourceDirectory' => [
                __( 'Additional source directory to include in deployment (absolute path)', 'vibestatic' ),
                '',
            ],
        ];
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command(
                'wp2static directory_deployment',
                [ CLI::class, 'directory_deployment' ]
            );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        \WP2Static\WsLog::l( 'Directory Deployment Addon deploying' );

        ( new Deployer() )->deploy( $processed_site_path );
    }

    /**
     * Create and seed this module's options table.
     *
     * Called by WP2Static\Modules::installTables(), from Schema::install().
     * It used to happen on every render of the settings page instead — a
     * dbDelta() per page load — alongside two view values, `uploads_path` and
     * `copy_url`, that no view has read since it was rewritten.
     */
    public static function installTables() : void {
        self::instance()->options()->install();
    }
}
