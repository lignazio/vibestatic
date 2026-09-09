<?php
/**
 * Copy the generated site into a directory on the same machine.
 *
 * Rewritten to copy only what changed. It used to empty the destination and
 * re-copy everything on every deploy: eighteen hundred files to publish one
 * modified page, with a window in the middle during which the published site
 * did not exist. The core now says what changed — see WP2Static\DeployPlan —
 * and this copies those files, deletes the ones that have gone, and leaves the
 * rest alone.
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

use WP2Static\DeployCache;
use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    /**
     * The namespace these files are recorded under in the core's DeployCache.
     *
     * Not `default`: the cache is shared between deployers, and two different
     * deployers publishing the same site to two different places each need to
     * be able to say what they have already put where.
     */
    const DEFAULT_NAMESPACE = 'wp2static-addon-directory-deployment';

    protected function deployCacheNamespace() : string {
        return self::DEFAULT_NAMESPACE;
    }

    protected function label() : string {
        return 'Directory deployment';
    }

    protected function root() : string {
        return rtrim( Controller::instance()->options()->get( 'directoryDeploymentTargetDirectory' ), '/' );
    }

    /**
     * The name the add-on has always had for this.
     */
    public function uploadFiles( string $processed_site_path ) : void {
        $this->deploy( $processed_site_path );
    }

    protected function connect() : bool {
        $target = $this->root();

        if ( '' === $target ) {
            WsLog::l(
                'You must specify the target folder in WP2Static > Addons >' .
                ' Directory Deployment > Configure'
            );

            return false;
        }

        if ( ! is_dir( $target ) ) {
            WsLog::l( 'Target folder does not exist: ' . $target );

            return false;
        }

        /*
         * Emptying the destination is still possible, but it is no longer the
         * normal route: it deletes the unchanged files too, and while it runs
         * the published site is not there. It is only for starting over, which
         * is why the cache is emptied afterwards as well — otherwise the plan
         * would report "all unchanged" for an empty directory.
         */
        if ( Controller::instance()->options()->bool( 'directoryDeploymentDeleteBeforeDeployment' ) ) {
            WsLog::l( 'Cleaning ' . $target );

            rrmdir( $target );
            mkdir( $target, 0755, true );

            DeployCache::truncate( self::DEFAULT_NAMESPACE );
        }

        return true;
    }

    protected function disconnect() : void {
        $this->copyAdditionalSource( $this->root() );
    }

    /**
     * @param string $local       Absolute path of the file to copy.
     * @param string $destination Absolute path it goes to.
     */
    protected function put( string $local, string $destination ) : bool {
        $directory = dirname( $destination );

        if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            WsLog::l( 'Could not create directory: ' . $directory );

            return false;
        }

        return copy( $local, $destination );
    }

    protected function delete( string $destination ) : bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a local file this module wrote.
        return is_file( $destination ) && unlink( $destination );
    }

    protected function removeDirectory( string $destination ) : bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a local directory this module made.
        return @rmdir( $destination );
    }

    /**
     * The additional directory: files that do not come from the processed site
     * but that the user wants at the destination anyway. It does not go through
     * the plan, because the plan describes the generated site: here it is just
     * copied.
     *
     * @param string $target Root of the destination.
     */
    private function copyAdditionalSource( string $target ) : void {
        $extra = Controller::instance()->options()->get( 'directoryDeploymentAdditionalSourceDirectory' );

        if ( '' === $extra ) {
            return;
        }

        if ( ! is_dir( $extra ) ) {
            WsLog::l( 'Extra folder does not exist: ' . $extra );

            return;
        }

        WsLog::l( 'Copying ' . $extra . ' to ' . $target );

        xcopy( $extra, $target );
    }
}
