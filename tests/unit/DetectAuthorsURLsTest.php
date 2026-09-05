<?php

namespace WP2Static;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class DetectAuthorsURLsTest extends TestCase {

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
        $users = [];

        // Create some virtual users
        for ( $i = 1; $i <= 3; $i++ ) {
            // Add the user
            $users[] = (object) [ 'ID' => $i ];

            // Create an author URL for this user
            \WP_Mock::userFunction(
                'get_author_posts_url',
                [
                    'times' => 1,
                    'args' => [ $i ],
                    'return' => "{$site_url}users/{$i}",
                ]
            );
        }

        // create user missing author URL
        $users[] = (object) [ 'ID' => 4 ];
        \WP_Mock::userFunction(
            'get_author_posts_url',
            [
                'times' => 1,
                'args' => [ 4 ],
                'return' => null,
            ]
        );

        \WP_Mock::userFunction(
            'get_users',
            [
                'times' => 1,
                'return' => $users,
            ]
        );

        $expected = [
            "{$site_url}users/1",
            "{$site_url}users/2",
            "{$site_url}users/3",
        ];
        $actual = DetectAuthorsURLs::detect();
        $this->assertEquals( $expected, $actual );
    }
}
