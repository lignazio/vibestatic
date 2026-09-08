<?php
/**
 * The source addresses of the redirects the Redirection plugin manages.
 *
 * **What this buys, and why it is a detector rather than anything cleverer:**
 * a static site expresses a redirect only if the crawler saw one. The core
 * already collects them — a URL that answers 3xx is recorded in the crawl cache
 * with its target, and `wp2static_list_redirects` hands that list to the
 * deployers. But a redirect's source address is not a post, an archive or an
 * asset: nothing detects it, so it was never requested, so it never answered
 * 301, so it never reached that list. Queueing the source is the whole job; the
 * rest of the machinery was already there.
 *
 * Redirection is on more than two million sites, which is the reason this one
 * plugin gets a detector of its own rather than a general mechanism nobody
 * would configure.
 *
 * @package WP2Static
 */

namespace WP2Static;

class DetectRedirectionPluginURLs {

    const TABLE = 'redirection_items';

    /**
     * @return string[] Absolute URLs to crawl.
     */
    public static function detect( string $site_url ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE;

        /*
         * The table belongs to another plugin, so its absence is the normal
         * case rather than an error: without this check every detection run on
         * a site without Redirection would log a database error.
         */
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( $table_name !== $exists ) {
            return [];
        }

        /** @var list<object{url: string}>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // `regex = 0`: a regular-expression redirect has no single
                // source address to request, so there is nothing to queue.
                'SELECT url FROM %i WHERE status = %s AND regex = %d',
                $table_name,
                'enabled',
                0
            )
        );

        $site_url = untrailingslashit( $site_url );
        $urls = [];

        foreach ( $rows ?? [] as $row ) {
            $path = URLHelper::pathOnThisSite( $row->url, $site_url );

            if ( null === $path ) {
                continue;
            }

            $urls[] = $site_url . $path;
        }

        return $urls;
    }
}
