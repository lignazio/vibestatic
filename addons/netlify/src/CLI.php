<?php

namespace WP2StaticNetlify;

use WP_CLI;

/**
 * `wp vibestatic netlify options get|set|list`
 *
 * @package WP2StaticNetlify
 */
class CLI {

    /**
     * @param string[] $args       CLI args.
     * @param string[] $assoc_args CLI args.
     */
    public static function netlify( array $args, array $assoc_args ) : void {
        if ( 'options' !== ( $args[0] ?? '' ) ) {
            WP_CLI::error( 'Missing required argument: <options>' );
        }

        $action = $args[1] ?? '';

        switch ( $action ) {
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
                    'accessToken' === $name
                        ? \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $value )
                        : $value
                );
                break;

            case 'list':
                /*
                 * The token is not printed. `options list` is the command
                 * whose output gets pasted into a bug report, and the original
                 * printed the decrypted token in it — the same defect the core
                 * had on its Diagnostics page.
                 */
                $rows = [];

                foreach ( [ 'siteID', 'accessToken' ] as $name ) {
                    $rows[] = [
                        'name' => $name,
                        'value' => self::readable( $name, true ),
                    ];
                }

                WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'value' ] );
                break;

            default:
                WP_CLI::error( 'Missing required argument: <get|set|list>' );
        }
    }

    /**
     * An option's value, with the token hidden when asked for in bulk.
     */
    private static function readable( string $name, bool $mask = false ) : string {
        $value = Controller::getValue( $name );

        if ( 'accessToken' !== $name ) {
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
