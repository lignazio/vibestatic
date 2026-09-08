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

class Deployer {

    /**
     * The namespace these files are recorded under in the core's DeployCache.
     *
     * Not `default`: the cache is shared between deployers, and two different
     * deployers publishing the same site to two different places each need to
     * be able to say what they have already put where.
     */
    const DEFAULT_NAMESPACE = 'wp2static-addon-directory-deployment';

    /**
     * @param string $processed_site_path The processed site's directory.
     */
    public function uploadFiles( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        $target = (string) Controller::getValue( 'directoryDeploymentTargetDirectory' );

        if ( '' === $target ) {
            WsLog::l(
                'You must specify the target folder in WP2Static > Addons >' .
                ' Directory Deployment > Configure'
            );

            return;
        }

        if ( ! is_dir( $target ) ) {
            WsLog::l( 'Target folder does not exist: ' . $target );

            return;
        }

        $target = rtrim( $target, '/' );

        /*
         * Emptying the destination is still possible, but it is no longer the
         * normal route: it deletes the unchanged files too, and while it runs
         * the published site is not there. It is only for starting over, which
         * is why the cache is emptied afterwards as well — otherwise the plan
         * would report "all unchanged" for an empty directory.
         */
        if ( 0 !== intval( Controller::getValue( 'directoryDeploymentDeleteBeforeDeployment' ) ) ) {
            WsLog::l( 'Cleaning ' . $target );

            rrmdir( $target );
            mkdir( $target, 0755, true );

            DeployCache::truncate( self::DEFAULT_NAMESPACE );
        }

        $plan = DeployCache::plan( self::DEFAULT_NAMESPACE );

        WsLog::l( $plan->summary() );

        $copied = 0;

        foreach ( $plan->toDeploy() as $path ) {
            if ( $this->copyFile( $processed_site_path . $path, $target . $path ) ) {
                DeployCache::addFile( $path, self::DEFAULT_NAMESPACE );

                $copied++;
            }
        }

        $removed = $this->removeFiles( $plan->toDelete(), $target );

        // The cache is updated AFTER: if the deploy stops halfway, what was
        // not copied has to still be pending on the next run.
        DeployCache::rmPaths( $plan->toDelete(), self::DEFAULT_NAMESPACE );

        WsLog::l( "Directory deployment complete: $copied copied, $removed removed." );

        $this->copyAdditionalSource( $target );
    }

    /**
     * @param string $from Absolute path of the source file.
     * @param string $to   Absolute destination path.
     */
    private function copyFile( string $from, string $to ) : bool {
        $directory = dirname( $to );

        if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
            WsLog::l( 'Could not create directory: ' . $directory );

            return false;
        }

        if ( ! copy( $from, $to ) ) {
            WsLog::l( 'Could not copy: ' . $from );

            return false;
        }

        return true;
    }

    /**
     * Delete the files that have gone, and the directories they leave empty.
     *
     * @param string[] $paths  Root-relative paths to remove.
     * @param string   $target Root of the destination.
     * @return int How many were actually removed.
     */
    private function removeFiles( array $paths, string $target ) : int {
        $removed = 0;
        $directories = [];

        foreach ( $paths as $path ) {
            $file = $target . $path;

            if ( is_file( $file ) && unlink( $file ) ) {
                $directories[ dirname( $file ) ] = true;

                $removed++;
            }
        }

        /*
         * A directory left empty is a visible leftover: on a server with
         * directory listings enabled it becomes an indexable empty page. They go
         * deepest first, and only if empty — `rmdir` fails on its own for the
         * rest.
         */
        krsort( $directories );

        foreach ( array_keys( $directories ) as $directory ) {
            $this->removeEmptyDirectories( $directory, $target );
        }

        return $removed;
    }

    /**
     * Remove a directory left empty, then every ancestor it leaves empty in
     * turn, stopping at the destination root.
     *
     * **It walks up, and that is the point.** Only the directory that held the
     * file was collected above, so unpublishing the one post under
     * `/2019/08/` removed `/2019/08` and left `/2019` behind: an empty
     * directory nobody would ever look in again, on a server that may well
     * list it. Emptiness is only visible one level at a time, so it has to be
     * asked one level at a time.
     *
     * `rmdir` failing is the normal stop condition — it refuses a directory
     * that still has something in it — which is why the loop leans on it
     * rather than counting entries first.
     *
     * @param string $directory Absolute path of the directory just emptied.
     * @param string $target    Root of the destination; never removed.
     */
    private function removeEmptyDirectories( string $directory, string $target ) : void {
        /*
         * The prefix test carries the trailing slash. Without it a destination
         * of `/srv/site` matches `/srv/site-old`, and this would walk out of
         * the destination and start deleting a sibling's empty directories.
         */
        while (
            $directory !== $target
            && 0 === strpos( $directory, $target . '/' )
            && @rmdir( $directory )
        ) {
            $directory = dirname( $directory );
        }
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
        $extra = (string) Controller::getValue( 'directoryDeploymentAdditionalSourceDirectory' );

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
