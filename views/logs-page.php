<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var list<object{time: string, log: string}> $logs */
$logs = $view['logs'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];
?>

<div class="wrap">
    <br>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'When', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'What', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! $logs ) : ?>
                <tr>
                    <td colspan="2"><?php esc_html_e( 'Logs are empty.', 'vibestatic' ); ?></td>
                </tr>
            <?php endif; ?>

            <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log->time ); ?></td>
                    <td><?php echo esc_html( $log->log ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>

    <?php if ( $logs ) : ?>
        <form
            name="wp2static-log-delete"
            method="POST"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

        <?php wp_nonce_field( $nonce_action ); ?>
        <input name="action" type="hidden" value="wp2static_log_delete" />

        <button class="wp2static-button button btn-danger"><?php esc_html_e( 'Delete Log', 'vibestatic' ); ?></button>

        </form>
    <?php endif; ?>
</div>
