<?php
/*
    Crawler

    Crawls URLs in WordPressSite, saving them to StaticSite

*/

namespace WP2Static;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Psr7\Request;
use WP2Static\Vendor\Psr\Http\Message\ResponseInterface;
use WP2Static\Vendor\GuzzleHttp\Exception\TooManyRedirectsException;
use WP2Static\Vendor\GuzzleHttp\Pool;

define( 'WP2STATIC_REDIRECT_CODES', [ 301, 302, 303, 307, 308 ] );

class Crawler {

    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $site_path;

    /**
     * @var integer
     */
    private $crawled = 0;

    /**
     * @var integer
     */
    private $cache_hits = 0;

    /**
     * @var array<string, true> Paths found in this pass's pages, as a set.
     */
    private $discovered = [];

    /**
     * How many times the crawl will go round picking up newly found URLs.
     *
     * Not a performance guard. A site that links to endlessly new addresses —
     * a calendar with a "next month" link, a paginated archive with no last
     * page — supplies new work for ever, and without a cap the crawl never
     * ends.
     */
    const MAX_PASSES = 10;

    /**
     * Crawler constructor
     *
     * The two parameters are the seam that makes this class testable. Left out,
     * the behaviour is what it always was — the client builds itself from the
     * options — but a test can hand it a client with a fake handler and run the
     * whole round: what gets written, what gets cached, what happens on a 404
     * and on a redirect. Without them the only way to exercise the crawler was
     * to have a real site on the other end.
     *
     * @param Client|null $client    HTTP client; null to build one.
     * @param string|null $site_path The site's root; null to ask SiteInfo.
     */
    public function __construct( ?Client $client = null, ?string $site_path = null ) {
        $this->site_path = $site_path ?? rtrim( SiteInfo::getURL( 'site' ), '/' );

        if ( $client ) {
            $this->client = $client;

            return;
        }

        $port_override = apply_filters(
            'wp2static_curl_port',
            null
        );

        $base_uri = $this->site_path;

        if ( $port_override ) {
            $base_uri = "{$base_uri}:{$port_override}";
        }

        /*
         * apply_filters() returns whatever the filter decides, and an add-on
         * returning an array or an integer would hand Guzzle an invalid header.
         * The default value is the safety net.
         */
        $user_agent = apply_filters( 'wp2static_curl_user_agent', 'VibeStatic' );

        if ( ! is_string( $user_agent ) ) {
            $user_agent = 'VibeStatic';
        }

        $opts = [
            'base_uri' => $base_uri,
            'verify' => false,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 2,
                // required to get effective_url
                'track_redirects' => true,
            ],
            'connect_timeout'  => 0,
            'timeout' => 600,
            'headers' => [
                'User-Agent' => $user_agent,
            ],
        ];

        $auth_user = CoreOptions::getValue( 'basicAuthUser' );

        if ( $auth_user ) {
            $auth_password = CoreOptions::getValue( 'basicAuthPassword' );

            if ( $auth_password ) {
                WsLog::l( 'Using basic auth credentials to crawl' );
                $opts['auth'] = [ $auth_user, $auth_password ];
            }
        }

