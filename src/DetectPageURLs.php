<?php

namespace WP2Static;

class DetectPageURLs {

    /**
     * Detect Page URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $page_urls = [];

        $page_ids = $wpdb->get_col(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_type = 'page'"
        );

        foreach ( $page_ids as $page_id ) {
            /*
             * `get_col()` answers strings, and a null among them is possible.
             * `get_page_link( 0 )` does not mean "no post": it means the one in the
             * global $post, which during a detector run is whatever was left
             * there.
             */
            if ( ! is_numeric( $page_id ) ) {
                continue;
            }

            $permalink = get_page_link( (int) $page_id );

            if ( strpos( $permalink, '?post_type' ) !== false ) {
                continue;
            }

            $page_urls[] = $permalink;
        }

        return $page_urls;
    }
}
