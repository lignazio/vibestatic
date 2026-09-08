<?php
/**
 * Point the site's forms somewhere that can still answer them.
 *
 * **The problem it exists for:** a static site cannot run PHP, so a form that
 * posts to WordPress posts to nothing. On the published copy every contact
 * form, every newsletter box, is a button that does not work — and nothing on
 * the page says so. This rewrites them to post to a service that will.
 *
 * The idea comes from the abandoned `static-form-converter` add-on. Its code
 * does not: the whole of it was twenty-five lines, three of them comments
 * describing the work to be done, and the one line that did anything set every
 * form on the site to somebody's personal Basin endpoint, left in as an
 * "example". It also hooked `wp2static_post_process_file`, which the core does
 * not fire, so none of it ever ran.
 *
 * **It does nothing until it is configured.** With no endpoint set, no file is
 * opened and no form is touched: adding this to the core changes the output of
 * no existing installation.
 *
 * @package WP2Static
 */

namespace WP2Static;

class FormConverter {

    /**
     * @var int Forms rewritten during this run.
     */
    private static $converted = 0;

    /**
     * @var array<string, int> Why forms were left alone, and how many.
     */
    private static $left = [];

    public static function registerHooks() : void {
        add_action( 'wp2static_process_html', [ self::class, 'processFile' ] );
        add_action( 'wp2static_post_process_complete', [ self::class, 'reportOnce' ] );
    }

    /**
     * Rewrite the forms in one processed file.
     *
     * **Tags are edited, not the document.** The obvious way to do this is to
     * parse the page with DOMDocument, change the form, and write it back —
     * and the tests said no. `saveHTML()` writes every non-ASCII character as
     * an entity, so `città` came back `citt&agrave;` on every page that had a
     * form, and the parser normalises markup besides. That is a great deal of
     * collateral change to a page for the sake of one attribute. Here the
     * opening tag is found, its attributes are read — a tag is small and
     * regular, unlike a document — and only that tag is replaced. Everything
     * else in the file is left byte for byte.
     */
    public static function processFile( string $filename ) : void {
        $default_action = trim( CoreOptions::getValue( 'formAction' ) );
        $rules = self::rules();

        /*
         * Nothing configured, nothing done — and the check is here, before the
         * file is read. This runs once per file on a site that may have
         * eighteen hundred of them.
         */
        if ( '' === $default_action && ! $rules && ! self::isNetlify() ) {
            return;
        }

        if ( ! is_readable( $filename ) ) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this plugin wrote.
        $html = (string) file_get_contents( $filename );

        if ( false === stripos( $html, '<form' ) ) {
            return;
        }

        $converted = self::rewrite( $html, $default_action, $rules );

        if ( null === $converted ) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local file this plugin wrote.
        file_put_contents( $filename, $converted );
    }

