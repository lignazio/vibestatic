<?php
/**
 * The add-on's settings page.
 *
 * Rewritten. The previous one was named `copy-page.php` while the Controller
 * required a different filename, so the page never opened at all: it gave a
 * fatal error. Inside it were three option names that no longer exist —
 * `copyTargetFolder` instead of `directoryDeploymentTargetDirectory` — and an
 * equally stale form action, `wp2static_copy_save_options`, that nothing
 * listens for. They were the last leftovers of the half-finished 2021 rename.
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
 * The labels live here, not in the add-on table's `label` column. That column
 * was written by `seedOptions()` the first time the page was opened: translating
 * it would mean freezing whichever language was active at that moment into the
 * database, and anyone changing the site language would end up with a half-and-
 * half interface. The core made the same decision and dropped both columns
 * outright.
 */
$labels = [
    'directoryDeploymentTargetDirectory' => __( 'Target directory (absolute path)', 'vibestatic-directory-deployment' ),
    'directoryDeploymentDeleteBeforeDeployment' => __( 'Delete target directory before deployment', 'vibestatic-directory-deployment' ),
    'directoryDeploymentAdditionalSourceDirectory' => __( 'Additional source directory to include in deployment (absolute path)', 'vibestatic-directory-deployment' ),
];

/**
 * Print a table row for a text option.
 *
 * @param object $option The option to render.
 * @param string $label  Already-translated label.
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
