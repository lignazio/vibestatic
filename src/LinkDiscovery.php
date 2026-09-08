<?php
/**
 * Find the URLs a crawled page points at.
 *
 * **Why this exists at all:** the crawler only ever visited what detection had
 * produced up front — the posts, pages, archives and assets WordPress knows how
 * to enumerate. Anything reachable only by a link inside a page was never
 * fetched, and so was never published. A hand-written link in the body of a
 * post, a page a plugin renders at a route of its own, a PDF in the uploads
 * folder nothing else references: all missing from the static copy, with
 * nothing to say so.
 *
 * The idea comes from the abandoned `advanced-crawling` add-on, whose crawler
 * cannot run any more — it builds a `WP2Static\Request` the core no longer has,
 * then guards a cURL handle with `is_resource()`, which since PHP 8 is always
 * false. So this takes the idea and not the code, on top of the pool the core
 * already has.
 *
 * DOMDocument rather than a parser library: the plugin has one production
 * dependency, and adding masterminds/html5 for this would make it two.
 *
 * @package WP2Static
 */

namespace WP2Static;

use WP2Static\Vendor\GuzzleHttp\Psr7\Uri;
use WP2Static\Vendor\GuzzleHttp\Psr7\UriResolver;

class LinkDiscovery {

    /**
     * Element/attribute pairs that carry a URL.
     *
     * `a[href]` is the one that matters — it is what makes a page reachable —
     * but a static copy needs what the page loads as well as where it points.
     *
     * @var array<string, string[]>
     */
    const ATTRIBUTES = [
        'a' => [ 'href' ],
        'area' => [ 'href' ],
        'audio' => [ 'src' ],
        'embed' => [ 'src' ],
        'iframe' => [ 'src' ],
        'img' => [ 'src', 'srcset' ],
        'link' => [ 'href' ],
        'object' => [ 'data' ],
        'script' => [ 'src' ],
        'source' => [ 'src', 'srcset' ],
        'track' => [ 'src' ],
        'video' => [ 'src', 'poster' ],
    ];

    /**
     * The root-relative paths a page refers to, on this site.
     *
     * @param string $html      The crawled page.
     * @param string $page_url  Absolute URL of the page, to resolve relative
     *                          references against.
     * @param string $site_host Host (with port) the site answers on.
     * @return string[] Unique root-relative paths, each beginning with `/`.
     */
    public static function find( string $html, string $page_url, string $site_host ) : array {
        if ( '' === trim( $html ) ) {
            return [];
        }

        $document = self::parse( $html );

        if ( null === $document ) {
            return [];
        }

        $base = new Uri( $page_url );
        $found = [];

        foreach ( self::ATTRIBUTES as $tag => $attributes ) {
            foreach ( $document->getElementsByTagName( $tag ) as $element ) {
                foreach ( $attributes as $attribute ) {
                    if ( ! $element->hasAttribute( $attribute ) ) {
                        continue;
                    }

                    $value = $element->getAttribute( $attribute );

                    foreach ( self::candidates( $attribute, $value ) as $candidate ) {
                        $path = self::toRootRelativePath( $candidate, $base, $site_host );

                        if ( null !== $path ) {
                            $found[ $path ] = true;
                        }
                    }
                }
            }
        }

        return array_keys( $found );
    }

    /**
     * Parse without letting libxml's opinion of the markup reach the log.
     *
     * A crawled page is whatever the theme produced, and complaining about it
     * is not this crawler's job: `libxml_use_internal_errors` keeps the
     * warnings out of PHP's error handler, and the parse result is used for
     * whatever it managed to read.
     */
    private static function parse( string $html ) : ?\DOMDocument {
        $previous = libxml_use_internal_errors( true );

        $document = new \DOMDocument();

        /*
         * The meta charset. Without it libxml assumes ISO-8859-1 and an
         * accented character in a URL comes back mangled — the same class of
         * defect as reading a title through wptexturize and getting entities.
         */
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $document : null;
    }

    /**
     * One attribute can hold several URLs.
     *
     * `srcset` is a comma-separated list of "url descriptor" pairs, and taking
     * the whole attribute as a URL would mean crawling nothing from it.
     *
     * @return string[]
     */
    private static function candidates( string $attribute, string $value ) : array {
        if ( 'srcset' !== $attribute ) {
            return [ $value ];
        }

        $candidates = [];

        foreach ( explode( ',', $value ) as $entry ) {
            // strtok answers false on an empty string, which is the only way
            // out of this: given anything else it returns a non-empty token.
            $url = strtok( trim( $entry ), " \t\n" );

            if ( is_string( $url ) ) {
                $candidates[] = $url;
            }
        }

        return $candidates;
    }

    /**
     * A reference as a root-relative path on this site, or null.
     *
     * Null covers everything that is not a page of ours to fetch: other hosts,
     * `mailto:`, `tel:`, `javascript:`, `data:`, and bare fragments — `#main`
     * is the page you are already on, and following it would queue the same URL
     * forever under a new name.
     *
     * @param Uri $base The page the reference was found on.
     */
    private static function toRootRelativePath( string $value, Uri $base, string $site_host ) : ?string {
        $value = trim( $value );

        if ( '' === $value || '#' === $value[0] ) {
            return null;
        }

        try {
            $resolved = UriResolver::resolve( $base, new Uri( $value ) );
        } catch ( \InvalidArgumentException $exception ) {
            // A malformed href is the page's problem, not the crawl's — and
            // this is the only thing Uri raises for one. Catching Throwable
            // here would also swallow whatever else went wrong.
            return null;
        }

        if ( ! in_array( $resolved->getScheme(), [ 'http', 'https' ], true ) ) {
            return null;
        }

        $host = $resolved->getHost();

        if ( '' !== (string) $resolved->getPort() ) {
            $host .= ':' . (string) $resolved->getPort();
        }

        if ( $host !== $site_host ) {
            return null;
        }

        $path = $resolved->getPath();

        if ( '' === $path ) {
            $path = '/';
        }

        /*
         * The query string is dropped and the fragment with it. A static site
         * has no query strings: `/?p=12` and `/?p=13` are the same file on
         * disk, and keeping them would queue the same page under as many names
         * as it has links pointing at it.
         */
        return $path;
    }
}
