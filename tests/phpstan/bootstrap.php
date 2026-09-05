<?php

// We use core constants instead of core functions
define( 'WPINC', '' );
define( 'WP_CONTENT_DIR', '' );
define( 'WP_PLUGIN_DIR', '' );

/*
 * Le costanti del plugin. PHPStan non le prende da vibestatic.php: quel file
 * definisce anche i due alias storici come `define( 'WP2STATIC_PATH',
 * VIBESTATIC_PATH )`, cioe' una costante il cui valore e' un'altra costante, e
 * l'analisi statica non lo risolve. Dichiararle qui e' anche il modo di dire
 * quali sono i nomi buoni.
 */
define( 'VIBESTATIC_VERSION', '' );
define( 'VIBESTATIC_PATH', '' );

// I due nomi vecchi, mantenuti come alias per gli addon.
define( 'WP2STATIC_VERSION', '' );
define( 'WP2STATIC_PATH', '' );

// Le costanti di tempo di WordPress, per le stesse ragioni del bootstrap dei test.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
