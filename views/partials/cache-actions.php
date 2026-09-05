<?php
/**
 * Il form «mostra oppure cancella» della pagina Caches, ripetuto per ogni cache.
 *
 * E' un file e non una closure dentro la view perche' le variabili di una
 * closure in un template restano `mixed` per l'analisi statica, e ogni valore
 * stampato diventa un cast da mixed che le regole strict vietano. Un `@var` in
 * cima a un file, invece, PHPStan lo legge — e' lo stesso motivo per cui il
 * paginatore e' un partial.
 *
 * @package WP2Static
 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var string $nonce_action */
/** @var string $form_name */
/** @var array<string, string> $form_actions Valore dell'azione => etichetta. */
/** @var array<string, string> $form_hidden Campi nascosti in piu'. */

$form_hidden = isset( $form_hidden ) ? $form_hidden : [];

?>
<form
    name="<?php echo esc_attr( $form_name ); ?>"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>

    <label class="screen-reader-text" for="<?php echo esc_attr( $form_name ); ?>-action">
        <?php esc_html_e( 'Action', 'vibestatic' ); ?>
    </label>
    <select name="action" id="<?php echo esc_attr( $form_name ); ?>-action" class="wp2static-select">
        <?php foreach ( $form_actions as $action_value => $action_label ) : ?>
            <option value="<?php echo esc_attr( $action_value ); ?>"><?php echo esc_html( $action_label ); ?></option>
        <?php endforeach; ?>
    </select>

    <?php foreach ( $form_hidden as $hidden_name => $hidden_value ) : ?>
        <input name="<?php echo esc_attr( $hidden_name ); ?>" type="hidden" value="<?php echo esc_attr( $hidden_value ); ?>" />
    <?php endforeach; ?>

    <button class="button btn-danger"><?php esc_html_e( 'Go', 'vibestatic' ); ?></button>

</form>

<?php
/*
 * `$form_hidden` non deve sopravvivere alla require: la view include questo
 * file cinque volte di seguito nello stesso scope, e un campo nascosto lasciato
 * indietro finirebbe nel form successivo che non lo vuole.
 */
unset( $form_hidden );
