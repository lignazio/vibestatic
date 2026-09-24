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
                 * Where the file is, not a way in. The directory it sits in
                 * carries an `.htaccess` and a `web.config` that deny access,
                 * so on Apache and IIS this address answers 403 — as it should.
                 * The way to the archive is the admin page's Download button,
                 * which checks the capability and the nonce; on disk it is
                 * `get_path` below.
                 */
                WP_CLI::line(
                    \WP2Static\SiteInfo::getUrl( 'uploads' ) .
                    \WP2Static\StorageDir::DIRNAME . '/' . Controller::FILENAME
                );
                break;
            default:
                WP_CLI::error( 'Missing required argument: <get_path|get_url>' );
        }
    }
}
