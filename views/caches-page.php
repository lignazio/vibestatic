<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var int $crawl_queue_total_urls */
$crawl_queue_total_urls = $view['crawlQueueTotalURLs'];

/** @var int $crawl_cache_total_urls */
$crawl_cache_total_urls = $view['crawlCacheTotalURLs'];

/** @var int $exported_site_file_count */
$exported_site_file_count = $view['exportedSiteFileCount'];

/** @var string $uploads_path */
$uploads_path = $view['uploads_path'];

/** @var int $processed_site_file_count */
$processed_site_file_count = $view['processedSiteFileCount'];

/** @var array<string, int> $deploy_cache_total_paths */
$deploy_cache_total_paths = $view['deployCacheTotalPaths'];

/** @var string $exported_site_disk_space */
$exported_site_disk_space = $view['exportedSiteDiskSpace'];

/** @var string $processed_site_disk_space */
$processed_site_disk_space = $view['processedSiteDiskSpace'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

$show_urls = __( 'Show URLs', 'vibestatic' );
$show_paths = __( 'Show Paths', 'vibestatic' );

?>

<style>
select.wp2static-select {
    width: 165px;
}
</style>

<div class="wrap">
    <p><i>
        <?php
        printf(
            /* translators: %s: a link whose text is "Refresh page". */
            esc_html__( '%s to see latest status', 'vibestatic' ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=wp2static-caches' ) ) . '">' .
                esc_html__( 'Refresh page', 'vibestatic' ) .
            '</a>'
        );
        ?>
    </i></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Cache Type', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Statistics', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php esc_html_e( 'Crawl Queue (Detected URLs)', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    printf(
                        esc_html(
                            /* translators: %s: number of URLs. */
                            _n( '%s URL in database', '%s URLs in database', $crawl_queue_total_urls, 'vibestatic' )
                        ),
                        esc_html( number_format_i18n( $crawl_queue_total_urls ) )
                    );
                    ?>
                </td>
                <td>
                    <?php
                    $form_name = 'wp2static-crawl-queue';
                    $form_actions = [
                        'wp2static_crawl_queue_show' => $show_urls,
                        'wp2static_crawl_queue_delete' => __( 'Delete Crawl Queue', 'vibestatic' ),
                    ];
                    require VIBESTATIC_PATH . 'views/partials/cache-actions.php';
                    ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Crawl Cache', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    printf(
                        esc_html(
                            /* translators: %s: number of URLs. */
                            _n( '%s URL in database', '%s URLs in database', $crawl_cache_total_urls, 'vibestatic' )
                        ),
                        esc_html( number_format_i18n( $crawl_cache_total_urls ) )
                    );
                    ?>
                </td>
                <td>
                    <?php
                    $form_name = 'wp2static-crawl-cache';
                    $form_actions = [
                        'wp2static_crawl_cache_show' => $show_urls,
                        'wp2static_crawl_cache_delete' => __( 'Delete Crawl Cache', 'vibestatic' ),
                    ];
                    require VIBESTATIC_PATH . 'views/partials/cache-actions.php';
                    ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Generated Static Site', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    printf(
                        esc_html(
                            /* translators: 1: number of files, 2: disk space used, e.g. "12 MB". */
                            _n(
                                '%1$s file, using %2$s',
                                '%1$s files, using %2$s',
                                $exported_site_file_count,
                                'vibestatic'
                            )
                        ),
                        esc_html( number_format_i18n( $exported_site_file_count ) ),
                        esc_html( $exported_site_disk_space )
                    );
                    ?>
                    <br>
                    <a href="<?php echo esc_url( 'file://' . $uploads_path . 'wp2static-exported-site' ); ?>"><?php esc_html_e( 'Path', 'vibestatic' ); ?></a>
                </td>
                <td>
                    <?php
                    $form_name = 'wp2static-static-site';
                    $form_actions = [
                        'wp2static_static_site_show' => $show_paths,
                        'wp2static_static_site_delete' => __( 'Delete Files', 'vibestatic' ),
                    ];
                    require VIBESTATIC_PATH . 'views/partials/cache-actions.php';
                    ?>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Post-processed Static Site', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    printf(
                        esc_html(
                            /* translators: 1: number of files, 2: disk space used, e.g. "12 MB". */
                            _n(
                                '%1$s file, using %2$s',
                                '%1$s files, using %2$s',
                                $processed_site_file_count,
                                'vibestatic'
                            )
                        ),
                        esc_html( number_format_i18n( $processed_site_file_count ) ),
                        esc_html( $processed_site_disk_space )
                    );
                    ?>
                    <br>
                    <a href="<?php echo esc_url( 'file://' . $uploads_path . 'wp2static-processed-site' ); ?>"><?php esc_html_e( 'Path', 'vibestatic' ); ?></a>
                </td>
                <td>
                    <?php
                    $form_name = 'wp2static-post-processed-site';
                    $form_actions = [
                        'wp2static_post_processed_site_show' => $show_paths,
                        'wp2static_post_processed_site_delete' => __( 'Delete Files', 'vibestatic' ),
                    ];
                    require VIBESTATIC_PATH . 'views/partials/cache-actions.php';
                    ?>
                </td>
            </tr>

            <?php
            $namespaces = array_keys( $deploy_cache_total_paths );
            $deploy_cache_rows = max( 1, count( $namespaces ) );
            $first = true;
            ?>
            <?php if ( ! $namespaces ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Deploy Cache', 'vibestatic' ); ?></td>
                    <td><?php esc_html_e( 'No paths in database', 'vibestatic' ); ?></td>
                    <td>&nbsp;</td>
                </tr>
            <?php endif; ?>
            <?php foreach ( $namespaces as $deploy_namespace ) : ?>
                <tr>
                    <?php if ( $first ) : ?>
                        <td rowspan="<?php echo esc_attr( (string) $deploy_cache_rows ); ?>"><?php esc_html_e( 'Deploy Cache', 'vibestatic' ); ?></td>
                        <?php $first = false; ?>
                    <?php endif; ?>
                    <td>
                        <?php
                        printf(
                            esc_html(
                                /* translators: 1: number of paths, 2: deploy namespace, e.g. the add-on name. */
                                _n(
                                    '%1$s path in database for %2$s',
                                    '%1$s paths in database for %2$s',
                                    (int) $deploy_cache_total_paths[ $deploy_namespace ],
                                    'vibestatic'
                                )
                            ),
                            esc_html( number_format_i18n( (int) $deploy_cache_total_paths[ $deploy_namespace ] ) ),
                            '<code>' . esc_html( $deploy_namespace ) . '</code>'
                        );
                        ?>
                    </td>
                    <td>
                        <?php
                        $form_name = 'wp2static-deploy-cache';
                        $form_actions = [
                            'wp2static_deploy_cache_show' => $show_paths,
                            'wp2static_deploy_cache_delete' => __( 'Delete Deploy Cache', 'vibestatic' ),
                        ];
                        $form_hidden = [ 'deploy_namespace' => $deploy_namespace ];
                        require VIBESTATIC_PATH . 'views/partials/cache-actions.php';
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>

    <form
        name="wp2static-delete-all-caches"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

        <?php wp_nonce_field( $nonce_action ); ?>

        <input name="action" type="hidden" value="wp2static_delete_all_caches" />

        <button class="button btn-danger"><?php esc_html_e( 'Delete all caches', 'vibestatic' ); ?></button>

    </form>
</div>
