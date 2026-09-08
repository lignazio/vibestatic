<?php
/**
 * What Snipcart needs to know about a product, asked of WooCommerce.
 *
 * **Asked, not read off the page.** The add-on this replaces looked for the
 * string `add_to_cart_button` in the markup and wrote a line to the PHP error
 * log when it found one; the conversion it was supposed to do was two comments.
 * Had it been written the obvious way it would have scraped the price out of
 * the rendered HTML, and that is the thing to avoid: the markup around a price
 * changes with every theme, while the product does not. The button carries the
 * product's ID, so the ID is what is used, and everything else comes from
 * WooCommerce.
 *
 * @package WP2Static
 */

namespace WP2Static\WooCommerce;

class ProductIndex {

    /**
     * @var array<int, array<string, string>|null> Products already looked up.
     */
    private $cache = [];

    /**
     * What to put on the Snipcart button for a product, or null.
     *
     * Null for a product that is not there, is not published, or cannot be
     * bought — a Snipcart button on something out of stock takes an order the
     * shop cannot fill.
     *
     * @return array<string, string>|null
     */
    public function forId( int $id ) : ?array {
        if ( array_key_exists( $id, $this->cache ) ) {
            return $this->cache[ $id ];
        }

        $this->cache[ $id ] = $this->lookUp( $id );

        return $this->cache[ $id ];
    }

    /**
     * @return array<string, string>|null
     */
    private function lookUp( int $id ) : ?array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }

        $product = wc_get_product( $id );

        if ( ! $product instanceof \WC_Product ) {
            return null;
        }

        if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return null;
        }

        $price = $product->get_price();

        if ( '' === $price ) {
            return null;
        }

        $item = [
            'id' => (string) $id,
            'name' => (string) $product->get_name(),
            'price' => (string) $price,
            'url' => (string) get_permalink( $id ),
        ];

        $sku = (string) $product->get_sku();

        if ( '' !== $sku ) {
            // Snipcart shows it on the order, and it is what the shop's own
            // records are keyed by.
            $item['id'] = $sku;
        }

        $description = wp_strip_all_tags( (string) $product->get_short_description() );

        if ( '' !== trim( $description ) ) {
            $item['description'] = trim( $description );
        }

        $image = wp_get_attachment_url( (int) $product->get_image_id() );

        if ( is_string( $image ) && '' !== $image ) {
            $item['image'] = $image;
        }

        return $item;
    }
}
