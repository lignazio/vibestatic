<?php

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class DetectPluginAssets {

    /**
     * Detect Plugin asset URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        $files = [];

        $plugins_path = SiteInfo::getPath( 'plugins' );
        $plugins_url = SiteInfo::getUrl( 'plugins' );

        if ( is_dir( $plugins_path ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $plugins_path,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            /**
             * @var string[] $active_plugins
             */
            $active_plugins = get_option( 'active_plugins' );
            /**
             * @var string[] $active_sitewide_plugins
             */
            $active_sitewide_plugins = get_option( 'active_sitewide_plugins' );

            if ( is_multisite() ) {
                $active_plugins = array_unique(
                    array_merge(
                        $active_plugins,
                        array_keys( $active_sitewide_plugins )
                    )
                );
            }

            $active_plugin_dirs = array_map(
                function ( $active_plugin ) {
                    return explode( '/', $active_plugin )[0];
                },
                $active_plugins
            );

            // Normalised once, with the same rules applied to the file paths
            // a little further down (Windows).
            $plugins_prefix = rtrim( str_replace( '\\', '/', $plugins_path ), '/' ) . '/';

            /*
             * The path of the plugins URL, without its scheme and host.
             *
             * What was here before built the absolute URL and then removed
             * `get_home_url()` from it by string comparison. The two do not
             * always agree on the scheme: `plugins_url()` passes through
             * `set_url_scheme()`, which chooses http or https from `is_ssl()`,
             * and from WP-CLI — where a scheduled or scripted export runs —
             * `is_ssl()` is false. On an https site the first string was then
             * http:// and the second https://, nothing matched, and the
             * absolute URL travelled on into the crawl queue as if it were a
             * path.
             *
             * Measured on a real site exported from the command line: 297
             * plugin assets written under a directory named
             * `wp2static-crawled-sitehttp:` — outside the crawled site, so
             * never post-processed and never deployed.
             */
            $plugins_url_path =
                rtrim( (string) wp_parse_url( $plugins_url, PHP_URL_PATH ), '/' ) . '/';

            foreach ( $iterator as $filename => $file_object ) {
                /**
                 * @var string $filename
                 */

                $path_crawlable =
                    FilesHelper::filePathLooksCrawlable( $filename );

                if ( ! $path_crawlable ) {
                    continue;
                }

                // Standardise all paths to use / (Windows support)
                $filename = str_replace( '\\', '/', $filename );

                /*
                 * The comparison used to be
                 * `str_replace( $active_plugin_dirs, '', $filename ) !== $filename`,
                 * that is, "the name of an active plugin appears somewhere in
                 * the ABSOLUTE path". That is not the same question as "this
                 * file is inside an active plugin's directory", and the
                 * difference shows the moment the site's path happens to
                 * contain the name of an active plugin: from then on any file
                 * of any plugin passes, deactivated ones included — code the
                 * site owner deliberately switched off, published anyway.
                 *
                 * Measured here: the working directory was named `wp2static`,
                 * so every absolute path contained that string, and Akismet's
                 * fourteen files — an inactive plugin — were exported on every
                 * deploy.
                 *
                 * The right question is about the first segment after
                 * `plugins/`.
                 */
                if ( 0 !== strpos( $filename, $plugins_prefix ) ) {
                    continue;
                }

                $plugin_dir = explode( '/', substr( $filename, strlen( $plugins_prefix ) ) )[0];

                if ( ! in_array( $plugin_dir, $active_plugin_dirs, true ) ) {
                    continue;
                }

                $detected_filename =
                    $plugins_url_path . substr( $filename, strlen( $plugins_prefix ) );

                array_push(
                    $files,
                    $detected_filename
                );
            }
        }

        return $files;
    }
}
