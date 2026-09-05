<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var list<object{created_at: string, job_type: string, status: string}> $jobs */
$jobs = $view['jobs'];

/** @var array<string, object{name: string, value: string, label: string, description: string, type: string}> $options */
$options = $view['jobOptions'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/**
 * @param string $name Nome dell'opzione.
 * @return array<string, ?string>
 */
$spec = function ( string $name ) use ( $options ) : array {
    /** @var array<string, ?string> $opt */
    $opt = (array) $options[ $name ];

    return $opt;
};

$input = function ( string $name ) use ( $spec ) : string {
    return OptionRenderer::optionInput( $spec( $name ) );
};

$label = function ( string $name, bool $description = false ) use ( $spec ) : string {
    return OptionRenderer::optionLabel( $spec( $name ), $description );
};

$row = function ( string $name ) use ( $spec ) : string {
    return '<tr><td style="width: 50%">' . OptionRenderer::optionLabel( $spec( $name ), true ) .
            '</td><td>' . OptionRenderer::optionInput( $spec( $name ) ) . '</td></tr>';
};

$intervals = [
    0 => __( 'disable (never)', 'vibestatic' ),
    1 => __( 'every minute', 'vibestatic' ),
    5 => __( 'every 5 minutes', 'vibestatic' ),
    10 => __( 'every 10 minutes', 'vibestatic' ),
];

?>

<div class="wrap">
    <form
        name="wp2static-job-options"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <br>

    <table class="widefat striped">
        <thead>
            <tr>
                <td style="width:33%;"><?php esc_html_e( 'Events to queue new jobs', 'vibestatic' ); ?></td>
                <td>&nbsp;</td>
                <td><?php esc_html_e( 'Enabled?', 'vibestatic' ); ?></td>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( [ 'queueJobOnPostSave', 'queueJobOnPostDelete' ] as $job_event ) : ?>
                <tr>
                    <td style="width:33%;">
                        <?php echo $label( $job_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
                    </td>
                    <td>
                        <?php echo esc_html( $options[ $job_event ]->description ); ?>
                    </td>
                    <td>
                        <?php echo $input( $job_event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>


    <h4><?php esc_html_e( 'Jobs that will be added to queue', 'vibestatic' ); ?></h4>

    <?php
    $auto_jobs = [
        'autoJobQueueDetection',
        'autoJobQueueCrawling',
        'autoJobQueuePostProcessing',
        'autoJobQueueDeployment',
    ];
    ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <?php foreach ( $auto_jobs as $auto_job ) : ?>
                    <td style="text-align:center;">
                        <?php echo $label( $auto_job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr style="text-align:center;">
                <?php foreach ( $auto_jobs as $auto_job ) : ?>
                    <td><?php echo $input( $auto_job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?></td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>

    <p></p>

    <table class="widefat striped">
        <tbody>
            <tr>
                <td style="width: 50%">
                    <?php echo $label( 'processQueueInterval', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
                    <p><i>
                        <?php
                        printf(
                            /* translators: 1: wp-cron.php, 2: a WP-CLI command, 3: a WordPress action hook. All three are literals and must not be translated. */
                            esc_html__(
                                'If WP-Cron is not expected to be triggered by site visitors, you can also call %1$s directly, run the WP-CLI command %2$s or call the hook %3$s from within your own theme or plugin.',
                                'vibestatic'
                            ),
                            '<code>wp-cron.php</code>',
                            '<code>wp vibestatic process_queue</code>',
                            '<code>wp2staticProcessQueue</code>'
                        );
                        ?>
                    </i></p>
                </td>
                <td>
                    <select
                        id="<?php echo esc_attr( $options['processQueueInterval']->name ); ?>"
                        name="<?php echo esc_attr( $options['processQueueInterval']->name ); ?>"
                    >
                        <?php foreach ( $intervals as $minutes => $interval_label ) : ?>
                            <option
                                value="<?php echo esc_attr( (string) $minutes ); ?>"
                                <?php selected( (int) $options['processQueueInterval']->value, $minutes ); ?>
                            ><?php echo esc_html( $interval_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php echo $row( 'processQueueImmediately' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup gia' escapato da OptionRenderer. ?>
        </tbody>
    </table>

    <p></p>

    <button class="button btn-primary"><?php esc_html_e( 'Save Job Automation Settings', 'vibestatic' ); ?></button>
    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_ui_save_job_options" />
    </form>

    <p></p>

    <form
        name="wp2static-manually-enqueue-jobs"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

        <?php wp_nonce_field( 'wp2static-manually-enqueue-jobs' ); ?>
        <input name="action" type="hidden" value="wp2static_manually_enqueue_jobs" />

        <button class="button"><?php esc_html_e( 'Manually Enqueue Jobs Now', 'vibestatic' ); ?></button>
    </form>

    <hr>

    <h3><?php esc_html_e( 'Job Queue/History', 'vibestatic' ); ?></h3>

    <p><i>
        <?php
        printf(
            /* translators: %s: a link whose text is "Refresh page". */
            esc_html__( '%s to see latest status', 'vibestatic' ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=wp2static-jobs' ) ) . '">' .
                esc_html__( 'Refresh page', 'vibestatic' ) .
            '</a>'
        );
        ?>
    </i></p>

    <hr>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Job', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Status', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $jobs ) : ?>
                <tr>
                    <td colspan="3"><?php esc_html_e( 'No jobs yet.', 'vibestatic' ); ?></td>
                </tr>
            <?php endif; ?>
            <?php foreach ( $jobs as $job ) : ?>
            <tr>
                <td><?php echo esc_html( $job->created_at ); ?></td>
                <td><?php echo esc_html( $job->job_type ); ?></td>
                <td><?php echo esc_html( $job->status ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>

    <form
        name="wp2static-delete-jobs-queue"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( $nonce_action ); ?>
    <input name="action" type="hidden" value="wp2static_delete_jobs_queue" />

    <button class="wp2static-button button btn-danger"><?php esc_html_e( 'Delete all Jobs from Queue', 'vibestatic' ); ?></button>

    </form>
</div>
