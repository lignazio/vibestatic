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

/** @var array<int, object{url: string, page_hash: string}> $paginator_records */
$paginator_records = $view['paginatorRecords'];

/** @var string $nonce_action */
$nonce_action = $view['nonce_action'];

/** @var string $paginator_label */
$paginator_label = __( 'Crawl Cache', 'vibestatic' );

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
    <form id="posts-filter" method="POST">
        <?php wp_nonce_field( $nonce_action ); ?>
        <input type="hidden" name="page" value="<?php echo esc_attr( $paginator_index ); ?>" />
        <input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paginator_page ); ?>" />

        <p class="search-box">
            <label class="screen-reader-text" for="post-search-input"><?php esc_html_e( 'Search Crawl Cache URLs:', 'vibestatic' ); ?></label>
            <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr( $search_term ); ?>">
            <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search URLs', 'vibestatic' ); ?>">
        </p>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'vibestatic' ); ?></label>
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php esc_html_e( 'Bulk Actions', 'vibestatic' ); ?></option>
                    <option value="remove"><?php esc_html_e( 'Remove', 'vibestatic' ); ?></option>
                </select>
                <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'vibestatic' ); ?>">
            </div>

            <?php require VIBESTATIC_PATH . 'views/partials/paginator.php'; ?>

            <br class="clear">
        </div>

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'vibestatic' ); ?></label>
                        <input id="cb-select-all-1" type="checkbox">
                    </td>
                    <th><?php esc_html_e( 'URLs in Crawl Cache', 'vibestatic' ); ?></th>
                    <th><?php esc_html_e( 'Page MD5 Hash', 'vibestatic' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! $paginator_total_records ) : ?>
                    <tr>
                        <td colspan="3"><?php esc_html_e( 'Crawl cache is empty.', 'vibestatic' ); ?></td>
                    </tr>
                <?php endif; ?>

                <?php foreach ( $paginator_records as $paginator_id => $record ) : ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <label class="screen-reader-text" for="cb-select-<?php echo esc_attr( (string) $paginator_id ); ?>">
                                <?php
                                printf(
                                    /* translators: %s: a URL from the list. */
                                    esc_html__( 'Select %s', 'vibestatic' ),
                                    esc_html( $record->url )
                                );
                                ?>
                            </label>
                            <input id="cb-select-<?php echo esc_attr( (string) $paginator_id ); ?>" type="checkbox" name="id[]" value="<?php echo esc_attr( (string) $paginator_id ); ?>">
                            <div class="locked-indicator">
                                <span class="locked-indicator-icon" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php echo esc_html( $record->url ); ?></span>
                            </div>
                        </th>
                        <td><?php echo esc_html( $record->url ); ?></td>
                        <td><?php echo esc_html( $record->page_hash ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>
</div>
