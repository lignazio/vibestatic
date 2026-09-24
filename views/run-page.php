<?php
/**
 * @package WP2Static
 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * No script here. What this page runs lives in assets/run-page.js, enqueued
 * by Controller::enqueueAdminAssets() together with the nonce and the strings
 * it displays.
 */
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
    <button class="button button-primary" id="wp2static-run"><?php esc_html_e( 'Generate static site', 'vibestatic' ); ?></button>

    <div id="wp2static-spinner" class="spinner" style="padding:2px;float:none;"></div>

    <br>
    <br>

    <button class="button" id="wp2static-poll-logs"><?php esc_html_e( 'Refresh logs', 'vibestatic' ); ?></button>
    <br>
    <br>
    <textarea id="wp2static-run-log" rows="30" style="width:99%;"><?php
        echo esc_textarea(
            __(
                'Logs will appear here on completion, or click "Refresh logs" to check progress.',
                'vibestatic'
            )
        );
    ?></textarea>
</div>
