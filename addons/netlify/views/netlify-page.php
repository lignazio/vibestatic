<?php
/**
 * The module's settings page.
 *
 * Rewritten. The original echoed ten values with no escaping at all, one of
 * them the decrypted Netlify personal access token straight into a `value`
 * attribute — a token holding an apostrophe closed the attribute early.
 *
 * @package WP2StaticNetlify
 */

namespace WP2StaticNetlify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/** @var array<string, object{name: string, value: string}> $options */
$options = $view['options'];

$labels = [
    'siteID' => __( 'Site ID', 'vibestatic' ),
    'accessToken' => __( 'Personal access token', 'vibestatic' ),
];
?>

<div class="wrap">

<h2><?php esc_html_e( 'Netlify Deployment', 'vibestatic' ); ?></h2>

<form
    name="wp2static-netlify-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_netlify_save_options" />

    <table class="widefat striped">
        <tbody>
            <tr>
                <td style="width:50%;">
                    <label for="siteID"><?php echo esc_html( $labels['siteID'] ); ?></label>
                    <p><i><?php esc_html_e( 'Found under Site configuration in the Netlify dashboard.', 'vibestatic' ); ?></i></p>
                </td>
                <td>
                    <input
                        id="siteID"
                        name="siteID"
                        class="widefat"
                        type="text" maxlength="255"
                        value="<?php echo esc_attr( isset( $options['siteID'] ) ? $options['siteID']->value : '' ); ?>"
                    />
                </td>
            </tr>

            <tr>
                <td style="width:50%;">
                    <label for="accessToken"><?php echo esc_html( $labels['accessToken'] ); ?></label>
                    <p><i><?php esc_html_e( 'Stored encrypted. Create one under User settings / Applications in Netlify.', 'vibestatic' ); ?></i></p>
                </td>
                <td>
                    <input
                        id="accessToken"
                        name="accessToken"
                        class="widefat"
                        type="password" maxlength="255"
                        value="<?php
                        echo esc_attr(
                            isset( $options['accessToken'] ) && '' !== $options['accessToken']->value
                                ? (string) \WP2Static\CoreOptions::encrypt_decrypt(
                                    'decrypt',
                                    $options['accessToken']->value
                                )
                                : ''
                        );
                        ?>"
                    />
                </td>
            </tr>
        </tbody>
    </table>

    <br>

    <button class="button btn-primary"><?php esc_html_e( 'Save Options', 'vibestatic' ); ?></button>
</form>

</div>
