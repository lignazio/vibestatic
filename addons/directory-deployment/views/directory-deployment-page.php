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
 * @package WP2StaticDirectoryDeployer

 */

namespace WP2StaticDirectoryDeployer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var array<string, object> $options */
$options = $view['options'];

/*
 * Le etichette stanno qui, non nella colonna `label` della tabella dell'addon.
 * Quella colonna e' scritta da `seedOptions()` alla prima apertura della pagina:
 * tradurla vorrebbe dire congelare nel database la lingua attiva in quel
 * momento, e chi cambiasse lingua al sito si ritroverebbe l'interfaccia meta' e
 * meta'. Il core ha gia' fatto la stessa scelta e le due colonne le ha proprio
 * lasciate cadere.
 */
$labels = [
    'directoryDeploymentTargetDirectory' => __( 'Target directory (absolute path)', 'vibestatic-directory-deployment' ),
    'directoryDeploymentDeleteBeforeDeployment' => __( 'Delete target directory before deployment', 'vibestatic-directory-deployment' ),
    'directoryDeploymentAdditionalSourceDirectory' => __( 'Additional source directory to include in deployment (absolute path)', 'vibestatic-directory-deployment' ),
];

/**
 * Stampa una riga della tabella per un'opzione di testo.
 *
 * @param object $option Opzione da rendere.
 * @param string $label  Etichetta gia' tradotta.
 */
$text_row = function ( $option, string $label ) : void {
    ?>
    <tr>
        <td style="width:50%;">
            <label for="<?php echo esc_attr( $option->name ); ?>">
                <?php echo esc_html( $label ); ?>
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

<div class="wrap">

<h2><?php esc_html_e( 'Directory Deployment Options', 'vibestatic-directory-deployment' ); ?></h2>

<form
    name="wp2static-directory-deployment-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_directory_deployment_save_options" />

    <table class="widefat striped">
        <tbody>
            <?php
            $text_row(
                $options['directoryDeploymentTargetDirectory'],
                $labels['directoryDeploymentTargetDirectory']
            );
            ?>

            <tr>
                <td style="width:50%;">
                    <label for="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>">
                        <?php echo esc_html( $labels['directoryDeploymentDeleteBeforeDeployment'] ); ?>
                    </label>
                    <p><i><?php esc_html_e( 'Leave this off and each deployment copies only the files that changed, removing the ones that are gone. Turn it on and the target directory is emptied before every deployment: that is for starting over, not for daily use — while it empties, the published site is not there.', 'vibestatic-directory-deployment' ); ?></i></p>
                </td>
                <td>
                    <input
                        id="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>"
                        name="<?php echo esc_attr( $options['directoryDeploymentDeleteBeforeDeployment']->name ); ?>"
                        value="1"
                        <?php checked( 1, (int) $options['directoryDeploymentDeleteBeforeDeployment']->value ); ?>
                        type="checkbox"
                    />
                </td>
            </tr>

            <?php
            $text_row(
                $options['directoryDeploymentAdditionalSourceDirectory'],
                $labels['directoryDeploymentAdditionalSourceDirectory']
            );
            ?>
        </tbody>
    </table>

    <br>

    <button class="button btn-primary"><?php esc_html_e( 'Save Options', 'vibestatic-directory-deployment' ); ?></button>
</form>

</div>
