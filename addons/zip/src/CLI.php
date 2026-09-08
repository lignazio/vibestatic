<?php

namespace WP2StaticZip;

use WP_CLI;

/**
 * `wp vibestatic zip get_path|get_url`
 *
 * @package WP2StaticZip
 */
class CLI {

    /**
     * @param string[] $args       CLI args.
     * @param string[] $assoc_args CLI args.
     */
    public static function zip( array $args, array $assoc_args ) : void {
        $action = isset( $args[0] ) ? $args[0] : '';

        switch ( $action ) {
            case 'get_path':
                WP_CLI::line( Controller::path() );
                break;
            case 'get_url':
                /*
                 * Still the uploads URL, and it is worth being plain about it:
                 * the archive is readable there by anyone who knows the
                 * address, exactly as the generated site already is. The admin
                 * download goes through admin-post so a link is not the only
                 * way in; this command reports where the file actually is.
                 */
                WP_CLI::line(
                    \WP2Static\SiteInfo::getUrl( 'uploads' ) . Controller::FILENAME
                );
                break;
            default:
                WP_CLI::error( 'Missing required argument: <get_path|get_url>' );
        }
    }
}
