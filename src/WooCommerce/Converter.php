<?php
/**
 * Turn WooCommerce's add-to-cart buttons into Snipcart ones.
 *
 * **Tags are edited, not the document**, for the reason the form converter
 * found out the hard way: parsing a page with DOMDocument and writing it back
 * rewrites the whole thing — every non-ASCII character becomes an entity and
 * the markup is normalised besides. A product page is a whole WordPress page,
 * and the only part of it that should change is one button.
 *
 * WooCommerce renders that button in two shapes, and both are handled because
 * a shop shows both: the single product page has a `<form class="cart">` with a
 * submit button carrying `name="add-to-cart" value="<id>"`, and the block-based
 * listings have a plain button carrying `data-product_id="<id>"`. Either way
 * the product's ID is right there in the markup, which is what makes looking
 * the product up server-side possible.
 *
 * @package WP2Static
 */

namespace WP2Static\WooCommerce;

class Converter {

    /**
     * @var ProductIndex
     */
    private $products;

    /**
     * @var string The site as it will be published, for data-item-url.
     */
    private $deployment_url;

    /**
     * @var int
     */
    private $converted = 0;

    public function __construct( ProductIndex $products, string $deployment_url ) {
        $this->products = $products;
        $this->deployment_url = rtrim( $deployment_url, '/' );
    }

    public function converted() : int {
        return $this->converted;
    }

    /**
     * The page with its add-to-cart buttons converted, or null if none were.
     */
    public function convert( string $html ) : ?string {
        if ( false === stripos( $html, 'add_to_cart' ) ) {
            return null;
        }

        $before = $this->converted;

        $html = $this->convertButtons( $html );
        $html = $this->convertForms( $html );

        return $this->converted > $before ? $html : null;
    }

    /**
     * The listing shape: a button carrying `data-product_id`.
     */
    private function convertButtons( string $html ) : string {
        return (string) preg_replace_callback(
            '/<button\b[^>]*\bdata-product_id=(["\'])(\d+)\1[^>]*>/i',
            function ( array $match ) : string {
                return $this->button( $match[0], (int) $match[2] );
            },
            $html
        );
    }

    /**
     * The single-product shape: a submit button inside `<form class="cart">`,
     * carrying the id as its value.
     *
     * The form goes with it. Left alone it still posts to WordPress, which on a
     * static site is a button that reloads the page and does nothing — the same
     * defect the form converter exists for, and worse here because it looks
     * like it worked.
     */
    private function convertForms( string $html ) : string {
        return (string) preg_replace_callback(
            '/<button\b[^>]*\bname=(["\'])add-to-cart\1[^>]*\bvalue=(["\'])(\d+)\2[^>]*>/i',
            function ( array $match ) : string {
                return $this->button( $match[0], (int) $match[3] );
            },
            $html
        );
    }

    /**
     * One button, rebuilt for Snipcart, or left exactly as it was.
     *
     * Left alone is a real outcome and not a failure: a product that is out of
     * stock, unpurchasable or simply gone keeps WordPress's button, which does
     * nothing on a static site — and that is better than a Snipcart button
     * taking an order the shop cannot fill.
     */
    private function button( string $tag, int $id ) : string {
        $item = $this->products->forId( $id );

        if ( null === $item ) {
            return $tag;
        }

        $attributes = [
            'class' => 'snipcart-add-item ' . $this->classOf( $tag ),
            'type' => 'button',
        ];

        foreach ( $item as $name => $value ) {
            if ( 'url' === $name ) {
                continue;
            }

            $attributes[ 'data-item-' . $name ] = $value;
        }

        /*
         * The URL Snipcart will fetch to check the price it was given, so it
         * has to be the address of the *published* page rather than the
         * WordPress one. It is also the reason a shop behind HTTP basic auth
         * cannot be tested this way: Snipcart's fetch gets the 401 and refuses
         * the item.
         */
        $attributes['data-item-url'] = $this->publishedUrl( (string) $item['url'] );

        $rebuilt = '<button';

        foreach ( $attributes as $name => $value ) {
            $rebuilt .= ' ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES ) . '"';
        }

        $this->converted++;

        return $rebuilt . '>';
    }

    /**
     * Keep whatever classes the theme put on the button.
     *
     * Minus WooCommerce's own behavioural ones: `ajax_add_to_cart` is what its
     * JavaScript looks for, and leaving it means two scripts fighting over one
     * click.
     */
    private function classOf( string $tag ) : string {
        if ( ! preg_match( '/\bclass=(["\'])(.*?)\1/i', $tag, $match ) ) {
            return '';
        }

        $keep = [];

        foreach ( preg_split( '/\s+/', $match[2] ) ?: [] as $class ) {
            if ( '' === $class ) {
                continue;
            }

            if ( in_array(
                $class,
                [ 'add_to_cart_button', 'ajax_add_to_cart', 'single_add_to_cart_button' ],
                true
            ) ) {
                continue;
            }

            $keep[] = $class;
        }

        return implode( ' ', $keep );
    }

    /**
     * A WordPress permalink as it will be once published.
     */
    private function publishedUrl( string $permalink ) : string {
        if ( '' === $this->deployment_url ) {
            return $permalink;
        }

        $path = (string) wp_parse_url( $permalink, PHP_URL_PATH );

        return $this->deployment_url . ( '' === $path ? '/' : $path );
    }
}
