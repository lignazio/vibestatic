<?php
/**
 * The module's page: what the archive is, and the two things you can do to it.
 *
 * Rewritten. The original echoed four values with no escaping, two of them into
 * an `href`, and its "Refresh page" link pointed at a page slug that does not
 * exist — so the one link on the page led to "you are not authorized".
 *
 * @package WP2StaticZip
 */

namespace WP2StaticZip;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/** @var string $page */
$page = $view['page'];

/** @var bool $zip_exists */
$zip_exists = (bool) $view['zip_exists'];

/** @var string $zip_size */
$zip_size = $view['zip_size'];

/** @var string $zip_created */
$zip_created = $view['zip_created'];
?>

<div class="wrap">

<h1><?php esc_html_e( 'ZIP Deployment', 'vibestatic' ); ?></h1>

<p>
    <?php
    esc_html_e(
        'Select ZIP as the deployer on the Add-ons page, then run a deployment: the generated site is packed into a single archive.',
        'vibestatic'
    );
    ?>
</p>

<?php if ( ! $zip_exists ) : ?>

    <p><em><?php esc_html_e( 'No archive yet. Run a deployment with ZIP selected.', 'vibestatic' ); ?></em></p>

<?php else : ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Size', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Created', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo esc_html( $zip_size ); ?></td>
                <td><?php echo esc_html( $zip_created ); ?></td>
                <td>
                    <?php
                    /*
                     * Two forms, not a link and a form. The download used to be
                     * an <a> straight at the archive's uploads URL: a guessable
                     * address anyone could pass on. Through admin-post it is an
                     * action that asks who is asking.
                     */
                    ?>
                    <form
                        style="display:inline-block;margin-right:8px;"
                        method="POST"
                        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( $nonce_action ); ?>
                        <input name="action" type="hidden" value="wp2static_zip_download" />
                        <button class="button button-primary">
                            <?php esc_html_e( 'Download ZIP', 'vibestatic' ); ?>
                        </button>
                    </form>

                    <form
                        style="display:inline-block;"
                        method="POST"
                        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( $nonce_action ); ?>
                        <input name="action" type="hidden" value="wp2static_zip_delete" />
                        <button class="button">
                            <?php esc_html_e( 'Delete ZIP', 'vibestatic' ); ?>
                        </button>
                    </form>
                </td>
            </tr>
        </tbody>
    </table>

    <p>
        <em>
            <?php
            esc_html_e(
                'The archive also stays on disk under the uploads directory, where — like the generated site itself — it is readable by anyone who knows the address.',
                'vibestatic'
            );
            ?>
        </em>
    </p>

<?php endif; ?>

<p>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>">
        <?php esc_html_e( 'Refresh this page', 'vibestatic' ); ?>
    </a>
    <?php esc_html_e( 'to see the latest state.', 'vibestatic' ); ?>
</p>

</div>
