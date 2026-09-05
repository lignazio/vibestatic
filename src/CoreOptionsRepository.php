<?php
/**
 * Access to the wp_wp2static_core_options table.
 *
 * Reading and writing only: the definition of the options — names, types,
 * defaults, labels — stays in CoreOptions, because that is data, not
 * persistence.
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
     * Create the table and drop the two columns no longer in use.
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
     * The names of the existing columns.
     *
     * These used to be inferred from the keys of `SELECT * LIMIT 1`, that is,
     * from the shape of a row. An empty table has no rows, so no keys, so the
     * obsolete columns were never dropped — on an empty table the cleanup
     * simply did not happen. `SHOW COLUMNS` asks for what you want to know
     * instead of inferring it from the content.
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
     * Insert the options that are not there yet, leaving existing ones alone.
     *
     * @param array<string, array<string, ?string>> $specs The definitions.
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
     * @param string $name Option name.
     * @return string|null The raw value, null if the row is not there.
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
     * @param string $name Option name.
     * @return string|null The raw blob, null if the row is not there.
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
     * @param string $name Option name.
     * @return \stdClass|null The row, null if it is not there.
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
     * Every row, keyed by name.
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
     * @param string               $name Option name.
     * @param array<string, mixed> $data Colonne da aggiornare.
     */
    public function update( string $name, array $data ) : void {
        $this->db->update( $this->table, $data, [ 'name' => $name ] );
    }
}
