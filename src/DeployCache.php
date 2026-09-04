<?php
/**
 * Facciata statica della Deploy Cache.
 *
 * Le query e la lettura dei file stanno in DeployCacheRepository. Qui restano
 * i nomi che gli addon chiamano — `fileisCached` con la i minuscola compresa,
 * che e' un refuso ma e' API — e il messaggio di log della truncate.
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
        WsLog::l( 'Deleting DeployCache' );

        self::repository()->truncate( $namespace );
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
     * Cosa cambierebbe un deploy, prima di farlo.
     *
     * Senza argomenti guarda il sito processato cosi' com'e' adesso.
     *
     * @param string        $namespace     Spazio dei nomi del deployer.
     * @param string[]|null $current_paths Percorsi da confrontare; null per
     *                                     leggerli da ProcessedSite.
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
     * Toglie dalla cache i percorsi indicati, dopo averli rimossi a destinazione.
     *
     * @param string[] $paths     Percorsi da dimenticare.
     * @param string   $namespace Spazio dei nomi del deployer.
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
