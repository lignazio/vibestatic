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
        name="wp2static-ui-options"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <h2><?php esc_html_e( 'Detection Options', 'vibestatic' ); ?></h2>

    <h4><?php esc_html_e( 'Control Detected URLs', 'vibestatic' ); ?></h4>

    <p><?php esc_html_e( 'VibeStatic will crawl these WordPress URLs to generate a static site.', 'vibestatic' ); ?></p>

    <table class="striped widefat">
        <thead>
            <tr>
                <th style="width:50%;"><?php esc_html_e( 'URL Type', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Include in detection', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'detectCustomPostTypes' );
            echo $row( 'detectPages' );
            echo $row( 'detectPosts' );
            echo $row( 'detectUploads' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Crawling Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            echo $row( 'basicAuthUser' );
            echo $row( 'basicAuthPassword' );
            echo $row( 'useCrawlCaching' );
            echo $row( 'addURLsWhileCrawling' );
            echo $row( 'detectRedirectionPluginURLs' );
            echo $row( 'crawlChunkSize' );
            echo $row( 'crawlProgressReportInterval' );
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Post-processing Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            echo $row( 'deploymentURL' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            ?>
        </tbody>
    </table>

    <h2><?php esc_html_e( 'Deployment Options', 'vibestatic' ); ?></h2>

    <table class="widefat striped">
        <tbody>
            <?php
            echo $row( 'completionEmail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
            ?>
            <tr>
                <td style="width:50%;">
                    <?php
                    /** @var array<string, ?string> $webhook_option */
                    $webhook_option = (array) $options['completionWebhook'];
                    echo OptionRenderer::optionLabel( $webhook_option ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup already escaped by OptionRenderer.
                    ?>
                </td>
                <td>
                    <input
                        style="width:80%;"
                        type="url"
                        id="completionWebhook"
                        name="completionWebhook"
                        value="<?php echo esc_attr( $options['completionWebhook']->value ); ?>"
                    />

                    <select
                        id="<?php echo esc_attr( $options['completionWebhookMethod']->name ); ?>"
                        name="<?php echo esc_attr( $options['completionWebhookMethod']->name ); ?>"
                        >
                        <option
                            value="POST"
                            <?php selected( $options['completionWebhookMethod']->value, 'POST' ); ?>
                            >POST</option>
                        <option
                            value="GET"
                            <?php selected( $options['completionWebhookMethod']->value, 'GET' ); ?>
                            >GET</option>
                    </select>
                </td>
            </tr>
        </tbody>
    </table>

    <br>

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_ui_save_options" />

    <button class="button btn-primary" type="submit"><?php esc_html_e( 'Save options', 'vibestatic' ); ?></button>

    </form>
</div>
