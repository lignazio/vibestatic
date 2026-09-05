<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/** @var string $paginator_index */
$paginator_index = (string) ( filter_input( INPUT_GET, 'page' ) ?? '' );

/** @var int $paginator_page */
$paginator_page = $view['paginatorPage'];

/** @var string $search_term */
$search_term = (string) ( filter_input( INPUT_GET, 's', FILTER_SANITIZE_URL ) ?? '' );

/** @var int $paginator_total_records */
$paginator_total_records = $view['paginatorTotalRecords'];

/** @var int $paginator_first_page */
$paginator_first_page = $view['paginatorFirstPage'];

/** @var int $paginator_last_page */
$paginator_last_page = $view['paginatorLastPage'];

/** @var array<int, string> $paginator_records */
$paginator_records = $view['paginatorRecords'];

/** @var string $paginator_label */
$paginator_label = __( 'Post-processed Static Site', 'vibestatic' );

?>

<div class="wrap">
    <br>

    <form id="posts-filter" method="GET">
        <input type="hidden" name="page" value="<?php echo esc_attr( $paginator_index ); ?>" />
        <input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paginator_page ); ?>" />

        <p class="search-box">
            <label class="screen-reader-text" for="post-search-input"><?php esc_html_e( 'Search post-processed site paths:', 'vibestatic' ); ?></label>
            <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr( $search_term ); ?>">
            <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search paths', 'vibestatic' ); ?>">
        </p>

        <div class="tablenav top">
            <?php require VIBESTATIC_PATH . 'views/partials/paginator.php'; ?>

            <br class="clear">
        </div>

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Paths in Post-processed Static Site', 'vibestatic' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! $paginator_total_records ) : ?>
                    <tr>
                        <td><?php esc_html_e( 'Post-processed site directory is empty.', 'vibestatic' ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ( $paginator_records as $paginator_path ) : ?>
                    <tr>
                        <td><?php echo esc_html( $paginator_path ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>
</div>
