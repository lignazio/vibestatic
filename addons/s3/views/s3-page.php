<?php
/**
 * The module's settings page.
 *
 * Rewritten. The original echoed forty values with no escaping at all, two of
 * them the decrypted AWS secret access key straight into a `value` attribute —
 * a key holding a quote closed the attribute early.
 *
 * @package WP2StaticS3
 */

namespace WP2StaticS3;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/** @var array<string, object{name: string, value: string}> $options */
$options = $view['options'];

$value = function ( string $name ) use ( $options ) : string {
    return isset( $options[ $name ] ) ? $options[ $name ]->value : '';
};

$fields = [
    's3Bucket' => [ __( 'Bucket', 'vibestatic' ), 'text', '' ],
    's3Region' => [
        __( 'Region', 'vibestatic' ),
        'text',
        __( 'The region the bucket is in, for example eu-south-1. It is part of the signature, so a wrong one is refused rather than redirected.', 'vibestatic' ),
    ],
    's3RemotePath' => [
        __( 'Path in the bucket', 'vibestatic' ),
        'text',
        __( 'A folder inside the bucket. Leave empty to publish at its root.', 'vibestatic' ),
    ],
    's3AccessKeyID' => [ __( 'Access key ID', 'vibestatic' ), 'text', '' ],
    's3SecretAccessKey' => [
        __( 'Secret access key', 'vibestatic' ),
        'password',
        __( 'Stored encrypted.', 'vibestatic' ),
    ],
    's3ObjectACL' => [
        __( 'Object ACL', 'vibestatic' ),
        'text',
        __( 'Leave empty unless the bucket needs one. A bucket created since 2023 has ACLs disabled and refuses a request that carries one; older buckets serving a public site want public-read.', 'vibestatic' ),
    ],
    's3CacheControl' => [
        __( 'Cache-Control', 'vibestatic' ),
        'text',
        __( 'Optional, sent with every object, for example max-age=3600. There is no safe default: what suits a hashed asset is the opposite of what suits a page.', 'vibestatic' ),
    ],
    'cfDistributionID' => [
        __( 'CloudFront distribution ID', 'vibestatic' ),
        'text',
        __( 'Optional. With one set, the paths that changed are invalidated after each deploy.', 'vibestatic' ),
    ],
    'cfMaxPathsToInvalidate' => [
        __( 'Most paths to invalidate', 'vibestatic' ),
        'text',
        __( 'Above this many changes it invalidates everything instead, which is one request rather than thousands. CloudFront gives a thousand paths a month free and charges beyond that.', 'vibestatic' ),
    ],
    'cfAccessKeyID' => [
        __( 'CloudFront access key ID', 'vibestatic' ),
        'text',
        __( 'Optional. Leave empty to invalidate with the S3 credentials above.', 'vibestatic' ),
    ],
    'cfSecretAccessKey' => [
        __( 'CloudFront secret access key', 'vibestatic' ),
        'password',
        __( 'Optional, stored encrypted.', 'vibestatic' ),
    ],
];
?>

<div class="wrap">

<h2><?php esc_html_e( 'S3 Deployment', 'vibestatic' ); ?></h2>

<p>
    <?php
    esc_html_e(
        'The credentials need only s3:PutObject and s3:DeleteObject on the bucket, plus cloudfront:CreateInvalidation if a distribution is set. An account with more than that is an account that can do more than publish a site.',
        'vibestatic'
    );
    ?>
</p>

<form
    name="wp2static-s3-save-options"
    method="POST"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_s3_save_options" />

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
                                in_array( $name, Controller::SECRETS, true )
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
        </tbody>
    </table>

    <br>

    <button class="button btn-primary"><?php esc_html_e( 'Save Options', 'vibestatic' ); ?></button>
</form>

</div>
