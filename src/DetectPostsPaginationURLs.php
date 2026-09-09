<?php

namespace WP2Static;

class DetectPostsPaginationURLs {

    /**
     * Detect Post pagination URLs
     *
     * @return string[] list of URLs
     */
    public static function detect( string $wp_site_url ) : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $post_urls = [];
        $unique_post_types = [];

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ID, post_type FROM %i
                 WHERE post_status = %s
                 AND post_type NOT IN ( %s, %s )',
                $wpdb->posts,
                'publish',
                'revision',
                'nav_menu_item'
            )
        );

        /** @var list<object{post_type: string}> $rows */
        $rows = $posts ?? [];

        foreach ( $rows as $post ) {
            // capture all post types
            $unique_post_types[] = $post->post_type;
        }

        // get all pagination links for each post_type
        $post_types = array_unique( $unique_post_types );
        $pagination_base = URLHelper::paginationBase();

        /*
         * `posts_per_page` is an option, so it is whatever is in the database
         * or whatever a filter made of it, and it divides a total below.
         * WordPress's own default is 10, and a zero or a negative would be a
         * division by zero rather than a page count.
         */
        $posts_per_page = get_option( 'posts_per_page' );
        $default_posts_per_page = is_numeric( $posts_per_page ) ? (int) $posts_per_page : 10;

        if ( $default_posts_per_page < 1 ) {
            $default_posts_per_page = 10;
        }

        $urls_to_include = [];

        foreach ( $post_types as $post_type ) {
            $post_type_total = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE post_status = %s AND post_type = %s',
                    $wpdb->posts,
                    'publish',
                    $post_type
                )
            );

            if ( ! $post_type_total ) {
                continue;
            }

            $post_type_obj = get_post_type_object( $post_type );

            if ( ! $post_type_obj ) {
                continue;
            }

            // cast WP's object back to array
            $post_type_labels = (array) $post_type_obj->labels;

            $label = $post_type_labels['name'] ?? '';

            // A post type's labels object is whatever registered it built, and
            // a plugin can put anything on it.
            if ( ! is_string( $label ) ) {
                continue;
            }

            $plural_form = strtolower( $label );

            // skip post type names containing spaces
            if ( strpos( $plural_form, ' ' ) !== false ) {
                continue;
            }

            $total_pages = (int) ceil( (int) $post_type_total / $default_posts_per_page );

            /*
             * Outside the page loop. The posts page is read from the settings
             * and cannot change while the URLs are being generated: in here it
             * was two reads per pagination page, hundreds on a real site, all
             * with the same answer. It is also why the test that claimed "once
             * only" was never verified: nobody was checking that file's
             * expectations.
             */
            $post_archive_slug = '';

            // check if a Posts page has been set in Settings > Reading
            if ( 'post' === $post_type && get_option( 'page_for_posts' ) !== '0' ) {
                // get FQURL to Posts Page
                $post_archive_link = get_post_type_archive_link( 'post' );

                if ( $post_archive_link ) {
                    $post_archive_slug = str_replace(
                        $wp_site_url,
                        '',
                        trailingslashit( $post_archive_link )
                    );
                }
            }

            for ( $page = 1; $page <= $total_pages; $page++ ) {
                // TODO: skipping page pagination here, but is it covered elsewhere?
                if ( $post_type === 'page' ) {
                    continue;
                }

                if ( $post_type === 'post' ) {
                    $urls_to_include[] = "/{$post_archive_slug}{$pagination_base}/{$page}/";
                } else {
                    $urls_to_include[] =
                        "/{$plural_form}/{$pagination_base}/{$page}/";
                }
            }
        }

        return $urls_to_include;
    }
}
