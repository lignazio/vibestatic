<?php

namespace WP2StaticS3;

use WP_CLI;

/**
 * `wp vibestatic s3 options get|set|list`
 *
 * @package WP2StaticS3
 */
class CLI {

    /**
     * @param string[] $args       CLI args.
     * @param string[] $assoc_args CLI args.
     */
    public static function s3( array $args, array $assoc_args ) : void {
        if ( 'options' !== ( $args[0] ?? '' ) ) {
            WP_CLI::error( 'Missing required argument: <options>' );
        }

        switch ( $args[1] ?? '' ) {
            case 'get':
                $name = $args[2] ?? '';

                if ( '' === $name ) {
                    WP_CLI::error( 'Missing required argument: <option-name>' );
                }

                WP_CLI::line( self::readable( $name ) );
                break;

            case 'set':
                $name = $args[2] ?? '';
                $value = $args[3] ?? '';

                if ( '' === $name ) {
                    WP_CLI::error( 'Missing required argument: <option-name>' );
                }

                Controller::saveOption(
                    $name,
                    'secret_key' === $name
                        ? \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $value )
                        : $value
                );
                break;

            case 'list':
                /*
                 * The secret key is not printed. `options list` is the
                 * command whose output gets pasted into a bug report, and the
                 * add-on this replaces printed the decrypted key in it.
                 */
                $rows = [];

                foreach ( array_keys( Controller::DEFAULTS ) as $name ) {
                    $rows[] = [ 'name' => $name, 'value' => self::readable( $name, true ) ];
                }

                WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'value' ] );
                break;

            default:
                WP_CLI::error( 'Missing required argument: <get|set|list>' );
        }
    }

    private static function readable( string $name, bool $mask = false ) : string {
        $value = Controller::getValue( $name );

        if ( 'secret_key' !== $name ) {
            return $value;
        }

        if ( '' === $value ) {
            return $mask ? 'not set' : '';
        }

        if ( $mask ) {
            return 'set (hidden)';
        }

        return (string) \WP2Static\CoreOptions::encrypt_decrypt( 'decrypt', $value );
    }
}
