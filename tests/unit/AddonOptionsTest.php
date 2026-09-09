<?php
/**
 * WP2Static\Addon\Options, which every add-on uses and which the six bundled
 * modules each used to hand-write.
 *
 * These are the four defects the upstream boilerplate had, each with a test:
 * duplicate seed rows, a save that did nothing when the row was absent, table
 * names interpolated into SQL, and secrets written in the clear.
 *
 * @package WP2Static
 */

namespace WP2Static\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\Addon\Options;

/**
 * Constants are defined here, so a process of its own: CoreOptionsTest asserts
 * that encryption REFUSES to run when AUTH_KEY and AUTH_SALT are missing, and
 * these tests need them present.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AddonOptionsTest extends TestCase {

    protected function setUp() : void {
        parent::setUp();

        \WP_Mock::setUp();

        /*
         * The sanitisers savePosted() calls. Real enough to test with: the
         * point of these tests is which value reaches the database and in what
         * shape, not what WordPress's own sanitisers do to it — and stubbing
         * them as identity would hide the one thing that matters here, that an
         * array posted where a string is expected comes back as ''.
         */
        \WP_Mock::userFunction( 'wp_unslash', [ 'return' => function ( $value ) {
            return $value;
        } ] );

        \WP_Mock::userFunction( 'sanitize_text_field', [ 'return' => function ( $value ) {
            return is_scalar( $value ) ? trim( str_replace( [ "\n", "\r" ], ' ', (string) $value ) ) : '';
        } ] );

        \WP_Mock::userFunction( 'sanitize_textarea_field', [ 'return' => function ( $value ) {
            return is_scalar( $value ) ? (string) $value : '';
        } ] );

        // The real CoreOptions::encrypt_decrypt() is used, not a stub: what
        // these tests are about is that a secret does not reach the database
        // as itself, and a fake cipher would prove that about the fake.
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'chiave-di-prova-non-un-segreto' );
        }

        if ( ! defined( 'AUTH_SALT' ) ) {
            define( 'AUTH_SALT', 'sale-di-prova-non-un-segreto' );
        }

        /*
         * WP_Mock defines ABSPATH as the empty string, and it cannot be
         * redefined. install() therefore does
         * `require_once 'wp-admin/includes/upgrade.php'` — a relative path,
         * which PHP resolves against include_path. Pointing that at the fixture
         * is what makes install() reachable from a test at all.
         */
        set_include_path( __DIR__ . '/../fixtures/wp' . PATH_SEPARATOR . get_include_path() );

        \WP_Mock::userFunction( 'dbDelta', [ 'return' => [] ] );

        $GLOBALS['wpdb'] = new AddonFakeWpdb();
    }

    protected function tearDown() : void {
        \WP_Mock::tearDown();

        parent::tearDown();
    }

    private function options() : Options {
        return new Options(
            'wp2static_addon_test_options',
            [
                'plain' => [ 'string', 'default' ],
                'secret' => [ 'password', '' ],
                'flag' => [ 'bool', '0' ],
                'count' => [ 'int', '5' ],
                'body' => [ 'text', '' ],
            ]
        );
    }

    public function testSeedIsIdempotent() : void {
        $options = $this->options();

        $options->seed();
        $options->seed();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringContainsString(
                'INSERT IGNORE',
                $query,
                'Seeding twice must not be able to add a second row per option.'
            );
        }
    }

    public function testSaveIsAnUpsert() : void {
        $this->options()->save( 'plain', 'value' );

        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            $GLOBALS['wpdb']->queries[0],
            'save() must create the row when it is missing, not silently do nothing.'
        );
    }

    public function testEveryQueryIsPrepared() : void {
        $options = $this->options();

        $options->install();
        $options->all();
        $options->get( 'plain' );
        $options->save( 'plain', "O'Brien" );
        $options->drop();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringNotContainsString(
                '%i',
                $query,
                'A placeholder left unbound means the table name was never passed through prepare().'
            );
        }
    }

    public function testAnUnsetOptionFallsBackToItsDeclaredDefault() : void {
        $this->assertSame( 'default', $this->options()->get( 'plain' ) );
        $this->assertSame( 5, $this->options()->int( 'count' ) );
        $this->assertFalse( $this->options()->bool( 'flag' ) );
    }

    /**
     * The value written for `secret`, pulled back out of the recorded SQL.
     *
     * FakeWpdb::prepare() quotes what it substitutes, so the ciphertext is the
     * quoted string after the option's name.
     */
    private function ciphertextIn( string $sql ) : string {
        if ( ! preg_match( "/'secret', '([^']*)'/", $sql, $matches ) ) {
            return '';
        }

        return $matches[1];
    }

    public function testSecretsAreEncryptedOnTheWayIn() : void {
        $this->options()->save( 'secret', 'hunter2' );

        $written = implode( ' ', $GLOBALS['wpdb']->queries );

        $this->assertStringNotContainsString(
            'hunter2',
            $written,
            'A password must never reach the database as its own plain value.'
        );

        // And it is encryption, not discarding: what went in comes back.
        $ciphertext = $this->ciphertextIn( $written );

        $this->assertNotSame( '', $ciphertext );
        $this->assertSame(
            'hunter2',
            \WP2Static\CoreOptions::encrypt_decrypt( 'decrypt', $ciphertext )
        );
    }

    public function testANonSecretIsStoredAsItIs() : void {
        $this->options()->save( 'plain', 'visible' );

        $this->assertStringContainsString( 'visible', implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testABlankPasswordKeepsTheStoredOne() : void {
        $_POST = [
            'plain' => 'x',
            'secret' => '',
            'body' => '',
            'count' => '1',
        ];

        $this->options()->savePosted();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringNotContainsString(
                "'secret'",
                $query,
                'An empty password field must not wipe the saved credentials.'
            );
        }
    }

    public function testAnUncheckedBoxIsSavedAsZero() : void {
        $_POST = [ 'plain' => 'x' ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "'flag', '0'", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnArrayPostedForAScalarOptionIsRejected() : void {
        $_POST = [ 'plain' => [ 'not', 'a', 'string' ] ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "'plain', ''", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnUndeclaredPostedFieldIsIgnored() : void {
        $_POST = [
            'plain' => 'x',
            'somethingElse' => 'y',
        ];

        $this->options()->savePosted();

        $this->assertStringNotContainsString( 'somethingElse', implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testATextareaKeepsItsNewlines() : void {
        $_POST = [ 'body' => "one\ntwo" ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "one\ntwo", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnUndeclaredTypeFallsBackToString() : void {
        $options = new Options( 'x', [ 'weird' => [ 'nonsense', '' ] ] );

        $this->assertSame( 'string', $options->type( 'weird' ) );
        $this->assertFalse( $options->isSecret( 'weird' ) );
    }
}
