<?php
/**
 * The add-on's settings page.
 *
 * Rewritten. It was eleven near-identical blocks, none of them escaped — 57
 * violations of the escaping sniff, which had never looked at this directory —
 * and every label was read out of the add-on's own options table. That column
 * is written once, when the add-on is first activated, so it froze whichever
 * language happened to be active at that moment and could never be translated.
 * The labels live here now, like the core's and like directory-deployment's.
 *
 * @package WP2StaticSFTP
 *
 * @var array<string, mixed> $view
 */

namespace WP2StaticSFTP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var array<string, object{name: string, value: string}> $options */
$options = $view['options'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/**
 * The fields, in the order they are shown: name => [ label, type, help ].
 *
 * `password` and `passphrase` are rendered as password inputs, so the browser
 * masks them; they are also the two that are stored encrypted and have to be
 * decrypted to be shown back.
 *
 * @var array<string, array{0: string, 1: string, 2: string}>
 */
$fields = [
    'host' => [
        __( 'Remote host', 'vibestatic' ),
        'text',
        __( 'Host name of the sFTP server.', 'vibestatic' ),
    ],
    'port' => [
        __( 'Port', 'vibestatic' ),
        'number',
        __( 'Defaults to 22 when left empty.', 'vibestatic' ),
    ],
    'username' => [
        __( 'Username', 'vibestatic' ),
        'text',
        '',
    ],
    'password' => [
        __( 'Password', 'vibestatic' ),
        'password',
        __( 'Leave empty when authenticating with a private key.', 'vibestatic' ),
    ],
    'private_key' => [
        __( 'Private key path', 'vibestatic' ),
        'text',
        __( 'Path on this server to the private key. Takes precedence over the password.', 'vibestatic' ),
    ],
    'passphrase' => [
        __( 'Private key passphrase', 'vibestatic' ),
        'password',
        '',
    ],
    'remote_root' => [
        __( 'Remote root path', 'vibestatic' ),
        'text',
        __( 'Where the site is uploaded to. Empty means the login directory.', 'vibestatic' ),
    ],
    'dir_permissions' => [
        __( 'Remote directory permissions', 'vibestatic' ),
        'text',
        '',
    ],
    'file_permissions' => [
        __( 'Remote file permissions', 'vibestatic' ),
        'text',
        '',
    ],
    'owner' => [
        __( 'Remote owner', 'vibestatic' ),
        'text',
        '',
    ],
    'group' => [
        __( 'Remote group', 'vibestatic' ),
        'text',
        '',
    ],
];

?>

<div class="wrap">

<h2><?php esc_html_e( 'sFTP Deployment Options', 'vibestatic' ); ?></h2>

<form
    name="wp2static-sftp-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_sftp_save_options" />

    <table class="widefat striped">
        <tbody>
            <?php foreach ( $fields as $name => $field ) : ?>
                <?php
                if ( ! isset( $options[ $name ] ) ) {
                    continue;
                }

                $value = (string) $options[ $name ]->value;

                // The two secrets are stored encrypted and have to be turned
                // back before the form can show what is currently set.
                if ( 'password' === $field[1] && '' !== $value ) {
                    $value = \WP2Static\CoreOptions::encrypt_decrypt( 'decrypt', $value );
                }
                ?>
                <tr>
                    <td style="width:50%;">
                        <label for="<?php echo esc_attr( $name ); ?>">
                            <?php echo esc_html( $field[0] ); ?>
                        </label>
                        <?php if ( '' !== $field[2] ) : ?>
                            <p><i><?php echo esc_html( $field[2] ); ?></i></p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input
                            class="widefat"
                            id="<?php echo esc_attr( $name ); ?>"
                            name="<?php echo esc_attr( $name ); ?>"
                            type="<?php echo esc_attr( $field[1] ); ?>"
                            value="<?php echo esc_attr( $value ); ?>"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>

    <button class="button btn-primary"><?php esc_html_e( 'Save Options', 'vibestatic' ); ?></button>
</form>

</div>
