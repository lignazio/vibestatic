<?php
/**
 * Accesso alla tabella wp_wp2static_core_options.
 *
 * Solo lettura e scrittura: la definizione delle opzioni — nomi, tipi, valori
 * di partenza, etichette — resta in CoreOptions, perche' e' dato, non
 * persistenza.
 *
 * @package WP2Static
 */

namespace WP2Static;

class CoreOptionsRepository {

    /**
     * @var \wpdb
     */
    private $db;

    /**
     * @var string
     */
    private $table;

    /**
     * @param \wpdb $db Connessione WordPress.
     */
    public function __construct( \wpdb $db ) {
        $this->db = $db;
        $this->table = $db->prefix . 'wp2static_core_options';
    }

    /**
     * Crea la tabella e toglie le due colonne che non si usano piu'.
     */
    public function createTable() : void {
        $charset_collate = $this->db->get_charset_collate();
        $table = $this->table;

        $sql = "CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            value VARCHAR(249) NOT NULL,
            blob_value BLOB,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        foreach ( [ 'description', 'label' ] as $obsolete_column ) {
            if ( ! in_array( $obsolete_column, $this->columns(), true ) ) {
                continue;
            }

            $this->db->query(
                (string) $this->db->prepare(
                    'ALTER TABLE %i DROP COLUMN %i',
                    $this->table,
                    $obsolete_column
                )
            );
        }

        Controller::ensureIndex( $this->table, 'name', [ 'name' ], true );
    }

    /**
     * I nomi delle colonne esistenti.
     *
     * Prima si deducevano dalle chiavi di `SELECT * LIMIT 1`, cioe' dalla forma
     * di una riga. Se la tabella e' vuota non ci sono righe, quindi non ci sono
     * chiavi, quindi le colonne obsolete non venivano mai tolte — a tabella
     * vuota la pulizia semplicemente non avveniva. `SHOW COLUMNS` chiede quello
     * che si vuole sapere invece di dedurlo dal contenuto.
     *
     * @return string[]
     */
    private function columns() : array {
        /** @var list<object{Field: string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare( 'SHOW COLUMNS FROM %i', $this->table )
        ) ?? [];

        return array_map(
            function ( $row ) : string {
                return $row->Field;
            },
            $rows
        );
    }

    /**
     * Inserisce le opzioni che non ci sono ancora, senza toccare quelle presenti.
     *
     * @param array<string, array<string, ?string>> $specs Le definizioni.
     */
    public function seedOptions( array $specs ) : void {
        foreach ( $specs as $os ) {
            $this->db->query(
                (string) $this->db->prepare(
                    'INSERT IGNORE INTO %i (name, value, blob_value) VALUES (%s, %s, %s)',
                    $this->table,
                    $os['name'],
                    $os['default_value'],
                    $os['default_blob_value']
                )
            );
        }
    }

    /**
     * @param string $name Nome dell'opzione.
     * @return string|null Il valore grezzo, null se la riga non c'e'.
     */
    public function getValue( string $name ) : ?string {
        $value = $this->db->get_var(
            $this->db->prepare(
                'SELECT value FROM %i WHERE name = %s LIMIT 1',
                $this->table,
                $name
            )
        );

        return is_string( $value ) ? $value : null;
    }

    /**
     * @param string $name Nome dell'opzione.
     * @return string|null Il blob grezzo, null se la riga non c'e'.
     */
    public function getBlobValue( string $name ) : ?string {
        $value = $this->db->get_var(
            $this->db->prepare(
                'SELECT blob_value FROM %i WHERE name = %s LIMIT 1',
                $this->table,
                $name
            )
        );

        return is_string( $value ) ? $value : null;
    }

    /**
     * @param string $name Nome dell'opzione.
     * @return \stdClass|null La riga, null se non c'e'.
     */
    public function getRow( string $name ) : ?\stdClass {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT name, value, blob_value FROM %i WHERE name = %s LIMIT 1',
                $this->table,
                $name
            )
        );

        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * Tutte le righe, indicizzate per nome.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllRows() : array {
        /** @var list<object{name: string, value: string, blob_value: ?string}> $rows */
        $rows = $this->db->get_results(
            $this->db->prepare( 'SELECT name, value, blob_value FROM %i', $this->table )
        ) ?? [];

        $map = [];

        foreach ( $rows as $row ) {
            $map[ $row->name ] = [
                'name' => $row->name,
                'value' => $row->value,
                'blob_value' => $row->blob_value,
            ];
        }

        return $map;
    }

    /**
     * @param string               $name Nome dell'opzione.
     * @param array<string, mixed> $data Colonne da aggiornare.
     */
    public function update( string $name, array $data ) : void {
        $this->db->update( $this->table, $data, [ 'name' => $name ] );
    }
}
