<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CoreOptionsRepositoryTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function db() {
        $wpdb = Mockery::mock( '\WPDB' );
        $wpdb->prefix = 'wp_';
        wp2static_test_mock_prepare( $wpdb );

        return $wpdb;
    }

    public function testGetValueReadsOneRowByName() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'get_var' )->once()->andReturnUsing(
            function ( $sql ) use ( &$captured ) {
                $captured = $sql;
                return 'https://esempio.it';
            }
        );

        $value = ( new CoreOptionsRepository( $wpdb ) )->getValue( 'deploymentURL' );

        $this->assertSame( 'https://esempio.it', $value );
        $this->assertSame(
            "SELECT value FROM `wp_wp2static_core_options` WHERE name = 'deploymentURL' LIMIT 1",
            $captured
        );
    }

    public function testGetValueIsNullWhenTheRowIsMissing() : void {
        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

        // Null e non stringa vuota: chi legge deve poter distinguere «non c'e'»
        // da «c'e' e vale vuoto», perche' nel primo caso vale il valore di
        // partenza della specifica.
        $this->assertNull( ( new CoreOptionsRepository( $wpdb ) )->getValue( 'boh' ) );
    }

    public function testGetAllRowsIsKeyedByName() : void {
        $wpdb = $this->db();

        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            [
                (object) [
                    'name' => 'detectPosts',
                    'value' => '1',
                    'blob_value' => null,
                ],
                (object) [
                    'name' => 'crawlConcurrency',
                    'value' => '4',
                    'blob_value' => null,
                ],
            ]
        );

        $rows = ( new CoreOptionsRepository( $wpdb ) )->getAllRows();

        $this->assertSame( [ 'detectPosts', 'crawlConcurrency' ], array_keys( $rows ) );
        $this->assertSame( '4', $rows['crawlConcurrency']['value'] );
    }

    public function testGetAllRowsSurvivesANullResult() : void {
        $wpdb = $this->db();
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

        $this->assertSame( [], ( new CoreOptionsRepository( $wpdb ) )->getAllRows() );
    }

    public function testSeedOptionsInsertsWithoutOverwriting() : void {
        $wpdb = $this->db();
        $queries = [];

        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function ( $sql ) use ( &$queries ) {
                $queries[] = $sql;
                return 1;
            }
        );

        ( new CoreOptionsRepository( $wpdb ) )->seedOptions(
            [
                'uno' => [
                    'name' => 'uno',
                    'default_value' => '1',
                    'default_blob_value' => null,
                ],
            ]
        );

        // INSERT IGNORE, non INSERT: seedOptions() gira a ogni attivazione, e
        // sovrascrivere qui vorrebbe dire azzerare le impostazioni dell'utente
        // ogni volta che il plugin si riattiva.
        $this->assertCount( 1, $queries );
        $this->assertStringContainsString( 'INSERT IGNORE INTO', $queries[0] );
        $this->assertStringContainsString( "'uno'", $queries[0] );
    }

    public function testUpdateTargetsTheRowByName() : void {
        $wpdb = $this->db();
        $captured = null;

        $wpdb->shouldReceive( 'update' )->once()->andReturnUsing(
            function ( $table, $data, $where ) use ( &$captured ) {
                $captured = [ $table, $data, $where ];
                return 1;
            }
        );

        ( new CoreOptionsRepository( $wpdb ) )->update( 'crawlConcurrency', [ 'value' => 8 ] );

        $this->assertSame(
            [
                'wp_wp2static_core_options',
                [ 'value' => 8 ],
                [ 'name' => 'crawlConcurrency' ],
            ],
            $captured
        );
    }
}
