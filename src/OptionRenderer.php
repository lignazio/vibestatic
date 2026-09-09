<?php
/**
 * Builds the markup for the fields on the options pages.
 *
 * These functions return ready-made HTML: callers must print it as it is, not
 * pass it through esc_html(). The escaping happens here, value by value,
 * because this is where it is known which context is which — an id and a name
 * go through esc_attr(), a textarea's content through esc_textarea(), a label
 * through esc_html().
 *
 * Half the values used not to be escaped at all, and in the views the return of
 * these functions ended up inside esc_html(): the result was that users read
 * `<input class="widefat" ...>` in plain text where the fields should have
 * been. The two together made the one combination worse than either: the page
 * unusable and the values unprotected anyway.
 *
 * @package WP2Static
 */

namespace WP2Static;

class OptionRenderer {

    const INPUT_TYPE_FNS = [
        'array' => 'optionInputArray',
        'boolean' => 'optionInputBoolean',
        'integer' => 'optionInputInteger',
        'password' => 'optionInputPassword',
        'string' => 'optionInputString',
    ];

    /**
     * The values come from an array<string, mixed> read out of the database, so
     * they are `mixed`. strval() on mixed is not legitimate — an array, or an
     * object without __toString, dies on it — and PHPStan's strict rules flag
     * that rightly. Here a non-scalar value becomes an empty string: an empty
     * field is a visible problem, a fatal error in the middle of the options
     * page is not.
     *
     * @param mixed $value
     */
    private static function str( $value ) : string {
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInput( array $option ) : string {
        $type = $option['type'] ?? '';

        // The option's declared type picks the renderer. It comes out of a
        // mixed array, and a key that is not a string is not one of the four.
        if ( ! is_string( $type ) || ! isset( self::INPUT_TYPE_FNS[ $type ] ) ) {
            return '';
        }

        $option_input = call_user_func(
            [ 'WP2Static\OptionRenderer', self::INPUT_TYPE_FNS[ $type ] ],
            $option
        );

        return strval( $option_input );
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInputArray( array $option ) : string {
        $name = esc_attr( self::str( $option['name'] ) );

        return '<textarea class="widefat" cols=30 rows=10 id="' . $name . '" name="' .
               $name . '">' . esc_textarea( self::str( $option['blob_value'] ) ) . '</textarea>';
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInputBoolean( array $option ) : string {
        /**
         * @var int $unfiltered_value
         */
        $unfiltered_value = $option['unfiltered_value'];
        $checked = (int) $unfiltered_value === 1 ? ' checked' : '';
        $name = esc_attr( self::str( $option['name'] ) );

        return '<input id="' . $name . '" name="' . $name . '" value="1"' .
               ' type="checkbox"' . $checked . '>';
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInputInteger( array $option ) : string {
        return self::textLikeInput( $option, 'number' );
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInputPassword( array $option ) : string {
        return self::textLikeInput( $option, 'password' );
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionInputString( array $option ) : string {
        return self::textLikeInput( $option, 'text' );
    }

    /**
     * The three text inputs differed only by their type attribute, and each had
     * its own copy of the concatenation: three places to get the escaping wrong
     * instead of one.
     *
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML.
     */
    private static function textLikeInput( array $option, string $type ) : string {
        $name = esc_attr( self::str( $option['name'] ) );

        return '<input class="widefat" id="' . $name . '" name="' . $name .
               '" type="' . esc_attr( $type ) . '" value="' .
               esc_attr( self::str( $option['value'] ) ) . '">';
    }

    /**
     * @param array<string, mixed> $option
     * @return string Already-escaped HTML
     */
    public static function optionLabel( array $option, bool $description = false ) : string {
        $descr = $description && $option['description']
            ? '<br>' . esc_html( self::str( $option['description'] ) )
            : '';

        return '<label for="' . esc_attr( self::str( $option['name'] ) ) .
               '" style="font-weight: bold">' . esc_html( self::str( $option['label'] ) ) .
               '</label>' . $descr;
    }

}
