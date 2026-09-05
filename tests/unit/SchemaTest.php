<?php

namespace WP2Static;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SchemaTest extends TestCase {

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    public function testAFreshSiteNeedsTheSchema() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => false ] );

        $this->assertTrue( Schema::needsUpdate() );
    }

    public function testASiteAtTheCurrentVersionDoesNot() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) Schema::VERSION ] );

        $this->assertFalse( Schema::needsUpdate() );
    }

    public function testASiteAtAnOlderVersionDoes() : void {
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) ( Schema::VERSION - 1 ) ] );

        /*
         * This is the case that did not exist before: tables were created only
         * inside register_activation_hook, which does not fire when the plugin
         * is updated. A column added in a new version would never have reached
         * sites that already had it installed.
         */
        $this->assertTrue( Schema::needsUpdate() );
    }

    public function testNothingHappensOnAFrontEndRequest() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => false ] );

        // The comparison costs one read of an autoloaded option; dbDelta does
        // not. Letting a visitor's request trigger it means making that visitor
        // pay for the upgrade.
        WP_Mock::userFunction( 'get_option', [ 'times' => 0 ] );

        Schema::updateIfNeeded();

        $this->assertTrue( true );
    }

    public function testAnAdminRequestAtTheCurrentVersionWritesNothing() : void {
        WP_Mock::userFunction( 'is_admin', [ 'return' => true ] );
        WP_Mock::userFunction( 'get_option', [ 'return' => (string) Schema::VERSION ] );
        WP_Mock::userFunction( 'update_option', [ 'times' => 0 ] );

        Schema::updateIfNeeded();

        $this->assertTrue( true );
    }
}
