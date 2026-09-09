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

/**
 * Where each add-on's settings page is, slug => page or null.
 *
 * The gear used to link to `admin.php?page=<slug>` — the add-on's own slug,
 * `wp2static-addon-netlify` — and no such page exists, so it answered "Sorry,
 * you are not allowed to access this page" for every add-on and for all six
 * bundled modules. Working the page out is Controller::addonSettingsPage()'s
 * job, because there are two registration conventions and it takes asking
 * WordPress which one an add-on used; a view is no place for that.
 *
 * @var array<string, string|null> $settings_pages
 */
$settings_pages = $view['settings_pages'];
?>

<div class="wrap">
    <h1><?php esc_html_e( 'VibeStatic Add-ons', 'vibestatic' ); ?></h1>

    <p>
        <?php
        esc_html_e(
            'Only one deployer runs at a time: enabling one turns off whichever was enabled before. Add-ons of other kinds are independent.',
            'vibestatic'
        );
        ?>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Status', 'vibestatic' ); ?></th>
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

                        <p>
                            <?php if ( $addon->enabled ) : ?>
                                <strong><?php esc_html_e( 'Enabled', 'vibestatic' ); ?></strong>
                            <?php else : ?>
                                <?php esc_html_e( 'Disabled', 'vibestatic' ); ?>
                            <?php endif; ?>
                        </p>

                        <?php
                        /*
                         * The button says what pressing it does, and the state
                         * is the line above it. It used to say the state and do
                         * the opposite — a button reading "Enabled" that
                         * disabled the add-on — which is the one thing a
                         * control must not do. WordPress's own Plugins screen
                         * is the model: the row shows the state, the control
                         * offers the action.
                         *
                         * class="button" is not decoration either: without it
                         * WordPress styles nothing and the browser's default
                         * chrome comes through, which is what it looked like.
                         *
                         * The add-on's name is in the accessible name, so a
                         * screen reader announces "Enable S3" rather than six
                         * buttons all called "Enable" — the same thing the
                         * documentation and settings icons in this table
                         * already do.
                         */
                        ?>
                        <button type="submit" class="button">
                            <?php
                            echo $addon->enabled
                                ? esc_html__( 'Disable', 'vibestatic' )
                                : esc_html__( 'Enable', 'vibestatic' );
                            ?>
                            <span class="screen-reader-text"><?php echo esc_html( $addon->name ); ?></span>
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
                        <?php if ( isset( $settings_pages[ $addon->slug ] ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $settings_pages[ $addon->slug ] ) ); ?>">
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
                        <?php endif; ?>
                    </td>

                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>
</div>
