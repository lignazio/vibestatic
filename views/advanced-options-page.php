<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var array<string, object{name: string, value: string, label: string, description: string, type: string}> $options */
$options = $view['coreOptions'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

$row = function ( string $name ) use ( $options ) : string {
    /** @var array<string, ?string> $opt */
    $opt = (array) $options[ $name ];

    return '<tr><td style="width: 50%">' . OptionRenderer::optionLabel( $opt, true ) .
            '</td><td>' . OptionRenderer::optionInput( $opt ) . '</td></tr>';
};

?>

<div class="wrap">
    <form
        name="wp2static-ui-advanced-options"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <h1><?php esc_html_e( 'Advanced Options', 'vibestatic' ); ?></h1>

    <h2><?php esc_html_e( 'Detection Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'additionalPathsToCrawl' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Forms', 'vibestatic' ); ?></h2>

    <p>
        <?php
        esc_html_e(
            'A static site cannot run PHP, so a form left pointing at WordPress is a button that does nothing. Search and comment forms are left alone: they cannot be made to work by pointing them elsewhere, and removing them would be a larger change than this should make on its own.',
            'vibestatic'
        );
        ?>
    </p>

    <table class="widefat striped">
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'formAction' );
            echo $row( 'formProvider' );
            echo $row( 'formRules' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Detection Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'filenamesToIgnore' );
            echo $row( 'fileExtensionsToIgnore' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <p></p>

    <h2><?php esc_html_e( 'Post-processing Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'crawlConcurrency' );
            echo $row( 'skipURLRewrite' );
            echo $row( 'removeWordPressCruft' );
            echo $row( 'hostsToRewrite' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <p></p>

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_ui_save_advanced_options" />

    <button class="button btn-primary" type="submit"><?php esc_html_e( 'Save options', 'vibestatic' ); ?></button>

    </form>
</div>
