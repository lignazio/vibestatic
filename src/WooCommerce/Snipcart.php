<?php
/**
 * A WooCommerce shop that still sells after it has been made static.
 *
 * A static site cannot run a cart: WooCommerce's own is PHP and sessions, and
 * the published copy has neither. Snipcart is a cart that lives entirely in the
 * browser and on somebody else's servers, so a shop can be published as files
 * and still take orders. This rewrites the add-to-cart buttons on the way out
 * and puts Snipcart's script on the page.
 *
 * The idea comes from the abandoned `woocommerce-snipcart` add-on. Half a line
 * of it worked — `detectStoreURL`, which adds the shop page to the crawl list
 * — and that half is kept. The conversion it advertised did not exist: it read
 * each file, wrote a line to the PHP error log when it found the string
 * `add_to_cart_button`, and then two comments where the work should have been.
 *
 * **Three conditions, and they are why this is allowed to live in the core.**
 * Without WooCommerce it registers nothing at all — no hooks, no options, no
 * menu entry — so on the great majority of installations it is indistinguishable
 * from not being here. Its files are its own, so it stays extractable if the
 * plugin ever gains a channel for separate add-ons. And with no API key set it
 * does nothing even where WooCommerce is active.
 *
 * @package WP2Static
 */

namespace WP2Static\WooCommerce;

use WP2Static\CoreOptions;
use WP2Static\WsLog;

class Snipcart {

    /**
     * The Snipcart release the injected markup is written against.
     *
     * Pinned rather than "latest": a cart that changes under a published site
     * without anybody deciding to change it is a shop that breaks on somebody
     * else's schedule.
     */
    const VERSION = 'v3.7.1';

    const CDN = 'https://cdn.snipcart.com/themes/';

    /**
     * @var Converter|null Built once per run, when there is work to do.
     */
    private static $converter = null;

    /**
     * @var int Pages that had a button converted.
     */
    private static $pages = 0;

