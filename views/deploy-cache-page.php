<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

/*
 * The slug of this very page, for the form to come back to. It used to be read
 * back out of the request, which is a roundabout way of learning a constant.
 *
 * @var string $paginator_index
 */
$paginator_index = 'wp2static-deploy-cache';

/** @var int $paginator_page */
$paginator_page = $view['paginatorPage'];

/** @var string $search_term */
$search_term = $view['searchTerm'];

/** @var string $deploy_namespace */
$deploy_namespace = $view['deployNamespace'];

/** @var int $paginator_total_records */
$paginator_total_records = $view['paginatorTotalRecords'];

/** @var int $paginator_first_page */
$paginator_first_page = $view['paginatorFirstPage'];

/** @var int $paginator_last_page */
$paginator_last_page = $view['paginatorLastPage'];

/** @var array<int, string> $paginator_records */
$paginator_records = $view['paginatorRecords'];

/** @var string $paginator_label */
$paginator_label = __( 'Deploy Cache', 'vibestatic' );

?>

<div class="wrap">
    <?php
    /*
     * The page's own title, asked of WordPress rather than written again here.
     *
     * An admin page is expected to carry exactly one h1 inside `.wrap`: it is
     * what a screen reader announces on arrival, and what WordPress hangs
     * `.wp-header-end` off when it decides where to put admin notices. Every
     * view in this plugin but two opened with a `<br>` instead.
     *
     * get_admin_page_title() returns what add_submenu_page() was given, so the
     * heading cannot drift from the menu entry — and for the pages that have no
     * menu entry, Controller::setHiddenPageTitle() has already filled it in.
     */
    ?>
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <form id="posts-filter" method="GET">
        <input type="hidden" name="page" value="<?php echo esc_attr( $paginator_index ); ?>" />
        <?php if ( '' !== $deploy_namespace ) : ?>
            <input type="hidden" name="deploy_namespace" value="<?php echo esc_attr( $deploy_namespace ); ?>" />
        <?php endif; ?>
        <input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paginator_page ); ?>" />

        <p class="search-box">
            <label class="screen-reader-text" for="post-search-input"><?php esc_html_e( 'Search Deploy Cache paths:', 'vibestatic' ); ?></label>
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
                    <th><?php esc_html_e( 'Paths in Deploy Cache', 'vibestatic' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! $paginator_total_records ) : ?>
                    <tr>
                        <td><?php esc_html_e( 'Deploy Cache is empty.', 'vibestatic' ); ?></td>
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
