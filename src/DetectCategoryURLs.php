<?php

namespace WP2Static;

class DetectCategoryURLs {

    /**
     * Detect Category URLs
     *
     * @return string[] list of URLs
     */
    public static function detect() : array {
        global $wpdb;

        $args = [ 'public' => true ];

        $taxonomies = get_taxonomies( $args, 'objects' );

        $category_urls = [];

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

                $category_urls[] = $permalink;
            }
        }

        return $category_urls;
    }
}
