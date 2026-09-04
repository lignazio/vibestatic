<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor-prefixed/autoload.php';

WP_Mock::bootstrap();

if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $string ) {
        return rtrim( $string, '/\\' );
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}

/**
 * Dà a un mock di \WPDB una prepare() che si comporta come quella vera.
 *
 * Serve da quando le query del plugin passano tutte da $wpdb->prepare(): senza,
 * i test che mockano $wpdb fallirebbero con "Method prepare() does not exist",
 * e mockarla per farle restituire la stringa così com'è renderebbe i test
 * ciechi proprio sul punto che conta — cioè che gli identificatori finiscano
 * fra backtick e i valori fra apici.
 *
 * Copre i tre segnaposto che il plugin usa: %i (identificatore), %s (stringa),
 * %d (intero). Non è la prepare() di WordPress e non vuole esserlo: è quel
 * tanto che basta perché l'asserzione sul SQL finale resti significativa.
 *
 * @param \Mockery\MockInterface $wpdb
 */
function wp2static_test_mock_prepare( $wpdb ) : void {
    $wpdb->shouldReceive( 'prepare' )
        ->andReturnUsing(
            function ( $query, ...$args ) {
                // prepare() accetta sia una lista di argomenti sia un array solo.
                if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                    $args = $args[0];
                }

                return preg_replace_callback(
                    '/%[isd]/',
                    function ( $match ) use ( &$args ) {
                        $value = array_shift( $args );

                        switch ( $match[0] ) {
                            case '%i':
                                return '`' . str_replace( '`', '``', (string) $value ) . '`';
                            case '%d':
                                return (string) (int) $value;
                            default:
                                return "'" . addslashes( (string) $value ) . "'";
                        }
                    },
                    $query
                );
            }
        );
}
