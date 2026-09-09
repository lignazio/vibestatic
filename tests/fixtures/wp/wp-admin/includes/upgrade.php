<?php
/**
 * Stands in for the file Options::install() requires.
 *
 * `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` is how WordPress
 * makes dbDelta() available, and the require has to succeed for install() to
 * be testable at all. dbDelta() itself is stubbed by the test; this file only
 * has to exist.
 *
 * @package WP2Static
 */
