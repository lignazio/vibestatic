<?php
/**
 * The "show or delete" form on the Caches page, repeated for every cache.
 *
 * It is a file rather than a closure inside the view because a closure's
 * variables in a template stay `mixed` to static analysis, and every printed
 * value then becomes a cast from mixed that the strict rules forbid. A `@var`
 * at the top of a file, on the other hand, PHPStan reads — the same reason the
 * paginator is a partial.
 *
 * @package WP2Static
 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var string $nonce_action */
/** @var string $form_name */
/** @var array<string, string> $form_actions Action value => label. */
/** @var array<string, string> $form_hidden Extra hidden fields. */

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
 * `$form_hidden` must not survive the require: the view includes this file five
 * times in a row in the same scope, and a hidden field left behind would end up
 * in the next form, which does not want it.
 */
unset( $form_hidden );
