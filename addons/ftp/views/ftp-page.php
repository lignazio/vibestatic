<?php
/**
 * The module's settings page.
 *
 * @package WP2StaticFTP
 */

namespace WP2StaticFTP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/** @var array<string, object{name: string, value: string}> $options */
$options = $view['options'];

/** @var bool $has_extension */
$has_extension = (bool) $view['has_extension'];

/** @var bool $has_tls */
$has_tls = (bool) $view['has_tls'];

$value = function ( string $name ) use ( $options ) : string {
    return isset( $options[ $name ] ) ? $options[ $name ]->value : '';
};

$fields = [
    'host' => [ __( 'Host', 'vibestatic' ), 'text', '' ],
    'port' => [ __( 'Port', 'vibestatic' ), 'text', __( '21 unless the host says otherwise.', 'vibestatic' ) ],
    'username' => [ __( 'Username', 'vibestatic' ), 'text', '' ],
    'password' => [
        __( 'Password', 'vibestatic' ),
        'password',
        __( 'Stored encrypted.', 'vibestatic' ),
    ],
    'remote_root' => [
        __( 'Remote root', 'vibestatic' ),
        'text',
        __( 'Where the site goes on the server, for example /public_html. Leave empty for the directory you land in on login.', 'vibestatic' ),
    ],
];
?>

<div class="wrap">

<h2><?php esc_html_e( 'FTP Deployment', 'vibestatic' ); ?></h2>

<?php if ( ! $has_extension ) : ?>
    <div class="notice notice-error">
        <p>
            <?php
            esc_html_e(
                'This server has no FTP support in PHP. Install the ext-ftp extension, or use the sFTP module, which needs nothing beyond what the plugin already ships.',
                'vibestatic'
            );
            ?>
        </p>
    </div>
<?php endif; ?>

<form
    name="wp2static-ftp-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_ftp_save_options" />

    <table class="widefat striped">
        <tbody>
            <?php foreach ( $fields as $name => $field ) : ?>
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
                            id="<?php echo esc_attr( $name ); ?>"
                            name="<?php echo esc_attr( $name ); ?>"
                            class="widefat"
                            type="<?php echo esc_attr( $field[1] ); ?>"
                            maxlength="255"
                            value="<?php
                            echo esc_attr(
                                'password' === $name
                                    ? ( '' !== $value( $name )
                                        ? (string) \WP2Static\CoreOptions::encrypt_decrypt(
                                            'decrypt',
                                            $value( $name )
                                        )
                                        : '' )
                                    : $value( $name )
                            );
                            ?>"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td style="width:50%;">
                    <label for="use_tls"><?php esc_html_e( 'Encrypt the connection (FTPS)', 'vibestatic' ); ?></label>
                    <p>
                        <i>
                            <?php
                            esc_html_e(
                                'Leave this on. With it off, the password and then every byte of the site travel in the clear over whatever network is in between. It is here because some shared hosting still offers nothing else.',
                                'vibestatic'
                            );
                            ?>
                        </i>
                    </p>
                    <?php if ( ! $has_tls ) : ?>
                        <p>
                            <b>
                                <?php
                                esc_html_e(
                                    'This PHP build has no FTPS support, so this setting will have no effect.',
                                    'vibestatic'
                                );
                                ?>
                            </b>
                        </p>
                    <?php endif; ?>
                </td>
                <td>
                    <input
                        id="use_tls"
                        name="use_tls"
                        value="1"
                        <?php checked( '0' !== $value( 'use_tls' ) ); ?>
                        type="checkbox"
                    />
                </td>
            </tr>
        </tbody>
    </table>

    <br>

    <button class="button btn-primary"><?php esc_html_e( 'Save Options', 'vibestatic' ); ?></button>
</form>

</div>
