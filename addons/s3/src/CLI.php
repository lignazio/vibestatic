<?php
/**
 * `wp2static s3 options get|set|list`
 *
 * @package WP2StaticS3
 */

namespace WP2StaticS3;

use WP2Static\Addon\OptionsCommand;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     */
    public static function s3( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        OptionsCommand::run( Controller::instance()->options(), $args );
    }
}
