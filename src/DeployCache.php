<?php
/**
 * Static facade over the Deploy Cache.
 *
 * The queries and the file reading live in DeployCacheRepository. What stays
 * here are the names add-ons call — `fileisCached` with its lowercase i
 * included, which is a typo but is API — and the truncate log message.
 *
 * @package WP2Static
 */

namespace WP2Static;

class DeployCache {

    const DEFAULT_NAMESPACE = 'default';

    /**
     * @var DeployCacheRepository|null
     */
    private static $repository = null;

    public static function setRepository( ?DeployCacheRepository $repository ) : void {
        self::$repository = $repository;
    }

    public static function repository() : DeployCacheRepository {
        if ( ! self::$repository ) {
            /** @var \wpdb $wpdb */
            global $wpdb;

            self::$repository = new DeployCacheRepository( $wpdb, ProcessedSite::getPath() );
        }

        return self::$repository;
    }

    public static function createTable() : void {
        self::repository()->createTable();
    }

    public static function addFile(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : void {
        self::repository()->addFile( $local_path, $namespace, $file_hash );
    }

    /**
     * Checks if file can skip deployment
     *  - uses hash of file and path's hash
     */
    public static function fileisCached(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : bool {
        return self::repository()->isFileCached( $local_path, $namespace, $file_hash );
    }

    public static function truncate(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : void {
        WsLog::l( "Deleting DeployCache for namespace $namespace" );

        self::repository()->truncate( $namespace );
    }

    /**
     * Svuota la cache di tutti i deployer.
     */
    public static function truncateAll() : void {
        WsLog::l( 'Deleting DeployCache' );

        self::repository()->truncateAll();
    }

    /**
     *  Count Paths in Deploy Cache for default or specific namespace
     */
    public static function getTotalByNamespace(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : int {
        return self::repository()->getTotalByNamespace( $namespace );
    }

    /**
     *  Count Paths in Deploy Cache across all namespaces
     *
     *  @return mixed[] namespace totals
     */
    public static function getTotal() : array {
        return self::repository()->getTotals();
    }

    /**
     * What a deploy would change, before doing it.
     *
     * With no arguments it looks at the processed site as it stands now.
     *
     * @param string        $namespace     The deployer's namespace.
     * @param string[]|null $current_paths Paths to compare; null to read them
     *                                     from ProcessedSite.
     */
    public static function plan(
        string $namespace = self::DEFAULT_NAMESPACE,
        ?array $current_paths = null
    ) : DeployPlan {
        return self::repository()->plan(
            $current_paths ?? ProcessedSite::getPaths(),
            $namespace
        );
    }

    /**
     * Drop the given paths from the cache, after removing them at the
     * destination.
     *
     * @param string[] $paths     Paths to forget.
     * @param string   $namespace The deployer's namespace.
     */
    public static function rmPaths(
        array $paths,
        string $namespace = self::DEFAULT_NAMESPACE
    ) : void {
        self::repository()->rmPaths( $paths, $namespace );
    }

    /**
     *  Get all cached paths
     *
     *  @return string[] All cached paths
     */
    public static function getPaths(
        string $namespace = self::DEFAULT_NAMESPACE
    ) : array {
        return self::repository()->getPaths( $namespace );
    }
}
