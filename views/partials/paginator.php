<?php
/**
 * Navigation between the pages of a list.
 *
 * This already existed as an identical block in all five paginated views,
 * between the "start/end Paginator template partial" comments the original
 * authors left without ever extracting it. Copied five times, it had already
 * drifted: the screen-reader heading said "Crawl Queue" on the Deploy Cache
 * page too. With strings to translate, the cost of keeping them in step becomes
 * five times the cost of writing them once, and two copies that drift after
 * translation give two near-identical msgids to translate twice.
 *
 * @package WP2Static
 *
 * @var int    $paginator_page          Current page.
 * @var int    $paginator_first_page    First page.
 * @var int    $paginator_last_page     Last page.
 * @var int    $paginator_total_records Total records.
 * @var string $paginator_label         Name of the list, for screen readers.
 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var int $paginator_page */
/** @var int $paginator_first_page */
/** @var int $paginator_last_page */
/** @var int $paginator_total_records */
/** @var string $paginator_label */

?>
<h2 class="screen-reader-text">
    <?php
    printf(
        /* translators: %s: name of the list being paginated, e.g. "Crawl Queue". */
        esc_html__( '%s list navigation', 'vibestatic' ),
        esc_html( $paginator_label )
    );
    ?>
</h2>
<div class="tablenav-pages">
    <span class="displaying-num">
        <?php
        printf(
            esc_html(
                /* translators: %s: number of items in the list, already formatted. */
                _n( '%s item', '%s items', $paginator_total_records, 'vibestatic' )
            ),
            esc_html( number_format_i18n( $paginator_total_records ) )
        );
        ?>
    </span>
    <span class="pagination-links">
        <?php if ( $paginator_page === $paginator_first_page ) : ?>
            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
        <?php else : ?>
            <a class="first-page button" href="<?php echo esc_url( URLHelper::modifyUrl( [ 'paged' => 1 ] ) ); ?>">
                <span class="screen-reader-text"><?php esc_html_e( 'First page', 'vibestatic' ); ?></span>
                <span aria-hidden="true">&laquo;</span>
            </a>
            <a class="prev-page button" href="<?php echo esc_url( URLHelper::modifyUrl( [ 'paged' => $paginator_page - 1 ] ) ); ?>">
                <span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'vibestatic' ); ?></span>
                <span aria-hidden="true">&lsaquo;</span>
            </a>
        <?php endif; ?>
        <span class="paging-input">
            <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'vibestatic' ); ?></label>
            <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr( (string) $paginator_page ); ?>" size="3" aria-describedby="table-paging">
            <span class="tablenav-paging-text">
                <?php
                printf(
                    /* translators: %s: total number of pages. */
                    esc_html__( 'of %s', 'vibestatic' ),
                    '<span class="total-pages">' . esc_html( number_format_i18n( $paginator_last_page ) ) . '</span>'
                );
                ?>
            </span>
        </span>
        <?php if ( $paginator_page === $paginator_last_page ) : ?>
            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
        <?php else : ?>
            <a class="next-page button" href="<?php echo esc_url( URLHelper::modifyUrl( [ 'paged' => $paginator_page + 1 ] ) ); ?>">
                <span class="screen-reader-text"><?php esc_html_e( 'Next page', 'vibestatic' ); ?></span>
                <span aria-hidden="true">&rsaquo;</span>
            </a>
            <a class="last-page button" href="<?php echo esc_url( URLHelper::modifyUrl( [ 'paged' => $paginator_last_page ] ) ); ?>">
                <span class="screen-reader-text"><?php esc_html_e( 'Last page', 'vibestatic' ); ?></span>
                <span aria-hidden="true">&raquo;</span>
            </a>
        <?php endif; ?>
    </span>
</div>
