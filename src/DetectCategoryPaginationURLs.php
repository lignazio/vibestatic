<?php

namespace WP2Static;

class DetectCategoryPaginationURLs {

    /**
     * Detect Category Pagination URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        global $wpdb;

        // first we get each category with total posts as an array
        // similar to getting regular category URLs, but with extra
        // info we need to get correct pagination URLs
        $args = [ 'public' => true ];

        $category_links = [];
        $urls_to_include = [];
        $taxonomies = get_taxonomies( $args, 'objects' );
        $pagination_base = URLHelper::paginationBase();
        /*
         * An option, so it is whatever is in the database or whatever a filter
         * made of it, and it divides a total below: a zero or a negative would
         * be a division by zero rather than a page count. Ten is WordPress's
         * own default.
         */
        $posts_per_page = get_option( 'posts_per_page' );
        $default_posts_per_page = is_numeric( $posts_per_page ) ? (int) $posts_per_page : 10;

        if ( $default_posts_per_page < 1 ) {
            $default_posts_per_page = 10;
        }

        foreach ( $taxonomies as $taxonomy ) {
            /*
             * The current signature. `get_terms( $taxonomy, $args )` is the
             * pre-4.5 one: WordPress still honours it — it detects the legacy
             * shape and moves the arguments across — but it is the reason this
             * call carried an ignore comment, and an ignore comment over a
             * deprecated call is two problems written as none.
             *
             * The phrase had to be paraphrased rather than quoted: PHPStan
             * reads its own directives out of any comment, including one
             * explaining that a directive used to be here.
             */
            $terms = get_terms(
                [
                    'taxonomy' => $taxonomy->name,
                    'hide_empty' => true,
                ]
            );

            /*
             * get_terms() answers a WP_Error for a taxonomy that does not
             * exist. Iterating one walks its public properties instead of any
             * terms, silently.
             */
            if ( ! is_array( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                $term_link = get_term_link( $term );

                if ( ! is_string( $term_link ) ) {
                    continue;
                }
                $permalink = trim( $term_link );

                $total_posts = $term->count;

                $term_url = $permalink;

                $category_links[ $term_url ] = $total_posts;
            }
        }

        foreach ( $category_links as $term => $total_posts ) {
            $total_pages = ceil( $total_posts / $default_posts_per_page );

            for ( $page = 1; $page <= $total_pages; $page++ ) {
                $urls_to_include[] =
                    "{$term}{$pagination_base}/{$page}/";
            }
        }

        return $urls_to_include;
    }
}
