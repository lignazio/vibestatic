<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WPCronTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param string $user
     * @param string $password
     */
    private function credentials( $user, $password ) : void {
        Mockery::mock( 'overload:\WP2Static\CoreOptions' )
            ->shouldReceive( 'getValue' )->andReturnUsing(
                function ( $name ) use ( $user, $password ) {
                    if ( 'basicAuthUser' === $name ) {
                        return $user;
                    }

                    if ( 'basicAuthPassword' === $name ) {
                        return $password;
                    }

                    return '';
                }
            );
    }

    /**
     * @return mixed[]
     */
    private function cronRequest() : array {
        return [
            'url' => 'https://esempio.it/wp-cron.php?doing_wp_cron=1',
            'key' => '1',
            'args' => [
                'timeout' => 0.01,
                'blocking' => false,
                'sslverify' => true,
            ],
        ];
    }

    public function testTheWholeRequestComesBackWithTheHeaderAdded() : void {
        $this->credentials( 'staging', 'segreto' );

        $request = WPCron::wp2static_cron_with_http_basic_auth( $this->cronRequest() );

        /*
         * This is the defect: the function returned the headers alone. Right
         * afterwards WordPress does
         * `wp_remote_post( $cron_request['url'], $cron_request['args'] )`, so
         * with no `url` and no `args` WP-Cron does not fire — and it fails to
         * fire only with basic auth configured, which is exactly the case this
         * function exists for.
         */
        $this->assertSame(
            'https://esempio.it/wp-cron.php?doing_wp_cron=1',
            $request['url']
        );
        $this->assertSame( '1', $request['key'] );
        $this->assertFalse( $request['args']['blocking'] );
        $this->assertSame( 0.01, $request['args']['timeout'] );

        $this->assertSame(
            'Basic ' . base64_encode( 'staging:segreto' ),
            $request['args']['headers']['Authorization']
        );
    }

    public function testExistingHeadersAreKept() : void {
        $this->credentials( 'staging', 'segreto' );

        $original = $this->cronRequest();
        $original['args']['headers'] = [ 'X-Da-Un-Addon' => 'si' ];

        $request = WPCron::wp2static_cron_with_http_basic_auth( $original );

        // Another plugin may have hooked the same filter before us:
        // overwriting its headers would break it.
        $this->assertSame( 'si', $request['args']['headers']['X-Da-Un-Addon'] );
        $this->assertArrayHasKey( 'Authorization', $request['args']['headers'] );
    }

    public function testWithoutCredentialsTheRequestIsUntouched() : void {
        $this->credentials( '', '' );

        $original = $this->cronRequest();

        $this->assertSame(
            $original,
            WPCron::wp2static_cron_with_http_basic_auth( $original )
        );
    }

    public function testAUserWithoutAPasswordIsNotEnough() : void {
        $this->credentials( 'staging', '' );

        $original = $this->cronRequest();

        // Sending a Basic header with an empty password does not merely fail to
        // authenticate: it fails the request with a 401 instead of going
        // without the header at all.
        $this->assertSame(
            $original,
            WPCron::wp2static_cron_with_http_basic_auth( $original )
        );
    }
}
