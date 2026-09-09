<?php
/**
 * `wp2static directory_deployment options get|set|list`
 *
 * @package WP2StaticDirectoryDeployer
 */

namespace WP2StaticDirectoryDeployer;

use WP2Static\Addon\OptionsCommand;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     *
     * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
     */
    public static function directory_deployment( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        OptionsCommand::run( Controller::instance()->options(), $args );
    }
}
