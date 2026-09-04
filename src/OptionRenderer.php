<?php
/**
 * Costruisce il markup dei campi della pagina delle opzioni.
 *
 * Queste funzioni restituiscono HTML gia' pronto: chi le chiama deve stamparlo
 * cosi' com'e', non passarlo da esc_html(). L'escaping si fa qui, valore per
 * valore, perche' qui si sa quale contesto e' quale — un id e un name vanno in
 * esc_attr(), il contenuto di una textarea in esc_textarea(), un'etichetta in
 * esc_html().
 *
 * Prima meta' dei valori non era escapata affatto, e nelle view il ritorno di
 * queste funzioni finiva dentro esc_html(): il risultato era che gli utenti
 * leggevano `<input class="widefat" ...>` scritto in chiaro al posto dei campi.
 * Le due cose insieme facevano l'unica combinazione peggiore di entrambe: la
 * pagina inutilizzabile e i valori comunque non protetti.
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
     * I valori arrivano da un array<string, mixed> letto dal database, quindi
     * sono `mixed`. strval() su mixed non e' lecito — un array o un oggetto
     * senza __toString ci moriscono sopra — e le regole strict di PHPStan lo
     * segnalano a ragione. Qui un valore non scalare diventa stringa vuota:
     * un campo vuoto e' un problema visibile, un fatal error in mezzo alla
     * pagina delle opzioni no.
     *
     * @param mixed $value
     */
    private static function str( $value ) : string {
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
     */
    public static function optionInput( array $option ) : string {
        $option_input = call_user_func(
            [ 'WP2Static\OptionRenderer', self::INPUT_TYPE_FNS[ $option['type'] ] ],
            $option
        );

        return strval( $option_input );
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
     */
    public static function optionInputArray( array $option ) : string {
        $name = esc_attr( self::str( $option['name'] ) );

        return '<textarea class="widefat" cols=30 rows=10 id="' . $name . '" name="' .
               $name . '">' . esc_textarea( self::str( $option['blob_value'] ) ) . '</textarea>';
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
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
     * @return string HTML gia' escapato
     */
    public static function optionInputInteger( array $option ) : string {
        return self::textLikeInput( $option, 'number' );
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
     */
    public static function optionInputPassword( array $option ) : string {
        return self::textLikeInput( $option, 'password' );
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
     */
    public static function optionInputString( array $option ) : string {
        return self::textLikeInput( $option, 'text' );
    }

    /**
     * I tre input di testo differivano per il solo attributo type, e ognuno
     * aveva la sua copia della concatenazione: tre posti in cui sbagliare
     * l'escaping invece di uno.
     *
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
     */
    private static function textLikeInput( array $option, string $type ) : string {
        $name = esc_attr( self::str( $option['name'] ) );

        return '<input class="widefat" id="' . $name . '" name="' . $name .
               '" type="' . esc_attr( $type ) . '" value="' .
               esc_attr( self::str( $option['value'] ) ) . '">';
    }

    /**
     * @param array<string, mixed> $option
     * @return string HTML gia' escapato
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
