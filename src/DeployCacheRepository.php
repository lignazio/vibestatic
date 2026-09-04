<?php
/**
 * Accesso alla tabella wp_wp2static_deploy_cache.
 *
 * Come CrawlCacheRepository, ma con una dipendenza in piu' che qui e' il punto:
 * per sapere se un file e` cambiato bisogna leggerlo, e il percorso della
 * cartella post-processata arrivava da ProcessedSite::getPath(), una chiamata
 * statica dentro il metodo. Ora arriva dal costruttore, e un test puo' montare
 * un filesystem virtuale e verificare davvero «file identico -> gia' in cache».
 *
 * E' la verifica su cui poggia il deploy incrementale della fase 6.
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
     * L'hash del percorso e' l'md5 del percorso ASSOLUTO del file, non di
     * quello relativo. E' cosi' da sempre e non va cambiato: la tabella la
     * scrivono anche gli addon, e cambiare la regola invaliderebbe ogni cache
     * esistente senza dirlo a nessuno.
     */
    public function pathHash( string $local_path ) : string {
        return md5( $this->processed_site_path . $local_path );
    }

    /**
     * Legge il file e ne calcola l'md5. Restituisce null quando il file non
     * c'e' o e' vuoto — e la stringa vuota qui conta come «non c'e'», che e'
     * il comportamento originale.
     *
     * Il controllo is_readable() e' nuovo. Prima si chiamava file_get_contents()
     * a freddo, e su un file mancante quella emette un warning PHP: durante un
     * deploy con qualche percorso non piu' presente il log si riempiva di righe
     * che non descrivono un guasto, solo un file che non c'e' — cioe' proprio
     * la condizione che questo metodo esiste per riconoscere.
     */
    public function fileHash( string $local_path ) : ?string {
        $deployed_file = $this->processed_site_path . $local_path;

        if ( ! is_readable( $deployed_file ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file locale, non una richiesta remota.
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
         * Non `?? `: l'originale entrava nel ramo di lettura del file per
         * qualunque valore falso, stringa vuota compresa, e il coalescing lo
         * farebbe solo per null. Sembra una sfumatura, ma un chiamante che
         * passa '' otterrebbe una riga con un hash vuoto invece che l'hash
         * vero, cioe' un file che risulta «gia' deployato» per sempre.
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
     * Svuota la cache di TUTTI i deployer.
     *
     * `truncate()` senza argomenti tocca il solo spazio dei nomi `default`,
     * ed e` la cosa giusta quando un deployer vuole dimenticare cio` che ha
     * pubblicato lui. Non lo e` dietro un pulsante che dice «cancella la Deploy
     * Cache»: li` restavano in piedi le cache di tutti gli altri deployer, e
     * l'utente vedeva un numero diverso da zero subito dopo aver cancellato.
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
     * Gli hash gia' pubblicati, per percorso.
     *
     * Una query sola. `isFileCached()` ne fa una per file, ed e' giusto cosi'
     * quando si guarda un file solo; per confrontare un sito intero — qui sono
     * milleottocento file — mille e ottocento interrogazioni sono il modo piu'
     * rapido di rendere il deploy incrementale piu' lento di quello completo.
     *
     * @param string $namespace Spazio dei nomi del deployer.
     * @return array<string, string> percorso => hash del file
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
     * Confronta il sito processato con quello gia' pubblicato.
     *
     * I percorsi devono arrivare nella stessa forma in cui il deployer li
     * scrive in cache — quella di `ProcessedSite::getPaths()`, cioe' relativi
     * alla radice e con lo slash iniziale. Se le due forme divergono, ogni file
     * risulta nuovo e il deploy incrementale diventa un deploy completo che
     * dice di essere incrementale: e' il modo peggiore di sbagliare, perche'
     * non si vede.
     *
     * @param string[] $current_paths Percorsi presenti ora nel sito processato.
     * @param string   $namespace     Spazio dei nomi del deployer.
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
                // Illeggibile o vuoto: non c'e' niente da caricare, e dirlo
                // «invariato» sarebbe una bugia. Lo si lascia fuori da entrambe
                // le liste.
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
     * Toglie dalla cache i percorsi indicati.
     *
     * Va chiamata dopo averli rimossi a destinazione, non prima: se il deploy
     * fallisce a meta', una cache che ha gia' dimenticato quei file li
     * ripubblicherebbe al giro successivo.
     *
     * @param string[] $paths     Percorsi da dimenticare.
     * @param string   $namespace Spazio dei nomi del deployer.
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
