<?php

namespace WP2Static;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class DetectPageURLsTest extends TestCase {

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
        global $wpdb;
        $site_url = 'https://foo.com/';

        // Create 3 attachments
        // @phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $wpdb = Mockery::mock( '\WPDB' );
        $wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( [ 1, 2, 3 ] );
        $wpdb->posts = 'wp_posts';

        // And URLs for them
        for ( $i = 1; $i <= 3; $i++ ) {
            \WP_Mock::userFunction(
                'get_page_link',
                [
                    'times' => 1,
                    'args' => [ $i ],
                    'return' => "{$site_url}page/$i/",
                ]
            );
        }

        $expected = [
            "{$site_url}page/1/",
            "{$site_url}page/2/",
            "{$site_url}page/3/",
        ];
        $actual = DetectPageURLs::detect();
        $this->assertEquals( $expected, $actual );
    }
}
