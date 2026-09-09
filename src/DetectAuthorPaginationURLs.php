<?php

namespace WP2Static;

class DetectAuthorPaginationURLs {

    /**
     * Detect Author Pagination URLs
     *
     * @return string[] list of URLs
     */
    public static function detect( string $wp_site_url ) : array {
        global $wpdb;

        $public = true;
        $authors_urls = [];
        $urls_to_include = [];
        $users = get_users();
        $pagination_base = URLHelper::paginationBase();

        /**
         * @var int $default_posts_per_page
         */
        $default_posts_per_page = get_option( 'posts_per_page' );

        foreach ( $users as $author ) {
            /*
             * What this loop needs is an object with a numeric id, not a
             * WP_User specifically. `get_users()` answers whatever its `fields`
             * argument asks for, and a `pre_get_users` or `users_pre_query`
             * filter can put anything in there; reading `->ID` off a string is
             * a fatal error in the middle of a crawl. Asking for the shape the
             * code uses is both the honest check and the one that lets the
             * calls below have the int they are declared to take.
             */
            if ( ! is_object( $author ) || ! isset( $author->ID ) || ! is_numeric( $author->ID ) ) {
                continue;
            }

            $author_id = (int) $author->ID;

            $author_link = get_author_posts_url( $author_id );

            /*
             * Cast and then checked for emptiness, rather than checked for
             * type. The stubs declare this returns a string; WordPress runs the
             * value through the `author_link` filter first, so it can be
             * anything, and a filter that forgets to return is the commonest
             * filter mistake there is. `(string) null` is '', which this then
             * skips — an author with no URL is not a page to crawl, and the
             * test says so.
             */
            $permalink = trim( (string) $author_link );

            if ( '' === $permalink ) {
                continue;
            }

            $total_posts = count_user_posts( $author_id, 'post', $public );

            $author_url = str_replace(
                $wp_site_url,
                '',
                $permalink
            );

            $authors_urls [ $author_url ] = $total_posts;
        }

        foreach ( $authors_urls as $author => $total_posts ) {
            /**
             * @var int $total_posts
             */

            $total_pages = ceil( $total_posts / $default_posts_per_page );

            for ( $page = 1; $page <= $total_pages; $page++ ) {
                $urls_to_include[] =
                    "/{$author}{$pagination_base}/{$page}/";
            }
        }

        return $urls_to_include;
    }
}
