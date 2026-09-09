<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class DetectCategoryURLsTest extends TestCase {

    public function setUp() : void {
        \WP_Mock::setUp();
    }

    public function tearDown() : void {
        // Without these two, the expectations registered with
        // WP_Mock::userFunction were never checked: they stayed in Mockery's
        // global container and were checked, by accident, by the first later
        // test that called Mockery::close(). That is, the `'times' => 1`
        // written in here asserted nothing.
        \WP_Mock::tearDown();
        \Mockery::close();
    }


    public function testDetect() {
        $site_url = 'https://foo.com/';
        $taxonomies = [
            (object) [ 'name' => 'category' ],
            (object) [ 'name' => 'post_tag' ],
        ];
        $terms = [
            'category' => [
                'category1' => (object) [
                    'name' => 'category1',
                    'count' => 1,
                ],
                'category2' => (object) [
                    'name' => 'category2',
                    'count' => 3,
                ],
                'category3' => (object) [
                    'name' => 'category3',
                    'count' => 4,
                ],
            ],
            'post_tag' => [
                'post_tag1' => (object) [
                    'name' => 'post_tag1',
                    'count' => 14,
                ],
            ],
        ];
        $term_links = [
            'category1' => "{$site_url}category/1",
            'category2' => "{$site_url}category/2",
            'category3' => "{$site_url}category/3",
            'post_tag1' => "{$site_url}tags/foo/bar",
        ];

        // Set pagination to 3 posts per page
        // There was no place here for `get_option( 'posts_per_page' )`:
        // DetectCategoryURLs never calls it — pagination is computed by
        // DetectCategoryPaginationURLs, a different class. The expectation was
        // there and said "once", but nobody was checking it.

        // Set up our custom taxonomies
        \WP_Mock::userFunction(
            'get_taxonomies',
            [
                'times' => 1,
                'args' => [
                    [ 'public' => true ],
                    'objects',
                ],
                'return' => $taxonomies,
            ]
        );
        foreach ( $taxonomies as $taxonomy ) {
            // And the terms within those taxonomies
            \WP_Mock::userFunction(
                'get_terms',
                [
                    'times' => 1,
                    // The current signature, one argument: `get_terms( $args )`.
                    // It used to assert the pre-4.5 shape, which WordPress
                    // still honours but only by detecting it and moving the
                    // arguments across.
                    'args' => [
                        [
                            'taxonomy' => $taxonomy->name,
                            'hide_empty' => true,
                        ],
                    ],
                    'return' => $terms[ $taxonomy->name ],
                ]
            );

            // ...and the links for those terms
            foreach ( $terms[ $taxonomy->name ] as $term ) {
                \WP_Mock::userFunction(
                    'get_term_link',
                    [
                        'times' => 1,
                        'args' => [ $term ],
                        'return' => $term_links[ $term->name ],
                    ]
                );
            }
        }

        $expected = [
            "{$site_url}category/1",
            "{$site_url}category/2",
            "{$site_url}category/3",
            "{$site_url}tags/foo/bar",
        ];
        $actual = DetectCategoryURLs::detect();
        $this->assertEquals( $expected, $actual );
    }
}
