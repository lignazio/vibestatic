<?php
/**
 * Access to the wp_wp2static_deploy_cache table.
 *
 * Like CrawlCacheRepository, but with one extra dependency that is the whole
 * point here: telling whether a file changed means reading it, and the path to
 * the post-processed directory used to come from ProcessedSite::getPath(), a
 * static call inside the method. It now comes from the constructor, so a test
 * can mount a virtual filesystem and actually check "identical file -> already
 * cached".
 *
 * That check is what the incremental deploy rests on.
 *
 * @package WP2Static
 */

namespace WP2Static;

class DeployCacheRepository {

    const DEFAULT_NAMESPACE = 'default';

    /**
     * @var \wpdb
     */
    private $db;

    /**
     * @var string
     */
    private $table;

    /**
     * @var string
     */
    private $processed_site_path;

    public function __construct( \wpdb $db, string $processed_site_path ) {
        $this->db = $db;
        $this->table = $db->prefix . 'wp2static_deploy_cache';
        $this->processed_site_path = $processed_site_path;
    }

    public function createTable() : void {
        $charset_collate = $this->db->get_charset_collate();

        $check_table_query = $this->db->prepare(
            'SHOW TABLES LIKE %s',
            $this->db->esc_like( $this->table )
        );

        // if table exists, check structure
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $check_table_query è la prepare() qui sopra.
        if ( $this->db->get_var( $check_table_query ) === $this->table ) {
            // @todo We can remove this eventually
            // If the ID column is missing, just remove the table and start
            // again because dbDelta isn't adding it correctly
            $id_row = $this->db->get_row(
                $this->db->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $this->table, 'id' )
            );

            if ( ! $id_row ) {
                $this->db->query(
                    (string) $this->db->prepare( 'DROP TABLE IF EXISTS %i', $this->table )
                );
            }
        }

        $table = $this->table;

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            path_hash CHAR(32) NOT NULL,
            path VARCHAR(2083) NOT NULL,
            file_hash CHAR(32) NOT NULL,
            namespace VARCHAR(128) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash_ns_idx (path_hash, namespace)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * The path hash is the md5 of the file's ABSOLUTE path, not the relative
     * one. It has always been that way and must not change: add-ons write to
     * this table too, and changing the rule would invalidate every existing
     * cache without telling anyone.
     */
    public function pathHash( string $local_path ) : string {
        return md5( $this->processed_site_path . $local_path );
    }

