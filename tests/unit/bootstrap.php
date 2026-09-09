<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor-prefixed/autoload.php';

WP_Mock::bootstrap();

/*
 * The adopted add-ons' autoloaders. They are separate plugins with namespaces
 * of their own, so the core's PSR-4 map does not reach them — and without this
 * their classes cannot be exercised at all, which is how an add-on that
 * uploaded on every deploy went five years without anyone noticing.
 */
foreach ( glob( __DIR__ . '/../../addons/*/autoload.php' ) as $addon_autoloader ) {
    require_once $addon_autoloader;
}

/*
 * WordPress's time constants. WP_Mock does not define them, because it replaces
 * the functions and not the core: without these, any class writing
 * `12 * HOUR_IN_SECONDS` dies with "Undefined constant" inside the test rather
 * than in the code.
 */
foreach (
    [
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS' => 3600,
        'DAY_IN_SECONDS' => 86400,
        'WEEK_IN_SECONDS' => 604800,
    ] as $constant => $seconds
) {
    if ( ! defined( $constant ) ) {
        define( $constant, $seconds );
    }
}

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

/*
 * The four filesystem and URL wrappers WordPress asks plugins to prefer over
 * the bare PHP functions, and which the plugin directory's Plugin Check treats
 * as errors when they are missing. Each is the real one's behaviour, not a
 * stand-in that always succeeds: wp_delete_file() returns nothing — that is why
 * FilesHelper has to look at the file afterwards — and wp_mkdir_p() creates
 * recursively.
 */
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'wp_delete_file' ) ) {
    function wp_delete_file( $file ) {
        @unlink( $file );
    }
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) {
        return is_dir( $target ) || mkdir( $target, 0777, true );
    }
}

if ( ! function_exists( 'wp_is_writable' ) ) {
    function wp_is_writable( $path ) {
        return is_writable( $path );
    }
}

/**
 * Gives a \WPDB mock a prepare() that behaves like the real one.
 *
 * Needed since all the plugin's queries go through $wpdb->prepare(): without
 * it, tests that mock $wpdb would fail with "Method prepare() does not exist",
 * and mocking it to return the string unchanged would make the tests blind on
 * the very point that matters — that identifiers end up in backticks and values
 * in quotes.
 *
 * It covers the three placeholders the plugin uses: %i (identifier), %s
 * (string), %d (integer). It is not WordPress's prepare() and does not try to
 * be: it is just enough for the assertion on the final SQL to stay meaningful.
 *
 * @param \Mockery\MockInterface $wpdb
 */
function wp2static_test_mock_prepare( $wpdb ) : void {
    $wpdb->shouldReceive( 'prepare' )
        ->andReturnUsing(
            function ( $query, ...$args ) {
                // prepare() accepts either a list of arguments or a single array.
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
