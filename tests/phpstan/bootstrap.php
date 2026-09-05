<?php

// We use core constants instead of core functions
define( 'WPINC', '' );
define( 'WP_CONTENT_DIR', '' );
define( 'WP_PLUGIN_DIR', '' );

/*
 * The plugin's constants. PHPStan does not pick them up from vibestatic.php:
 * that file also defines the two historical aliases as
 * `define( 'WP2STATIC_PATH', VIBESTATIC_PATH )`, that is, a constant whose value
 * is another constant, which static analysis does not resolve. Declaring them
 * here is also how the good names are stated.
 */
define( 'VIBESTATIC_VERSION', '' );
define( 'VIBESTATIC_PATH', '' );

// The two old names, kept as aliases for add-ons.
define( 'WP2STATIC_VERSION', '' );
define( 'WP2STATIC_PATH', '' );

// WordPress's time constants, for the same reasons as the test bootstrap.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