    /**
     * The page with its forms rewritten, or null when nothing changed.
     *
     * Public so it can be exercised on a string: this is where every decision
     * is made, and a test that has to write a file to reach it is a test that
     * has to be read twice.
     *
     * @param array<string, string> $rules id-or-name => endpoint.
     */
    public static function rewrite( string $html, string $default_action, array $rules ) : ?string {
        $changed = 0;
        $out = '';
        $offset = 0;
        $masked = self::maskedRanges( $html );

        while ( preg_match( '/<form\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
            $tag = (string) $match[0][0];
            $at = (int) $match[0][1];

            $attributes = self::attributes( $tag );

            $decision = self::isMasked( $at, $masked )
                ? null
                : self::decide( $attributes, $default_action, $rules );

            // Everything between the last form and this one, untouched.
            $out .= substr( $html, $offset, $at - $offset );

            if ( null === $decision ) {
                $out .= $tag;
                $offset = $at + strlen( $tag );

                continue;
            }

            $out .= self::buildTag( $attributes, $decision );

            /*
             * The form's own contents, with the WordPress-only fields taken
             * out. Forms cannot nest, so the next closing tag is this one's:
             * the extent is not a guess.
             */
            $body_at = $at + strlen( $tag );
            $close = stripos( $html, '</form', $body_at );
            $body_end = false === $close ? strlen( $html ) : $close;

            $out .= self::stripWordPressFields( substr( $html, $body_at, $body_end - $body_at ) );

            $offset = $body_end;
            $changed++;
        }

        if ( 0 === $changed ) {
            return null;
        }

        self::$converted += $changed;

        return $out . substr( $html, $offset );
    }

    /**
     * One line at the end of post-processing, not one per file.
     *
     * A site has a search form on every page: said per file that is eighteen
     * hundred identical lines, and a log nobody reads is a log that hides the
     * one line that mattered.
     */
    public static function reportOnce() : void {
        if ( self::$converted ) {
            WsLog::l( sprintf( 'Form conversion: %d form(s) rewritten.', self::$converted ) );
        }

        foreach ( self::$left as $reason => $count ) {
            WsLog::l( sprintf( 'Form conversion: %d %s left as they were.', $count, $reason ) );
        }

        self::$converted = 0;
        self::$left = [];
    }

    /**
     * What to do with a form: the endpoint to give it, or null to leave it.
     *
     * @param array<string, string> $attributes     The tag's attributes.
     * @param string                $default_action Endpoint for forms with no rule.
     * @param array<string, string> $rules          id-or-name => endpoint.
     * @return string|null The endpoint, '' for Netlify, or null to leave alone.
     */
    private static function decide( array $attributes, string $default_action, array $rules ) : ?string {
        $key = $attributes['id'] ?? '';

        if ( '' === $key ) {
            $key = $attributes['name'] ?? '';
        }

        $named = '' !== $key && isset( $rules[ $key ] );

        if ( $named && '' === $rules[ $key ] ) {
            self::note( 'form(s) the rules say to leave alone' );

            return null;
        }

        /*
         * A form the user has not named is only converted if leaving it would
         * break it. Three would not be improved by being sent to a form
         * service, and changing them silently is worse than leaving them:
         *
         * - one that already posts to another host works as it is;
         * - a search form is a GET to the site itself, which on a static host
         *   answers with something, just not a search;
         * - a comment form posts to wp-comments-post.php, which is not there.
         *
         * The last two stay broken, and that is the honest outcome: a search
         * box quietly removed from every page is a bigger change than a search
         * box that does not search, and it is not this converter's to make.
         * They are counted and reported at the end.
         */
        if ( ! $named ) {
            $reason = self::reasonToLeaveAlone( $attributes );

            if ( null !== $reason ) {
                self::note( $reason );

                return null;
            }
        }

        if ( self::isNetlify() ) {
            return '';
        }

        $action = $named ? $rules[ $key ] : $default_action;

        return '' === $action ? null : $action;
    }

    /**
     * @param array<string, string> $attributes The tag's attributes.
     * @param string                $endpoint   Where it should post; '' for Netlify.
     */
    private static function buildTag( array $attributes, string $endpoint ) : string {
        if ( self::isNetlify() ) {
            /*
             * Netlify Forms is not an endpoint: it is an attribute and a hidden
             * field. Without a distinct `form-name` a site with two forms gets
             * one, and which one is not defined.
             */
            $name = $attributes['id'] ?? ( $attributes['name'] ?? 'form' );

            $attributes['data-netlify'] = 'true';
            $attributes['name'] = $name;
        } else {
            $attributes['action'] = $endpoint;
        }

        // Explicit, because a theme can leave it out and the default is GET,
        // which puts the fields in the URL and loses anything with a newline.
        $attributes['method'] = 'post';

        $tag = '<form';

        foreach ( $attributes as $attribute => $value ) {
            $tag .= ' ' . $attribute . '="' . str_replace( '"', '&quot;', $value ) . '"';
        }

        $tag .= '>';

        if ( ! self::isNetlify() ) {
            return $tag;
        }

        return $tag . '<input type="hidden" name="form-name" value="'
            . str_replace( '"', '&quot;', (string) $attributes['name'] ) . '">';
    }

    /**
     * Why this form should be left as it is, or null to convert it.
     *
     * @param array<string, string> $attributes The tag's attributes.
     */
    private static function reasonToLeaveAlone( array $attributes ) : ?string {
        $action = $attributes['action'] ?? '';

        $host = '' !== $action ? parse_url( $action, PHP_URL_HOST ) : null;

        if ( is_string( $host ) && $host !== parse_url( SiteInfo::getUrl( 'site' ), PHP_URL_HOST ) ) {
            return 'form(s) that already post to another site';
        }

        if ( 'search' === strtolower( $attributes['role'] ?? '' ) ) {
            return 'search form(s), which cannot search a static site';
        }

        if ( false !== strpos( $action, 'wp-comments-post.php' )
            || 'commentform' === ( $attributes['id'] ?? '' )
        ) {
            return 'comment form(s), which have nothing to post to';
        }

        return null;
    }

    /**
     * Take out the fields that only meant something to WordPress.
     *
     * A nonce frozen into a static file expired the day it was written: it
     * protects nothing, and it reaches the form service as a mystery field on
     * every submission. `_wp_http_referer` is the same.
     */
    private static function stripWordPressFields( string $form_body ) : string {
        return (string) preg_replace(
            '/<input\b[^>]*\bname=(["\'])(?:_wpnonce|_wp_http_referer)\1[^>]*>\s*/i',
            '',
            $form_body
        );
    }

    /**
     * The attributes of one opening tag, in the order they were written.
     *
     * A tag is small and regular, which is why reading it this way is
     * reasonable where reading a whole document this way would not be:
     * everything up to the first `>` that is not inside a quoted value.
     *
     * @return array<string, string>
     */
    private static function attributes( string $tag ) : array {
        $attributes = [];

        preg_match_all(
            '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/',
            substr( $tag, 5, -1 ),
            $matches,
            PREG_SET_ORDER
        );

        foreach ( $matches as $match ) {
            // The pattern's first group needs at least one character, so the
            // name cannot come back empty: no guard for a case that cannot
            // happen.
            $name = strtolower( $match[1] );

            $value = '';

            foreach ( [ 2, 3, 4 ] as $group ) {
                if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
                    $value = $match[ $group ];

                    break;
                }
            }

            $attributes[ $name ] = $value;
        }

        return $attributes;
    }