        $this->client = new Client( $opts );
    }

    public static function wp2staticCrawl( string $static_site_path, string $crawler_slug ) : void {
        if ( 'wp2static' === $crawler_slug ) {
            $crawler = new Crawler();
            $crawler->crawlSite( $static_site_path );
        }
    }

    /**
     * Crawls URLs in WordPressSite, saving them to StaticSite
     */
    public function crawlSite( string $static_site_path ) : void {
        WsLog::l( 'Starting to crawl detected URLs.' );

        // parse_url() answers false on a malformed URL and null on a URL with
        // no host: both mean there is nothing here to crawl, and saying so is
        // better than carrying the ambiguity into every request.
        $site_host = (string) parse_url( $this->site_path, PHP_URL_HOST );
        $site_port = parse_url( $this->site_path, PHP_URL_PORT );
        $site_host = $site_port ? $site_host . ':' . (string) $site_port : $site_host;

        if ( '' === $site_host ) {
            WsLog::l( 'Cannot crawl: the site URL has no host — ' . $this->site_path );

            return;
        }
        $site_urls = [ "http://$site_host", "https://$site_host" ];

        $use_crawl_cache = (bool) CoreOptions::getValue( 'useCrawlCaching' );

        WsLog::l( ( $use_crawl_cache ? 'Using' : 'Not using' ) . ' CrawlCache.' );

        // TODO: use some Iterable or other performance optimisation here
        // to help reduce resources for large URL sites

        /**
         * When you call method that executes database query in for loop
         * you are calling method and querying database for every loop iteration.
         * To avoid that you need to assing the result to a variable.
         */

        /*
         * Link following, and the reason it is a loop.
         *
         * The pool's request generator is built from a snapshot of the queue,
         * and a generator cannot be extended while it runs — so a URL found
         * inside a page cannot be fetched in the same pass. Each pass crawls
         * what the previous one turned up, until nothing new appears.
         *
         * MAX_PASSES is not caution about slow sites: a page that links to a
         * page that links back, through URLs that differ each time, is an
         * endless supply of "new" work. The cap makes that end, and says so.
         */
        $follow_links = (bool) CoreOptions::getValue( 'addURLsWhileCrawling' );

        WsLog::l( ( $follow_links ? 'Following' : 'Not following' ) . ' links found while crawling.' );

        $already_crawled = [];
        $pass = 0;

        do {
            $pass++;

            $paths = array_values(
                array_diff( CrawlQueue::getCrawlablePaths(), $already_crawled )
            );

            if ( ! $paths ) {
                break;
            }

            if ( $pass > 1 ) {
                WsLog::l( sprintf( 'Crawling %d URL(s) found by following links.', count( $paths ) ) );
            }

            $this->discovered = [];

            $this->crawlPass( $paths, $use_crawl_cache, $site_urls, $site_host );

            $already_crawled = array_merge( $already_crawled, $paths );

            $added = $follow_links ? $this->queueDiscovered( $already_crawled ) : 0;

            if ( $added && $pass >= self::MAX_PASSES ) {
                WsLog::l(
                    sprintf(
                        'Stopping after %d passes with %d URL(s) still being found:' .
                        ' the site appears to link to endlessly new addresses.',
                        self::MAX_PASSES,
                        $added
                    )
                );

                break;
            }
        } while ( $added > 0 );


        WsLog::l(
            "Crawling complete. $this->crawled crawled, $this->cache_hits skipped (cached)."
        );

        $this->pruneStaticSite();

        $args = [
            'staticSitePath' => $static_site_path,
            'crawled' => $this->crawled,
            'cache_hits' => $this->cache_hits,
        ];

        do_action( 'wp2static_crawling_complete', $args );
    }

    /**
     * Remember the internal paths a crawled page points at.
     *
     * Only HTML: a stylesheet or a JSON feed has no links this can read, and
     * running the parser over a megabyte of image would cost for nothing. The
     * content type comes from the response rather than the file extension,
     * because a page served at `/about/` has neither.
     *
     * @param ResponseInterface $response  The crawled response.
     * @param string            $contents  Its body.
     * @param string            $page_url  Absolute URL of the page.
     * @param string            $site_host Host, with port, the site answers on.
     */
    private function collectLinks(
        ResponseInterface $response,
        string $contents,
        string $page_url,
        string $site_host
    ) : void {
        if ( '' === $contents ) {
            return;
        }

        $content_type = strtolower( $response->getHeaderLine( 'Content-Type' ) );

        if ( false === strpos( $content_type, 'html' ) ) {
            return;
        }

        foreach ( LinkDiscovery::find( $contents, $page_url, $site_host ) as $path ) {
            $this->discovered[ $path ] = true;
        }
    }

    /**
     * Put newly found paths into the queue.
     *
     * The ignore lists apply, and they are the core's own: a link to
     * `/wp-content/plugins/…` or to a `.zip` is followed no more eagerly than
     * detection would have followed it.
     *
     * @param string[] $already_crawled Paths this crawl has already fetched.
     * @return int How many were genuinely new.
     */
    private function queueDiscovered( array $already_crawled ) : int {
        if ( ! $this->discovered ) {
            return 0;
        }

        $known = array_flip( CrawlQueue::getCrawlablePaths() ) + array_flip( $already_crawled );

        /*
         * The two lists are read once, not once per path. `filePathLooksCrawlable()`
         * would do the same work — an option read and two filters — for every
         * link on every page, and the first pass of a real site brings back
         * thousands.
         */
        $filenames_to_ignore = self::strings(
            apply_filters(
                'wp2static_filenames_to_ignore',
                CoreOptions::getLineDelimitedBlobValue( 'filenamesToIgnore' )
            )
        );

        $extensions_to_ignore = self::strings(
            apply_filters(
                'wp2static_file_extensions_to_ignore',
                CoreOptions::getLineDelimitedBlobValue( 'fileExtensionsToIgnore' )
            )
        );

        $new = [];

        foreach ( array_keys( $this->discovered ) as $path ) {
            if ( isset( $known[ $path ] ) ) {
                continue;
            }

            if ( ! FilesHelper::pathLooksCrawlable( $path, $filenames_to_ignore, $extensions_to_ignore ) ) {
                continue;
            }

            $new[] = $path;
        }

        if ( ! $new ) {
            return 0;
        }

        CrawlQueue::addUrls( $new );

        return count( $new );
    }

    /**
     * Whatever a filter returned, as a list of strings.
     *
     * The two ignore lists go through `apply_filters`, so anything can come
     * back: a third-party add-on returning a string, or an array with a stray
     * object in it, should cost its own entry and not the whole crawl.
     *
     * @param mixed $value What the filter returned.
     * @return string[]
     */
    private static function strings( $value ) : array {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $strings = [];

        foreach ( $value as $entry ) {
            if ( is_string( $entry ) ) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /**
     * Run one pass of the pool over a set of paths.
     *
     * A pass, not the crawl: with link following on, a page can put a URL in
     * the queue that this pass's request generator was already built from, and
     * a generator cannot be added to once it is running. crawlSite() calls this
     * again for whatever turned up.
     *
     * @param string[] $paths           Root-relative paths to fetch.
     * @param bool     $use_crawl_cache Whether to skip writing unchanged pages.
     * @param string[] $site_urls       The site's http and https roots.
     * @param string   $site_host       Host, with port, the site answers on.
     */
    private function crawlPass(
        array $paths,
        bool $use_crawl_cache,
        array $site_urls,
        string $site_host
    ) : void {
        $urls = [];

        foreach ( $paths as $root_relative_path ) {
            $absolute_uri = new URL( $this->site_path . $root_relative_path );
            $urls[] = [
                'url' => (string) $absolute_uri->get(),
                'path' => $root_relative_path,
            ];
        }

        /*
         * $urls comes in through the closure rather than as a parameter: passed
         * as an untyped parameter it was `mixed`, and so was every element,
         * which made the URL that ends up in the Request uncheckable.
         */
        $requests = function () use ( $urls ) : \Generator {
            foreach ( $urls as $url ) {
                yield new Request( 'GET', $url['url'] );
            }
        };

        $concurrency = intval( CoreOptions::getValue( 'crawlConcurrency' ) );

        $pool = new Pool(
            $this->client,
            $requests(),
            [
                'concurrency' => $concurrency,
                'fulfilled' => function ( ResponseInterface $response, $index ) use (
                    $urls, $use_crawl_cache, $site_urls, $site_host
                ) {
                    $root_relative_path = $urls[ $index ]['path'];
                    $crawled_contents = (string) $response->getBody();
                    $status_code = $response->getStatusCode();

                    /*
                     * Links are read here, before anything downstream can
                     * decide this page is unchanged — and that placement is the
                     * whole safety of the feature.
                     *
                     * A URL found only by following a link is, by construction,
                     * one detection does not produce: `URLDetector::
                     * pruneCrawlQueue()` drops it from the queue at the start of
                     * every run, and `pruneStaticSite()` then deletes any file
                     * with no URL in the queue. It survives only by being found
                     * again on this run. The crawler re-downloads every URL
                     * regardless — the cache saves the writing, not the request
                     * — so the body is in hand even for a page that has not
                     * changed, and reading it here means an unchanged page
                     * still vouches for what it links to. Read it after the
                     * cache check and a site that changed nothing would
                     * unpublish every page reachable only by a link.
                     */
                    $this->collectLinks( $response, $crawled_contents, $urls[ $index ]['url'], $site_host );

                    $is_cacheable = true;
                    if ( $status_code === 404 ) {
                        WsLog::l( '404 for URL ' . $root_relative_path );
                        CrawlCache::rmUrl( $root_relative_path );
                        // Delete crawl queue to prevent crawling not found urls forever.
                        CrawlQueue::rmUrl( $root_relative_path );
                        // Delete previously generated files under the directories,
                        // both the crawled and the processed.
                        array_map(
                            function( $dir ) use ( $root_relative_path ) {
                                $transformed_path = self::transformPath( $root_relative_path );
                                $suffix = ltrim( $transformed_path, '/' );
                                $full_path = trailingslashit( $dir ) . $suffix;
                                if ( file_exists( $full_path ) && ! is_dir( $full_path ) ) {
                                    unlink( $full_path );
                                }
                            },
                            [ StaticSite::getPath(), ProcessedSite::getPath() ]
                        );
                        $crawled_contents = null;
                        $is_cacheable = false;
                    } elseif ( in_array( $status_code, WP2STATIC_REDIRECT_CODES ) ) {
                        $crawled_contents = null;
                    }

                    $redirect_to = null;

                    if ( in_array( $status_code, WP2STATIC_REDIRECT_CODES ) ) {
                        $effective_url = $urls[ $index ]['url'];

                        // returns as string
                        $redirect_history =
                            $response->getHeaderLine( 'X-Guzzle-Redirect-History' );

                        if ( $redirect_history ) {
                            $redirects = explode( ', ', $redirect_history );
                            $effective_url = end( $redirects );
                        }

                        $redirect_to =
                            (string) str_replace( $site_urls, '', $effective_url );
                        $page_hash = md5( $status_code . $redirect_to );
                    } elseif ( ! is_null( $crawled_contents ) ) {
                        $page_hash = md5( $crawled_contents );
                    } else {
                        $page_hash = md5( (string) $status_code );
                    }

                    $write_contents = true;

                    if ( $use_crawl_cache ) {
                        // if not already cached
                        if ( CrawlCache::getUrl( $root_relative_path, $page_hash ) ) {
                            $this->cache_hits++;
                            $write_contents = false;
                        }
                    }

                    $this->crawled++;

                    if ( $crawled_contents && $write_contents ) {
                        $static_path = static::transformPath( $root_relative_path );
                        StaticSite::add( $static_path, $crawled_contents );
                    }

                    if ( $is_cacheable ) {
                        CrawlCache::addUrl(
                            $root_relative_path,
                            $page_hash,
                            $status_code,
                            $redirect_to
                        );
                    }

                    // incrementally log crawl progress
                    if ( $this->crawled % 300 === 0 ) {
                        $notice = "Crawling progress: $this->crawled crawled," .
                                  " $this->cache_hits skipped (cached).";
                        WsLog::l( $notice );
                    }
                },
                /*
                 * The type used to be RequestException, which is wrong: a
                 * refused connection produces a ConnectException, which extends
                 * TransferException and NOT RequestException. So the very case
                 * this callback exists for — a server that does not answer —
                 * ended in a TypeError inside a promise, where nobody sees it.
                 */
                'rejected' => function ( $reason, $index ) use ( $urls ) {
                    $root_relative_path = $urls[ $index ]['path'];
                    WsLog::l( 'Failed ' . $root_relative_path );
                },
            ]
        );

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->wait();
    }

    /**
     * Remove from the crawled site whatever no longer has a URL in the queue.
     *
     * **It sits here for one reason:** this line is only reached if the pool
     * finished. An interrupted crawl — timeout, fatal, killed process — never
     * gets here, and so deletes nothing. It is the difference between "these
     * are all the URLs there are" and "these are the ones I got round to
     * seeing", and on that difference sits a published site.
     *
     * **The comparison is against the queue, not against what this crawl just
     * downloaded.** A URL that hit a network error stays in the queue, so its
     * file stays where it is: an unreachable site does not unpublish itself.
     * The same holds for cache hits, which do not rewrite a file either.
     *
     * **An empty queue deletes nothing.** Empty means detection never ran or
     * was cleared by hand, not that the site no longer exists;
     * `removePathsNotIn()` stops on an empty list by itself, and this comment
     * is the reason it stops.
     */
    private function pruneStaticSite() : void {
        if ( ! FilesHelper::pruningEnabled() ) {
            return;
        }

        $expected = array_map(
            [ self::class, 'transformPath' ],
            array_values( CrawlQueue::getCrawlablePaths() )
        );

        $removed = StaticSite::prune( $expected );

        if ( $removed ) {
            // Same register as the other crawl lines: WsLog messages do not
            // go through gettext, in any file.
            WsLog::l(
                sprintf(
                    'Pruned crawled site: %d file(s) no longer in the Crawl Queue.',
                    count( $removed )
                )
            );
        }
    }

    /**
     * Transform a root-relative path to a static site path.
     *
     * This lets us encapsulate the logic for path transformation in a single
     * place and use it in multiple places.
     *
     * TODO Should this actually be in `StaticSite`?
     */
    public static function transformPath( string $root_relative_path ) : string {
        // do some magic here - naive: if URL ends in /, save to /index.html
        // TODO: will need love for example, XML files
        // check content type, serve .xml/rss, etc instead
        if ( mb_substr( $root_relative_path, -1 ) === '/' ) {
            return $root_relative_path . 'index.html';
        }
        return $root_relative_path;
    }

    /**
     * @deprecated
     *
     * Crawls a string of full URL within WordPressSite
     *
     * @return ResponseInterface|null response object
     */
    public function crawlURL( string $url ) : ?ResponseInterface {
        WsLog::w( 'VibeStatic Crawler::crawlURL is deprecated.' );

        $headers = [];
        $response = null;

        $auth_user = CoreOptions::getValue( 'basicAuthUser' );

        if ( $auth_user ) {
            $auth_password = CoreOptions::getValue( 'basicAuthPassword' );

            if ( $auth_password ) {
                $headers['auth'] = [ $auth_user, $auth_password ];
            }
        }

        $request = new Request( 'GET', $url, $headers );

        try {
            $response = $this->client->send( $request );
        } catch ( TooManyRedirectsException $e ) {
            WsLog::l( "Too many redirects from $url" );
        }

        return $response;
    }
}
