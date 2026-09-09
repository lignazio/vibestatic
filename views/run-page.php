<?php
/**
 * @package WP2Static
 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$run_nonce = wp_create_nonce( 'wp2static-run-page' );

/*
 * The strings the JS displays go through wp_json_encode(): it produces an
 * already-quoted JavaScript literal with inner quotes escaped, so a translation
 * containing an apostrophe — in Italian, in French, in most languages — does
 * not break the script.
 */
$run_strings = [
    'httpError' => sprintf(
        /* translators: %s: HTTP status code returned by the server. */
        __( '%s error code returned from server.', 'vibestatic' ),
        '{status}'
    ),
    'httpErrorAdvice' => __(
        "Please check your server's error logs, or try increasing the max_execution_time limit in PHP if this consistently fails after the same duration. More information about the error may be logged in your browser's console.",
        'vibestatic'
    ),
];
?>

<script type="text/javascript">
jQuery(document).ready(function($){
    var strings = <?php echo wp_json_encode( $run_strings ); ?>;

    var run_data = {
        action: 'wp2static_run',
        security: <?php echo wp_json_encode( $run_nonce ); ?>,
    };

    var log_data = {
        action: 'wp2static_poll_log',
        startRow: 0,
        security: <?php echo wp_json_encode( $run_nonce ); ?>,
    };

    function responseErrorHandler( jqXHR, textStatus, errorThrown ) {
        $("#wp2static-spinner").removeClass("is-active");
        $("#wp2static-run" ).prop('disabled', false);

        console.log(errorThrown);
        console.log(jqXHR.responseText);

        alert(
            strings.httpError.replace('{status}', jqXHR.status) +
            "\n" + strings.httpErrorAdvice
        );
    }

    function pollLogs() {
        $.post(ajaxurl, log_data, function(response) {
            var log = response && response.data ? response.data.log : '';
            $('#wp2static-run-log').val(log);
            $("#wp2static-poll-logs" ).prop('disabled', false);
        });
    }

    $( "#wp2static-run" ).click(function() {
        $("#wp2static-spinner").addClass("is-active");
        $("#wp2static-run" ).prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: run_data,
            timeout: 0,
            success: function() {
                $("#wp2static-spinner").removeClass("is-active");
                $("#wp2static-run" ).prop('disabled', false);
                pollLogs();
            },
            error: responseErrorHandler
        });

    });

    $( "#wp2static-poll-logs" ).click(function() {
        $("#wp2static-poll-logs" ).prop('disabled', true);
        pollLogs();
    });
});
</script>

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