    /**
     * Whether forms should be marked for Netlify Forms rather than pointed at
     * an endpoint.
     */
    private static function isNetlify() : bool {
        return 'netlify' === strtolower( trim( CoreOptions::getValue( 'formProvider' ) ) );
    }

    /**
     * Per-form overrides: `id-or-name = endpoint`, one per line.
     *
     * By id or name and not by CSS selector, which is what a first draft of
     * this wanted: there is no selector engine here, so a selector would mean
     * translating CSS to XPath by hand or taking a second dependency — a lot
     * of work for a rule that in practice names one form.
     *
     * An empty endpoint means "leave this one alone", which is how a form that
     * must keep its own action is spared.
     *
     * @return array<string, string>
     */
    private static function rules() : array {
        $rules = [];

        foreach ( CoreOptions::getLineDelimitedBlobValue( 'formRules' ) as $line ) {
            if ( false === strpos( $line, '=' ) ) {
                continue;
            }

            [ $key, $endpoint ] = explode( '=', $line, 2 );

            $key = trim( $key );

            if ( '' === $key ) {
                continue;
            }

            $rules[ $key ] = trim( $endpoint );
        }

        return $rules;
    }

    /**
     * Where a `<form` is not a form: inside a script, a style, a comment or a
     * textarea.
     *
     * A test caught this rather than a review: a page carrying
     * `<script>var x = "<form>";</script>` had that string rewritten into a
     * real form tag, which is both wrong and a syntax error in the script. It
     * is the one thing reading tags rather than parsing a document does not
     * get for free, so it is paid for here.
     *
     * @return array<int, array{0: int, 1: int}> Start and end offsets.
     */
    private static function maskedRanges( string $html ) : array {
        $ranges = [];

        foreach (
            [
                '/<script\b[^>]*>.*?<\/script\s*>/is',
                '/<style\b[^>]*>.*?<\/style\s*>/is',
                '/<textarea\b[^>]*>.*?<\/textarea\s*>/is',
                '/<!--.*?-->/s',
            ] as $pattern
        ) {
            if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }

            foreach ( $matches[0] as $hit ) {
                $ranges[] = [ (int) $hit[1], (int) $hit[1] + strlen( (string) $hit[0] ) ];
            }
        }

        return $ranges;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $ranges Start and end offsets.
     */
    private static function isMasked( int $offset, array $ranges ) : bool {
        foreach ( $ranges as $range ) {
            if ( $offset >= $range[0] && $offset < $range[1] ) {
                return true;
            }
        }

        return false;
    }

    private static function note( string $reason ) : void {
        self::$left[ $reason ] = ( self::$left[ $reason ] ?? 0 ) + 1;
    }
}
