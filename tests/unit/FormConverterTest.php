<?php

namespace WP2Static;

use Mockery;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * What the converter does to a page's forms, and — as much — what it does not.
 *
 * The add-on this replaces set every form on the site to a single hard-coded
 * endpoint belonging to somebody else, left in as an "example". The tests that
 * matter here are therefore the ones about restraint: nothing configured means
 * nothing touched, and a form that would not be improved by being redirected is
 * left where it is and counted.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormConverterTest extends TestCase {

    const ENDPOINT = 'https://forms.example.net/f/abc123';

    /**
     * @var vfsStreamDirectory
     */
    private $fs;

    public function setUp() : void {
        WP_Mock::setUp();

        $this->fs = vfsStream::setup( 'processed' );

        Mockery::mock( 'alias:WP2Static\WsLog' )->shouldReceive( 'l' )->andReturnNull();
        Mockery::mock( 'alias:WP2Static\SiteInfo' )
            ->shouldReceive( 'getUrl' )
            ->andReturn( 'https://example.com/' );
    }

    public function tearDown() : void {
        CoreOptions::setRepository( null );
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param array<string, string> $options name => value
     * @param string[]              $rules   "id = endpoint" lines
     */
    private function configure( array $options, array $rules = [] ) : void {
        $repository = Mockery::mock( CoreOptionsRepository::class );

        $repository->shouldReceive( 'getValue' )->andReturnUsing(
            function ( $name ) use ( $options ) {
                return $options[ $name ] ?? '';
            }
        );

        $repository->shouldReceive( 'getBlobValue' )->andReturn( implode( "\n", $rules ) );

        CoreOptions::setRepository( $repository );
    }

    private function convert( string $html ) : string {
        $file = vfsStream::url( 'processed/page.html' );

        file_put_contents( $file, $html );

        FormConverter::processFile( $file );

        return (string) file_get_contents( $file );
    }

    /**
     * Nothing configured, nothing touched.
     *
     * The first thing to be sure of, because this runs over every file of every
     * site that installs the plugin.
     */
    public function testItLeavesEverythingAloneUntilItIsConfigured() : void {
        $this->configure( [] );

        $html = '<form action="/wp-admin/admin-post.php" method="post"><input name="email"></form>';

        $this->assertSame( $html, $this->convert( $html ) );
    }

    public function testItPointsAFormAtTheConfiguredEndpoint() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $out = $this->convert( '<form action="/wp-admin/admin-post.php"><input name="email"></form>' );

        $this->assertStringContainsString( 'action="' . self::ENDPOINT . '"', $out );
        $this->assertStringContainsString( 'method="post"', $out );
    }

    /**
     * The field names survive: they are what the receiving service labels the
     * submission with.
     */
    public function testItKeepsTheFields() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $out = $this->convert(
            '<form action="/x"><input name="your-name"><textarea name="message"></textarea></form>'
        );

        $this->assertStringContainsString( 'name="your-name"', $out );
        $this->assertStringContainsString( 'name="message"', $out );
    }

    /**
     * A nonce frozen into a static file expired the day it was written. It
     * protects nothing and reaches the form service as a mystery field on every
     * submission.
     */
    public function testItTakesOutTheWordPressOnlyFields() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $out = $this->convert(
            '<form action="/x"><input type="hidden" name="_wpnonce" value="abc">'
            . '<input type="hidden" name="_wp_http_referer" value="/page/">'
            . '<input name="email"></form>'
        );

        $this->assertStringNotContainsString( '_wpnonce', $out );
        $this->assertStringNotContainsString( '_wp_http_referer', $out );
        $this->assertStringContainsString( 'name="email"', $out );
    }

    /**
     * A search form cannot be made to work by pointing it somewhere else, and
     * removing it from every page would be a larger change than this should
     * make on its own.
     */
    public function testItLeavesASearchFormAlone() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $html = '<form role="search" method="get" action="/"><input name="s"></form>';

        $this->assertSame( $html, $this->convert( $html ) );
    }

    public function testItLeavesACommentFormAlone() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $html = '<form id="commentform" action="/wp-comments-post.php" method="post">'
            . '<textarea name="comment"></textarea></form>';

        $this->assertSame( $html, $this->convert( $html ) );
    }

    /**
     * A form already posting somewhere else works as it is.
     */
    public function testItLeavesAFormThatAlreadyPostsOffSiteAlone() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $html = '<form action="https://newsletter.example.org/subscribe" method="post">'
            . '<input name="email"></form>';

        $this->assertSame( $html, $this->convert( $html ) );
    }

    /**
     * A rule names one form and overrides the default for it.
     */
    public function testARuleOverridesTheDefaultForOneForm() : void {
        $this->configure(
            [ 'formAction' => self::ENDPOINT ],
            [ 'newsletter = https://lists.example.net/subscribe' ]
        );

        $out = $this->convert(
            '<form id="newsletter" action="/x"><input name="email"></form>'
            . '<form id="contact" action="/y"><input name="email"></form>'
        );

        $this->assertStringContainsString( 'action="https://lists.example.net/subscribe"', $out );
        $this->assertStringContainsString( 'action="' . self::ENDPOINT . '"', $out );
    }

    /**
     * A rule with no endpoint is how a form keeps its own action.
     */
    public function testARuleWithNoEndpointLeavesThatFormAlone() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ], [ 'special =' ] );

        $out = $this->convert( '<form id="special" action="/keep-me"><input name="a"></form>' );

        $this->assertStringContainsString( 'action="/keep-me"', $out );
        $this->assertStringNotContainsString( self::ENDPOINT, $out );
    }

    /**
     * A rule reaches a form that has a name and no id.
     */
    public function testARuleMatchesOnNameWhenThereIsNoId() : void {
        $this->configure( [ 'formAction' => '' ], [ 'contatti = https://a.example.net/f' ] );

        $out = $this->convert( '<form name="contatti" action="/x"><input name="a"></form>' );

        $this->assertStringContainsString( 'action="https://a.example.net/f"', $out );
    }

    /**
     * A rule can convert a search form, if that is what the user asked for.
     * Naming it is the difference between a default and an instruction.
     */
    public function testANamedRuleOverridesTheLeaveAloneRules() : void {
        $this->configure( [], [ 'searchform = https://search.example.net/q' ] );

        $out = $this->convert(
            '<form id="searchform" role="search" method="get" action="/"><input name="s"></form>'
        );

        $this->assertStringContainsString( 'action="https://search.example.net/q"', $out );
    }

    /**
     * Netlify Forms is not an endpoint: it is an attribute and a hidden field.
     * Without the field a site with two forms gets one, and which one is not
     * defined.
     */
    public function testNetlifyIsMarkedUpRatherThanPointedSomewhere() : void {
        $this->configure( [ 'formProvider' => 'netlify' ] );

        $out = $this->convert( '<form id="contact" action="/x"><input name="email"></form>' );

        $this->assertStringContainsString( 'data-netlify="true"', $out );
        $this->assertStringContainsString( 'name="form-name"', $out );
        $this->assertStringContainsString( 'value="contact"', $out );
    }

    /**
     * A page with no form is not rewritten at all — not even reformatted by the
     * parser, which would put a diff into every file of the site.
     */
    public function testAPageWithoutAFormIsLeftByteForByte() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $html = "<!DOCTYPE html>\n<html><body><p>niente moduli</p></body></html>\n";

        $this->assertSame( $html, $this->convert( $html ) );
    }

    /**
     * Everything that is not the form tag comes out byte for byte.
     *
     * This is the whole reason the converter edits tags instead of parsing the
     * page. The first version used DOMDocument, and the accented-text test
     * below caught what that costs: `saveHTML()` writes every non-ASCII
     * character as an entity and normalises the markup besides, so pointing one
     * form somewhere else rewrote the entire page. On a site of eighteen
     * hundred files that is eighteen hundred files changed for no reason
     * anyone asked for.
     */
    public function testNothingOutsideTheFormTagIsTouched() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $before = "<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n"
            . "<meta charset=\"UTF-8\">\n<title>Perché — città</title>\n</head>\n<body>\n"
            . "<!-- un commento -->\n<p>Testo con &amp; entità e <em>corsivo</em>.</p>\n"
            . "<script>var x = \"<form>\";</script>\n";

        $after = "\n<p>coda della pagina</p>\n</body>\n</html>\n";

        $out = $this->convert(
            $before . '<form id="contact" action="/x" class="wpcf7-form"><input name="email"></form>' . $after
        );

        $this->assertStringStartsWith( $before, $out );
        $this->assertStringEndsWith( $after, $out );
        $this->assertStringContainsString( 'action="' . self::ENDPOINT . '"', $out );

        // The attributes it did not come to change are still there, and in the
        // order they were written.
        $this->assertStringContainsString( 'class="wpcf7-form"', $out );
        $this->assertStringContainsString( 'id="contact"', $out );
    }

    /**
     * An accented character survives the round trip through the parser.
     */
    public function testItDoesNotMangleAccentedText() : void {
        $this->configure( [ 'formAction' => self::ENDPOINT ] );

        $out = $this->convert(
            '<!DOCTYPE html><html><body><p>città però</p>'
            . '<form action="/x"><input name="a"></form></body></html>'
        );

        $this->assertStringContainsString( 'città però', $out );
        $this->assertStringNotContainsString( '<?xml', $out );
    }
}
