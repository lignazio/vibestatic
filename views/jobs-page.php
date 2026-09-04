<?php
// phpcs:disable Generic.Files.LineLength.MaxExceeded                              
// phpcs:disable Generic.Files.LineLength.TooLong                                  

/**
 * @var mixed[] $view
 */

use WP2Static\OptionRenderer;

/**
 * @var mixed[] $jobs
 */
$jobs = $view['jobs'];

/**
 * @var array<string, mixed> $options
 */
$options = $view['jobOptions'];

$input = function ( $name ) use ( $options ) {
    return OptionRenderer::optionInput( (array) $options[ $name ] );
};

$label = function ( $name, $description = false ) use ( $options ) {
    return OptionRenderer::optionLabel( (array) $options[ $name ], $description );
};

$row = function ( $name ) use ( $options ) {
    $opt = (array) $options[ $name ];
    return '<tr><td style="width: 50%">' . OptionRenderer::optionLabel( $opt, true ) .
            '</td><td>' . optionrenderer::optionInput( $opt ) . '</td></tr>';
}

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
                <td style="width:33%;">
                    Events to queue new jobs
                </td>
                <td>
                    &nbsp;
                </td>
                <td>
                    Enabled?
                </td>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="width:33%;">
                    <?php echo esc_html( $label( 'queueJobOnPostSave' ) ); ?>
                </td>
                <td>
                    <?php echo esc_html( $options['queueJobOnPostSave']->description ); ?>
                </td>
                <td>
                    <?php echo esc_html( $input( 'queueJobOnPostSave' ) ); ?>
                </td>
            </tr>
            <tr>
                <td style="width:33%;">
                    <?php echo esc_html( $label( 'queueJobOnPostDelete' ) ); ?>
                </td>
                <td>
                    <?php echo esc_html( $options['queueJobOnPostDelete']->description ); ?>
                </td>
                <td>
                    <?php echo esc_html( $input( 'queueJobOnPostDelete' ) ); ?>
                </td>
            </tr>
        </tbody>
    </table>


    <h4>Jobs that will be added to queue</h4>

    <table class="widefat striped">
        <thead>
            <tr>
                <td style="text-align:center;">
                    <?php echo esc_html( $label( 'autoJobQueueDetection' ) ); ?>
                </td>
                <td style="text-align:center;">
                    <?php echo esc_html( $label( 'autoJobQueueCrawling' ) ); ?>
                </td>
                <td style="text-align:center;">
                    <?php echo esc_html( $label( 'autoJobQueuePostProcessing' ) ); ?>
                </td>
                <td style="text-align:center;">
                    <?php echo esc_html( $label( 'autoJobQueueDeployment' ) ); ?>
                </td>
            </tr>
        </thead>
        <tbody>
            <tr style="text-align:center;">
                <td><?php echo esc_html( $input( 'autoJobQueueDetection' ) ); ?></td>
                <td><?php echo esc_html( $input( 'autoJobQueueCrawling' ) ); ?></td>
                <td><?php echo esc_html( $input( 'autoJobQueuePostProcessing' ) ); ?></td>
                <td><?php echo esc_html( $input( 'autoJobQueueDeployment' ) ); ?></td>
            </tr>
        </tbody>
    </table>

    <p/>

    <table class="widefat striped">
        <tbody>
            <tr>
                <td style="width: 50%">
                    <?php echo esc_html( $label( 'processQueueInterval', true ) ); ?>
                    <p><i>If WP-Cron is not expected to be triggered by site visitors, you can also call `wp-cron.php` directly, run the WP-CLI command `wp wp2static process_queue` or call the hook `wp2staticProcessQueue` from within your own theme or plugin.</i></p>
                </td>
                <td>
                    <select
                        id="<?php echo esc_attr( $options['processQueueInterval']->name ); ?>"
                        name="<?php echo esc_attr( $options['processQueueInterval']->name ); ?>"
                        value="<?php echo esc_attr( (int) $options['processQueueInterval']->value ); ?>"
                    >
                    <option
                        <?php echo esc_html( (int) $options['processQueueInterval']->value === 0 ? 'selected' : '' ); ?>
                        value="0">disable (never)</option>
                    <option
                        <?php echo esc_html( (int) $options['processQueueInterval']->value === 1 ? 'selected' : '' ); ?>
                        value="1">every minute</option>
                    <option
                        <?php echo esc_html( (int) $options['processQueueInterval']->value === 5 ? 'selected' : '' ); ?>
                        value="5">every 5 minutes</option>
                    <option
                        <?php echo esc_html( (int) $options['processQueueInterval']->value === 10 ? 'selected' : '' ); ?>
                        value="10">every 10 minutes</option>
                    </select>
                </td>
            </tr>
            <?php echo esc_html( $row( 'processQueueImmediately' ) ); ?>
        </tbody>
    </table>

    <p/>

    <button class="button btn-primary">Save Job Automation Settings</button>
    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_ui_save_job_options" />
    </form>

    <p/>

    <form
        name="wp2static-manually-enqueue-jobs"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

        <?php wp_nonce_field( 'wp2static-manually-enqueue-jobs' ); ?>
        <input name="action" type="hidden" value="wp2static_manually_enqueue_jobs" />

        <button class="button">Manually Enqueue Jobs Now</button>
    </form>

    <hr>

    <h3>Job Queue/History</h3>

    <p><i><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp2static-jobs' ) ); ?>">Refresh page</a> to see latest status</i><p>

    <hr>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Date</th>
                <th>Job</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
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

    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_delete_jobs_queue" />

    <button class="wp2static-button button btn-danger">Delete all Jobs from Queue</button>

    </form>

    <!-- TODO: consider manual queue processing, needs further testing, unstable execution so far

    <br>

    <form
        name="wp2static-process-jobs-queue"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

    <?php wp_nonce_field( strval( $view['nonce_action'] ) ); ?>
    <input name="action" type="hidden" value="wp2static_process_jobs_queue" />

    <button class="wp2static-button button btn-danger">Manually process Job Queue</button>

    </form>

-->
</div>