    /**
     * Read the file and compute its md5. Returns null when the file is missing
     * or empty — and an empty string counts as "missing" here, which is the
     * original behaviour.
     *
     * The is_readable() check is new. file_get_contents() used to be called
     * cold, and on a missing file that emits a PHP warning: during a deploy
     * with a few paths no longer present, the log filled with lines that
     * describe no failure at all, only a file that is not there — which is
     * exactly the condition this method exists to recognise.
     */
    public function fileHash( string $local_path ) : ?string {
        $deployed_file = $this->processed_site_path . $local_path;

        if ( ! is_readable( $deployed_file ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.
        $file_contents = file_get_contents( $deployed_file );

        if ( ! $file_contents ) {
            return null;
        }

        return md5( $file_contents );
    }

    public function addFile(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : void {
        /*
         * Not `??`: the original took the read-the-file branch for any falsy
         * value, the empty string included, whereas coalescing would only do so
         * for null. It looks like a nuance, but a caller passing '' would get a
         * row with an empty hash instead of the real one — that is, a file that
         * reads as "already deployed" forever.
         */
        if ( ! $file_hash ) {
            $file_hash = $this->fileHash( $local_path );
        }

        if ( ! $file_hash ) {
            return;
        }

        $this->db->query(
            (string) $this->db->prepare(
                'INSERT INTO %i (path_hash, path, file_hash, namespace)
                 VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE file_hash = %s, namespace = %s',
                $this->table,
                // Insert values
                $this->pathHash( $local_path ),
                $local_path,
                $file_hash,
                $namespace,
                // Duplicate key values
                $file_hash,
                $namespace
            )
        );
    }

    /**
     * Checks if file can skip deployment
     *  - uses hash of file and path's hash
     */
    public function isFileCached(
        string $local_path,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?string $file_hash = null
    ) : bool {
        if ( ! $file_hash ) {
            $file_hash = $this->fileHash( $local_path );
        }

        if ( ! $file_hash ) {
            return false;
        }

        $hash = $this->db->get_var(
            $this->db->prepare(
                'SELECT path_hash FROM %i
                 WHERE path_hash = %s AND file_hash = %s AND namespace = %s LIMIT 1',
                $this->table,
                $this->pathHash( $local_path ),
                $file_hash,
                $namespace
            )
        );

        return (bool) $hash;
    }

    /**
     * Empty the cache of EVERY deployer.
     *
     * `truncate()` with no arguments touches only the `default` namespace, and
     * that is the right thing when a deployer wants to forget what it published
     * itself. It is not the right thing behind a button that says "delete the
     * Deploy Cache": there, every other deployer's cache survived, and the user
     * saw a non-zero count right after deleting.
     */
    public function truncateAll() : void {
        $this->db->query( (string) $this->db->prepare( 'TRUNCATE TABLE %i', $this->table ) );
    }

    public function truncate( string $namespace = self::DEFAULT_NAMESPACE ) : void {
        $this->db->query(
            (string) $this->db->prepare(
                'DELETE FROM %i WHERE namespace = %s',
                $this->table,
                $namespace
            )
        );
    }

    /**
     *  Count Paths in Deploy Cache for default or specific namespace
     */
    public function getTotalByNamespace( string $namespace = self::DEFAULT_NAMESPACE ) : int {
        return (int) $this->db->get_var(
            $this->db->prepare(
                'SELECT count(*) FROM %i WHERE namespace = %s',
                $this->table,
                $namespace
            )
        );
    }

    /**
     *  Count Paths in Deploy Cache across all namespaces
     *
     *  @return mixed[] namespace totals
     */
    public function getTotals() : array {
        $counts = [];

        /** @var list<object{namespace: string, count: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT namespace, COUNT(*) AS count FROM %i GROUP BY namespace',
                $this->table
            )
        ) ?? [];

        foreach ( $rows as $row ) {
            $counts[ $row->namespace ] = $row->count;
        }

        return $counts;
    }

    /**
     * The hashes already published, keyed by path.
     *
     * One query. `isFileCached()` does one per file, which is right when you
     * are looking at a single file; to compare a whole site — eighteen hundred
     * files here — eighteen hundred queries are the quickest way to make the
     * incremental deploy slower than a full one.
     *
     * @param string $namespace The deployer's namespace.
     * @return array<string, string> path => file hash
     */
    public function getHashesByPath( string $namespace = self::DEFAULT_NAMESPACE ) : array {
        /** @var list<object{path: string, file_hash: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT path, file_hash FROM %i WHERE namespace = %s',
                $this->table,
                $namespace
            )
        ) ?? [];

        $hashes = [];

        foreach ( $rows as $row ) {
            $hashes[ $row->path ] = $row->file_hash;
        }

        return $hashes;
    }

    /**
     * Compare the processed site against what has already been published.
     *
     * The paths must arrive in the same shape the deployer writes into the
     * cache — the shape `ProcessedSite::getPaths()` produces, that is, relative
     * to the root and with a leading slash. If the two shapes diverge, every
     * file reads as new and the incremental deploy becomes a full deploy that
     * claims to be incremental: the worst way to be wrong, because it does not
     * show.
     *
     * @param string[] $current_paths Paths present in the processed site now.
     * @param string   $namespace     The deployer's namespace.
     */
    public function plan(
        array $current_paths,
        string $namespace = self::DEFAULT_NAMESPACE
    ) : DeployPlan {
        $published = $this->getHashesByPath( $namespace );

        $to_deploy = [];
        $unchanged = 0;

        foreach ( $current_paths as $path ) {
            $hash = $this->fileHash( $path );

            if ( null === $hash ) {
                // Unreadable or empty: there is nothing to upload, and calling
                // it "unchanged" would be a lie. It stays out of both lists.
                continue;
            }

            if ( isset( $published[ $path ] ) && $published[ $path ] === $hash ) {
                $unchanged++;

                continue;
            }

            $to_deploy[] = $path;
        }

        $to_delete = array_values(
            array_diff( array_keys( $published ), $current_paths )
        );

        return new DeployPlan( $to_deploy, $to_delete, $unchanged );
    }

    /**
     * Drop the given paths from the cache.
     *
     * Call it after removing them at the destination, not before: if the deploy
     * fails halfway, a cache that has already forgotten those files would
     * republish them on the next run.
     *
     * @param string[] $paths     Paths to forget.
     * @param string   $namespace The deployer's namespace.
     */
    public function rmPaths( array $paths, string $namespace = self::DEFAULT_NAMESPACE ) : void {
        foreach ( $paths as $path ) {
            $this->db->delete(
                $this->table,
                [
                    'path_hash' => $this->pathHash( $path ),
                    'namespace' => $namespace,
                ]
            );
        }
    }

    /**
     *  Get all cached paths
     *
     *  @return string[] All cached paths
     */
    public function getPaths( string $namespace = self::DEFAULT_NAMESPACE ) : array {
        $paths = $this->db->get_col(
            $this->db->prepare(
                'SELECT path FROM %i WHERE namespace = %s ORDER BY path',
                $this->table,
                $namespace
            )
        );

        // Vedi CrawlCacheRepository::getHashes(): la colonna e' NOT NULL.
        return array_values( array_filter( $paths, 'is_string' ) );
    }
}
