<?php
/**
 * `wp2static netlify options get|set|list`
 *
 * The command name keeps `wp2static`, not `vibestatic`: it is what any script
 * that deploys this site already types.
 *
 * A thin, **static** entry point. Upstream registered a static callable while
 * declaring the method non-static, which on PHP 8 is an Error the first time
 * anybody runs it.
 *
 * @package WP2StaticNetlify
 */

namespace WP2StaticNetlify;

use WP2Static\Addon\OptionsCommand;

class CLI {

    /**
     * @param string[] $args       Positional arguments.
     * @param string[] $assoc_args Flags. Unused; WP-CLI passes them regardless.
     */
    public static function netlify( array $args, array $assoc_args = [] ) : void {
        unset( $assoc_args );

        OptionsCommand::run( Controller::instance()->options(), $args );
    }
}
