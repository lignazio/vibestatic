<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/**
 * @var list<object{
 *     slug: string,
 *     enabled: int,
 *     name: string,
 *     description: string,
 *     type: string,
 *     docs_url: string
 * }> $addons
 */
$addons = $view['addons'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];
?>

<div class="wrap">
    <br>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Enabled', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Name', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Type', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Documentation URL', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Configure', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $addons ) : ?>
                <tr>
                    <td colspan="5">
                        <?php
                        printf(
                            /* translators: %s: link to the project page, with the link text as its own string. */
                            esc_html__( 'No add-ons are installed. %s', 'vibestatic' ),
                            '<a href="https://github.com/lignazio/vibestatic">' .
                                esc_html__( 'Get add-ons', 'vibestatic' ) .
                            '</a>'
                        );
                        ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ( $addons as $addon ) : ?>
                <tr>
                    <td>
                        <form
                            name="wp2static-toggle-addon"
                            method="POST"
                            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

                        <?php wp_nonce_field( $nonce_action ); ?>
                        <input name="action" type="hidden" value="wp2static_toggle_addon" />
                        <input name="addon_slug" type="hidden" value="<?php echo esc_attr( $addon->slug ); ?>" />

                        <button>
                            <?php
                            echo $addon->enabled
                                ? esc_html__( 'Enabled', 'vibestatic' )
                                : esc_html__( 'Disabled', 'vibestatic' );
                            ?>
                        </button>

                        </form>

                    </td>
                    <td>
                        <?php echo esc_html( $addon->name ); ?>
                        <br>
                        <?php echo esc_html( $addon->description ); ?>
                    </td>
                    <td><?php echo esc_html( $addon->type ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $addon->docs_url ); ?>">
                            <span class="dashicons dashicons-book-alt"></span>
                            <span class="screen-reader-text">
                                <?php
                                printf(
                                    /* translators: %s: add-on name. */
                                    esc_html__( 'Documentation for %s', 'vibestatic' ),
                                    esc_html( $addon->name )
                                );
                                ?>
                            </span>
                        </a>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( "admin.php?page={$addon->slug}" ) ); ?>">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <span class="screen-reader-text">
                                <?php
                                printf(
                                    /* translators: %s: add-on name. */
                                    esc_html__( 'Configure %s', 'vibestatic' ),
                                    esc_html( $addon->name )
                                );
                                ?>
                            </span>
                        </a>
                    </td>

                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>
</div>
