<?php

namespace WP2Static\WooCommerce;

use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Turning WooCommerce's add-to-cart buttons into Snipcart ones.
 *
 * The markup in these fixtures is not invented: it is what WooCommerce 10
 * actually renders, taken off a running shop — the `<form class="cart">` with a
 * submit button on a product page, and the block button carrying
 * `data-product_id` in a listing. A converter tested against markup somebody
 * imagined is a converter tested against nothing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SnipcartConverterTest extends TestCase {

    const LISTING_BUTTON = '<button
                        class="wp-block-button__link wp-element-button wc-block-components-product-button__button add_to_cart_button ajax_add_to_cart product_type_simple"
                        type="button" data-product_id="77" data-product_sku="maglietta-01" aria-label="Add to cart">';

    const SINGLE_BUTTON = '<form class="cart" action="http://localhost:8080/product/maglietta/" method="post">'
        . '<button type="submit" name="add-to-cart" value="77" class="single_add_to_cart_button button alt">Add to cart</button>'
        . '</form>';

    public function setUp() : void {
        WP_Mock::setUp();
    }

    public function tearDown() : void {
        WP_Mock::tearDown();
        Mockery::close();
    }

    /**
     * @param array<int, array<string, string>|null> $products id => item, or null
     */
    private function converter( array $products, string $deployment_url = 'https://negozio.example.com' ) : Converter {
        $index = Mockery::mock( ProductIndex::class );

        $index->shouldReceive( 'forId' )->andReturnUsing(
            function ( int $id ) use ( $products ) {
                return $products[ $id ] ?? null;
            }
        );

        WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
            function ( $url, $component ) {
                return parse_url( (string) $url, (int) $component );
            }
        );

        return new Converter( $index, $deployment_url );
    }

    /**
     * @return array<string, string>
     */
    private function maglietta() : array {
        return [
            'id' => 'maglietta-01',
            'name' => 'Maglietta',
            'price' => '29.00',
            'url' => 'http://localhost:8080/product/maglietta/',
        ];
    }

    public function testItConvertsTheListingButton() : void {
        $out = (string) $this->converter( [ 77 => $this->maglietta() ] )
            ->convert( self::LISTING_BUTTON );

        $this->assertStringContainsString( 'snipcart-add-item', $out );
        $this->assertStringContainsString( 'data-item-id="maglietta-01"', $out );
        $this->assertStringContainsString( 'data-item-price="29.00"', $out );
        $this->assertStringContainsString( 'data-item-name="Maglietta"', $out );
    }

    public function testItConvertsTheSingleProductButton() : void {
        $out = (string) $this->converter( [ 77 => $this->maglietta() ] )
            ->convert( self::SINGLE_BUTTON );

        $this->assertStringContainsString( 'snipcart-add-item', $out );
        $this->assertStringContainsString( 'data-item-id="maglietta-01"', $out );
    }

    /**
     * The button stops being a submit button.
     *
     * On the single product page it sits inside a `<form class="cart">` that
     * posts to WordPress. Left as `type="submit"` the click would submit that
     * form as well as adding to the cart — and on a static site submitting it
     * does nothing except reload the page, which looks exactly like a purchase
     * that failed.
     */
    public function testTheButtonNoLongerSubmitsTheWooCommerceForm() : void {
        $out = (string) $this->converter( [ 77 => $this->maglietta() ] )
            ->convert( self::SINGLE_BUTTON );

        $this->assertStringContainsString( 'type="button"', $out );
        $this->assertStringNotContainsString( 'type="submit"', $out );
    }

    /**
     * WooCommerce's behavioural classes go; the theme's stay.
     *
     * `ajax_add_to_cart` is what WooCommerce's own script binds to. Left on the
     * button it would mean two carts responding to one click.
     */
    public function testItDropsWooCommercesOwnClassesAndKeepsTheThemes() : void {
        $out = (string) $this->converter( [ 77 => $this->maglietta() ] )
            ->convert( self::LISTING_BUTTON );

        $this->assertStringNotContainsString( 'ajax_add_to_cart', $out );
        $this->assertStringNotContainsString( 'add_to_cart_button', $out );
        $this->assertStringContainsString( 'wp-element-button', $out );
    }

    /**
     * The URL Snipcart will fetch is the published one.
     *
     * Snipcart re-fetches it to check the price against the page rather than
     * trusting the button. Pointed at the WordPress address it would be
     * checking a site the buyer is not on — and on a site not yet public, a
     * site it cannot reach at all.
     */
    public function testTheItemURLIsThePublishedOne() : void {
        $out = (string) $this->converter( [ 77 => $this->maglietta() ] )
            ->convert( self::SINGLE_BUTTON );

        $this->assertStringContainsString(
            'data-item-url="https://negozio.example.com/product/maglietta/"',
            $out
        );
    }

    /**
     * A product that cannot be bought keeps WordPress's button.
     *
     * Out of stock, unpurchasable, or simply gone: a Snipcart button on any of
     * those takes an order the shop cannot fill, which is worse than a button
     * that does nothing on a static site.
     */
    public function testAProductThatCannotBeBoughtIsLeftAlone() : void {
        $converter = $this->converter( [ 77 => null ] );

        $this->assertNull( $converter->convert( self::LISTING_BUTTON ) );
        $this->assertSame( 0, $converter->converted() );
    }

    /**
     * A page with nothing to sell is not rewritten at all.
     */
    public function testAPageWithoutProductsIsNotTouched() : void {
        $converter = $this->converter( [] );

        $this->assertNull( $converter->convert( '<html><body><p>nessun prodotto</p></body></html>' ) );
    }

    /**
     * Two products on one page each get their own item.
     */
    public function testItConvertsEveryButtonOnAListing() : void {
        $second = str_replace(
            [ 'data-product_id="77"', 'maglietta-01' ],
            [ 'data-product_id="76"', 'tazza-01' ],
            self::LISTING_BUTTON
        );

        $converter = $this->converter(
            [
                77 => $this->maglietta(),
                76 => [
                    'id' => 'tazza-01',
                    'name' => 'Tazza',
                    'price' => '12.50',
                    'url' => 'http://localhost:8080/product/tazza/',
                ],
            ]
        );

        $out = (string) $converter->convert( self::LISTING_BUTTON . $second );

        $this->assertSame( 2, $converter->converted() );
        $this->assertStringContainsString( 'data-item-id="maglietta-01"', $out );
        $this->assertStringContainsString( 'data-item-id="tazza-01"', $out );
    }

    /**
     * A name with a quote in it does not break out of the attribute.
     */
    public function testItEscapesTheProductName() : void {
        $item = $this->maglietta();
        $item['name'] = 'Maglietta "grande"';

        $out = (string) $this->converter( [ 77 => $item ] )->convert( self::LISTING_BUTTON );

        $this->assertStringNotContainsString( 'data-item-name="Maglietta "grande""', $out );
        $this->assertStringContainsString( '&quot;grande&quot;', $out );
    }
}
