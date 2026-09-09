<?php
/**
 * `wp2static sftp options get|set|list`
 *
 * @package WP2StaticSFTP
 */

namespace WP2StaticSFTP;

use WP2Static\Addon\OptionsCommand;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     */
    public static function sftp( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        OptionsCommand::run( Controller::instance()->options(), $args );
    }
}