    /**
     * The one place WooCommerce is checked for.
     *
     * Everything else can then assume it is there, instead of asking again in
     * every method and being wrong in one of them.
     */
    public static function registerHooks() : void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        add_filter( 'wp2static_modify_initial_crawl_list', [ self::class, 'addShopURLs' ] );
        add_action( 'wp2static_process_html', [ self::class, 'processFile' ] );
        add_action( 'wp2static_post_process_complete', [ self::class, 'reportOnce' ] );
    }

    /**
     * Add what a shop has that detection does not produce, and leave out what
     * cannot work.
     *
     * The shop page is a page, so it is detected — but only if it has been set,
     * and a shop's category and tag archives are neither posts nor pages and
     * are detected by nothing. The cart, the checkout and the account page are
     * the opposite case: they are pages, so they *are* detected, and every one
     * of them is a WooCommerce form over a PHP session. Published, they are
     * three dead ends that look like the real thing.
     *
     * @param mixed $urls The list detection has built so far.
     * @return string[] The same, plus the shop's, minus what cannot work.
     */
    public static function addShopURLs( $urls ) : array {
        /*
         * Paths, not URLs, and that is not a detail. This filter runs *after*
         * `FilesHelper::cleanDetectedURLs()`, so what arrives is a list of
         * root-relative paths — and adding full permalinks to it would put two
         * shapes in one queue, while excluding by full permalink would match
         * nothing at all. Measured before the fix: the cart, the checkout and
         * the account page were still in the list.
         */
        $list = [];

        foreach ( is_array( $urls ) ? $urls : [] as $url ) {
            if ( is_string( $url ) ) {
                $list[] = $url;
            }
        }

        foreach ( self::shopPaths() as $path ) {
            $list[] = $path;
        }

        $excluded = self::unpublishablePaths();

        return array_values(
            array_unique(
                array_filter(
                    $list,
                    function ( string $path ) use ( $excluded ) : bool {
                        return ! in_array( untrailingslashit( $path ), $excluded, true );
                    }
                )
            )
        );
    }

    /**
     * What a shop has that detection does not produce.
     *
     * The shop page is a page, so it is detected — but a shop's category and
     * tag archives are neither posts nor pages, and nothing looks for them.
     *
     * @return string[] Root-relative paths.
     */
    private static function shopPaths() : array {
        $paths = [];

        $shop = get_permalink( (int) wc_get_page_id( 'shop' ) );

        if ( is_string( $shop ) ) {
            $paths[] = self::asPath( $shop );
        }

        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );

            // is_array() and nothing more: get_terms() answers a WP_Error for a
            // taxonomy that is not registered, which is the case worth guarding.
            foreach ( is_array( $terms ) ? $terms : [] as $term ) {
                $link = get_term_link( $term );

                if ( is_string( $link ) ) {
                    $paths[] = self::asPath( $link );
                }
            }
        }

        return array_values( array_filter( $paths ) );
    }

    /**
     * The three pages that cannot survive being made static.
     *
     * They are pages, so detection finds them, and every one is a WooCommerce
     * form over a PHP session. Published, they are three dead ends that look
     * like the real thing — a checkout that silently does nothing is worse than
     * no checkout at all.
     *
     * @return string[] Root-relative paths, without a trailing slash.
     */
    private static function unpublishablePaths() : array {
        $paths = [];

        foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
            $permalink = get_permalink( (int) wc_get_page_id( $page ) );

            if ( ! is_string( $permalink ) ) {
                continue;
            }

            $path = self::asPath( $permalink );

            if ( '' !== $path && '/' !== $path ) {
                $paths[] = untrailingslashit( $path );
            }
        }

        return $paths;
    }

    /**
     * A permalink as the root-relative path the crawl queue holds.
     */
    private static function asPath( string $permalink ) : string {
        $path = wp_parse_url( $permalink, PHP_URL_PATH );

        return is_string( $path ) ? $path : '';
    }

    /**
     * Convert one processed file, and give it the cart.
     */
    public static function processFile( string $filename ) : void {
        $key = trim( CoreOptions::getValue( 'snipcartApiKey' ) );

        // Nothing configured, nothing opened. This runs once per file.
        if ( '' === $key ) {
            return;
        }

        if ( ! is_readable( $filename ) ) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this plugin wrote.
        $html = (string) file_get_contents( $filename );

        if ( null === self::$converter ) {
            self::$converter = new Converter(
                new ProductIndex(),
                CoreOptions::getValue( 'deploymentURL' )
            );
        }

        $converted = self::$converter->convert( $html );

        if ( null === $converted ) {
            return;
        }

        /*
         * The cart's own markup goes only on the pages that got a button. A
         * shop's archive pages and product pages are a fraction of a site, and
         * putting a third-party script on the other nine tenths would be
         * slowing down pages that have nothing to sell.
         */
        $converted = self::injectCart( $converted, $key );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local file this plugin wrote.
        file_put_contents( $filename, $converted );

        self::$pages++;
    }

    public static function reportOnce() : void {
        if ( null === self::$converter ) {
            return;
        }

        WsLog::l(
            sprintf(
                'Snipcart: %d add-to-cart button(s) converted across %d page(s).',
                self::$converter->converted(),
                self::$pages
            )
        );

        self::$converter = null;
        self::$pages = 0;
    }

    /**
     * Snipcart's stylesheet, script and the element holding the public key.
     *
     * The key is public by design — it is the shop's identifier in the
     * browser, and Snipcart validates every price against the page it fetches
     * rather than trusting what the button says. It is an option all the same,
     * because it differs between a test shop and a live one.
     */
    private static function injectCart( string $html, string $key ) : string {
        if ( false !== strpos( $html, 'id="snipcart"' ) ) {
            return $html;
        }

        $markup = sprintf(
            '<link rel="stylesheet" href="%1$s%2$s/default/snipcart.css">'
            . '<script async src="%1$s%2$s/default/snipcart.js"></script>'
            . '<div hidden id="snipcart" data-api-key="%3$s"></div>',
            self::CDN,
            self::VERSION,
            htmlspecialchars( $key, ENT_QUOTES )
        );

        $at = strripos( $html, '</body>' );

        if ( false === $at ) {
            return $html . $markup;
        }

        return substr( $html, 0, $at ) . $markup . substr( $html, $at );
    }
}
