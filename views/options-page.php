<?php
// phpcs:disable Generic.Files.LineLength.MaxExceeded                              
// phpcs:disable Generic.Files.LineLength.TooLong                                  

/**
 * @var mixed[] $view
 */

use WP2Static\OptionRenderer;

/**
 * @var array<string, mixed> $options
 */
$options = $view['coreOptions'];

$row = function ( $name ) use ( $options ) {
    $opt = (array) $options[ $name ];
    return '<tr><td style="width: 50%">' . OptionRenderer::optionLabel( $opt, true ) .
            '</td><td>' . optionrenderer::optionInput( $opt ) . '</td></tr>';
}

?>

<div class="wrap">
    <form
        name="wp2static-ui-options"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <h2>Detection Options</h2>

    <h4>Control Detected URLs</h4>

    <p>VibeStatic will crawl these WordPress URLs to generate a static site.</p>

    <table class="striped widefat">
        <thead>
            <tr>
                <th style="width:50%;">URL Type</th>
                <th>Include in detection</th>
            </tr>
        </thead>
        <tbody>
            <?php echo $row( 'detectCustomPostTypes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <?php echo $row( 'detectPages' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <?php echo $row( 'detectPosts' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <?php echo $row( 'detectUploads' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
        </tbody>
    </table>

    <h2>Crawling Options</h2>

    <table class="widefat striped">
        <tbody>
            <?php echo $row( 'basicAuthUser' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <?php echo $row( 'basicAuthPassword' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <?php echo $row( 'useCrawlCaching' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
        </tbody>
    </table>

    <h2>Post-processing Options</h2>

    <table class="widefat striped">
        <tbody>
            <?php echo $row( 'deploymentURL' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
        </tbody>
    </table>

    <h2>Deployment Options</h2>

    <table class="widefat striped">
        <tbody>
            <?php echo $row( 'completionEmail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
            <tr>
                <td style="width:50%;">
                    <?php echo OptionRenderer::optionLabel( (array) $options['completionWebhook'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
                </td>
                <td>
                    <input
                        style="width:80%;"
                        type="url"
                        id="completionWebhook"
                        name="completionWebhook"
                        value="<?php echo esc_attr( $options['completionWebhook']->value !== '' ? $options['completionWebhook']->value : '' ); ?>"
                    />

                    <select
                        id="<?php echo esc_attr( $options['completionWebhookMethod']->name ); ?>"
                        name="<?php echo esc_attr( $options['completionWebhookMethod']->name ); ?>"
                        >
                        <option
                            value="POST"
                            <?php echo esc_attr( $options['completionWebhookMethod']->value === 'POST' ? 'selected' : '' ); ?>
                            >POST</option>
                        <option
                            value="GET"
                            <?php echo esc_attr( $options['completionWebhookMethod']->value === 'GET' ? 'selected' : '' ); ?>
                            >GET</option>
                    </select>
                </td>
            </tr>
        </tbody>
    </table>

    <br>

    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_ui_save_options" />

    <button class="button btn-primary" type="submit">Save options</button>

    </form>
</div>
