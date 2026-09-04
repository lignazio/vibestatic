<?php
/**
 * Pagina di configurazione dell'addon.
 *
 * Riscritta. Quella di prima si chiamava `copy-page.php` mentre il Controller
 * ne richiedeva un'altra, quindi la pagina non si e' mai aperta: dava un fatal
 * error. Dentro, tre nomi di opzione che non esistono piu' — `copyTargetFolder`
 * al posto di `directoryDeploymentTargetDirectory` — e un'azione di form
 * altrettanto vecchia, `wp2static_copy_save_options`, che nessuno ascolta. Sono
 * gli ultimi residui del rinominamento lasciato a meta` nel 2021.
 *
 * @var mixed[] $view
 * @package WP2StaticDirectoryDeployer
 */

$options = $view['options'];

/**
 * Stampa una riga della tabella per un'opzione di testo.
 *
 * @param object $option Opzione da rendere.
 */
$text_row = function ( $option ) : void {
    ?>
    <tr>
        <td style="width:50%;">
            <label for="<?php echo esc_attr( $option->name ); ?>">
                <?php echo esc_html( $option->label ); ?>
            </label>
        </td>
        <td>
            <input
                id="<?php echo esc_attr( $option->name ); ?>"
                name="<?php echo esc_attr( $option->name ); ?>"
                class="widefat"
                type="text" maxlength="255"
                value="<?php echo esc_attr( $option->value ); ?>"
            />
        </td>
    </tr>
    <?php
};
?>

<h2>Directory Deployment Options</h2>

<form
    name="wp2static-directory-deployment-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_directory_deployment_save_options" />

    <table class="widefat striped">
        <tbody>
            <?php $text_row( $options['directoryDeploymentTargetDirectory'] ); ?>

            <tr>
                <td style="width:50%;">
                    <label
                        for="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>"
                    ><?php echo esc_html( $options['directoryDeploymentDeleteBeforeDeployment']->label ); ?></label>
                    <p><i>
                        Lasciando questa casella spenta il deploy copia solo i
                        file cambiati e cancella quelli spariti. Accendendola si
                        svuota la destinazione a ogni pubblicazione: serve per
                        ripartire da zero, non per l'uso quotidiano — mentre
                        svuota, il sito pubblicato non c'è.
                    </i></p>
                </td>
                <td>
                    <input
                        id="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>"
                        name="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>"
                        value="1"
                        <?php echo 1 === (int) $options['directoryDeploymentDeleteBeforeDeployment']->value ? 'checked' : ''; ?>
                        type="checkbox"
                    />
                </td>
            </tr>

            <?php $text_row( $options['directoryDeploymentAdditionalSourceDirectory'] ); ?>
        </tbody>
    </table>

    <br>

    <button class="button btn-primary">Save Options</button>
</form>
