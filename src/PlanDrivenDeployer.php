<?php
/**
 * The part of a deploy that is the same wherever the site is going.
 *
 * Ask the plan what changed, send those files, delete the ones that have gone,
 * tidy the directories that emptied, record what arrived — and record it
 * *after* it arrived, so an interrupted deploy leaves the rest pending rather
 * than believing it is done. None of that depends on whether the destination is
 * a directory on the same disk, a server over sFTP, or one over FTP.
 *
 * It was written three times before this: once in `directory-deployment`, once
 * in `sftp`, and a third copy was about to be written for `ftp`. Each copy had
 * drifted — the empty-directory walk went up one level in one and all the way
 * in another, the failure logging was capped in one and unbounded in the other
 * — and the drift is the argument. A deployer should have to say only what is
 * different about it.
 *
 * **What a subclass supplies:** where the files go (`root()`), how one file is
 * sent (`put()`), how one is removed (`delete()`), how an empty directory is
 * removed (`removeDirectory()`), and what to call itself in the log. Opening
 * and closing a connection are optional.
 *
 * @package WP2Static
 */

namespace WP2Static;

abstract class PlanDrivenDeployer {

    /**
     * How many individual failures get a line of their own.
     *
     * A misconfigured server fails for every file, and one row each on a site
     * of eighteen hundred pages is eighteen hundred rows saying the same
     * thing — which buries the deploy that actually ran.
     */
    const FAILURES_TO_LOG = 10;

    /**
     * The DeployCache namespace: the add-on's slug, and never `default`.
     *
     * The cache is shared between deployers. Two of them writing to the same
     * namespace would each see the other's uploads as its own and skip files it
     * had never sent.
     */
    abstract protected function deployCacheNamespace() : string;

    /**
     * What this deployer calls itself in the log.
     */
    abstract protected function label() : string;

    /**
     * Send one file.
     *
     * @param string $local       Absolute path of the file to send.
     * @param string $destination Where it goes, root included.
     */
    abstract protected function put( string $local, string $destination ) : bool;

    /**
     * Remove one file that has left the site.
     *
     * @param string $destination Where it is, root included.
     */
    abstract protected function delete( string $destination ) : bool;

    /**
     * Remove one directory, and answer false when it is not empty.
     *
     * False is the loop's stop condition rather than an error: the walk up
     * stops at the first directory that still holds something.
     *
     * @param string $destination Where it is, root included.
     */
    abstract protected function removeDirectory( string $destination ) : bool;

    /**
     * Where the site goes. Empty for a destination with no prefix.
     */
    protected function root() : string {
        return '';
    }

    /**
     * Prepare the destination. False abandons the deploy without an error of
     * its own: whatever failed has already said so.
     */
    protected function connect() : bool {
        return true;
    }

    /**
     * Anything to do once the files have gone.
     */
    protected function disconnect() : void {
    }

    /**
     * Run the deploy.
     */
    final public function deploy( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        if ( ! $this->connect() ) {
            return;
        }

        $namespace = $this->deployCacheNamespace();
        $plan = DeployCache::plan( $namespace );

        WsLog::l( $plan->summary() );

        $root = $this->root();
        $sent = 0;
        $failed = 0;

        foreach ( $plan->toDeploy() as $path ) {
            if ( $this->put( $processed_site_path . $path, $root . $path ) ) {
                DeployCache::addFile( $path, $namespace );

                $sent++;

                continue;
            }

            $failed++;

            if ( $failed <= self::FAILURES_TO_LOG ) {
                WsLog::l( sprintf( '%s could not send %s', $this->label(), $path ) );
            }
        }

        if ( $failed > self::FAILURES_TO_LOG ) {
            WsLog::l(
                sprintf(
                    '%s could not send %d more file(s).',
                    $this->label(),
                    $failed - self::FAILURES_TO_LOG
                )
            );
        }

        $removed = $this->removeFiles( $plan->toDelete(), $root );

        /*
         * The cache is updated AFTER the destination has changed. A path
         * dropped from the cache while still at the destination is a path no
         * later deploy will ever remove, because from the cache's point of view
         * it was dealt with.
         */
        DeployCache::rmPaths( $plan->toDelete(), $namespace );

        WsLog::l(
            sprintf(
                '%s complete: %d sent, %d removed, %d failed.',
                $this->label(),
                $sent,
                $removed,
                $failed
            )
        );

        $this->disconnect();
    }

    /**
     * Delete what has gone, and the directories it leaves empty.
     *
     * @param string[] $paths Root-relative paths that have left the site.
     * @param string   $root  Where the site lives at the destination.
     * @return int How many files were actually removed.
     */
    private function removeFiles( array $paths, string $root ) : int {
        $removed = 0;
        $emptied = [];

        foreach ( $paths as $path ) {
            if ( ! $this->delete( $root . $path ) ) {
                continue;
            }

            $removed++;
            $emptied[ dirname( $root . $path ) ] = true;
        }

        /*
         * A directory left empty is a visible leftover: on a server with
         * directory listings on it becomes an indexable empty page, which is
         * the same dead-URL problem the deploy plan exists to avoid.
         *
         * Deepest first, and walking up. Emptiness is only visible one level at
         * a time: a directory can be left empty because the only subdirectory
         * it held was emptied, and in that case its own name never came through
         * the loop above. removeDirectory() answers false for a directory that
         * still holds something, which is what ends each walk.
         */
        krsort( $emptied );

        $stop = '' === $root ? '.' : $root;

        foreach ( array_keys( $emptied ) as $directory ) {
            while ( $directory !== $stop
                && strlen( $directory ) > strlen( $stop )
                && $this->removeDirectory( $directory )
            ) {
                $directory = dirname( $directory );
            }
        }

        return $removed;
    }
}
