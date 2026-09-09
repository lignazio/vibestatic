<?php

namespace WP2Static;

class DetectPostURLs {

    /**
     * Detect Post URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $post_urls = [];

        $post_ids = $wpdb->get_col(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_type = 'post'"
        );

        foreach ( $post_ids as $post_id ) {
            /*
             * `get_col()` answers strings, and a null among them is possible.
             * `get_permalink( 0 )` does not mean "no post": it means the one in
             * the global $post, which during a detector run is whatever was
             * left there.
             */
            if ( ! is_numeric( $post_id ) ) {
                continue;
            }

            $permalink = get_permalink( (int) $post_id );

            if ( ! $permalink ) {
                continue;
            }

            if ( strpos( $permalink, '?post_type' ) !== false ) {
                continue;
            }

            $post_urls[] = $permalink;
        }

        return $post_urls;
    }
}
