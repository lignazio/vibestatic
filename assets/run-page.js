/**
 * The Run page: one button that starts the full workflow over AJAX, one that
 * refreshes the log while it runs.
 *
 * Enqueued by Controller::enqueueAdminAssets() on that page only. What the
 * PHP side knows and this file does not — the nonce and the translated
 * strings — arrives as `vibestaticRun`, defined by wp_add_inline_script()
 * ahead of this file.
 */
jQuery( document ).ready( function ( $ ) {
    var settings = window.vibestaticRun || { nonce: '', strings: {} };
    var strings = settings.strings;

    var run_data = {
        action: 'wp2static_run',
        security: settings.nonce
    };

    var log_data = {
        action: 'wp2static_poll_log',
        startRow: 0,
        security: settings.nonce
    };

    function responseErrorHandler( jqXHR, textStatus, errorThrown ) {
        $( '#wp2static-spinner' ).removeClass( 'is-active' );
        $( '#wp2static-run' ).prop( 'disabled', false );

        console.log( errorThrown );
        console.log( jqXHR.responseText );

        alert(
            strings.httpError.replace( '{status}', jqXHR.status ) +
            '\n' + strings.httpErrorAdvice
        );
    }

    function pollLogs() {
        $.post( ajaxurl, log_data, function ( response ) {
            var log = response && response.data ? response.data.log : '';
            $( '#wp2static-run-log' ).val( log );
            $( '#wp2static-poll-logs' ).prop( 'disabled', false );
        } );
    }

    $( '#wp2static-run' ).click( function () {
        $( '#wp2static-spinner' ).addClass( 'is-active' );
        $( '#wp2static-run' ).prop( 'disabled', true );

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            data: run_data,
            timeout: 0,
            success: function () {
                $( '#wp2static-spinner' ).removeClass( 'is-active' );
                $( '#wp2static-run' ).prop( 'disabled', false );
                pollLogs();
            },
            error: responseErrorHandler
        } );
    } );

    $( '#wp2static-poll-logs' ).click( function () {
        $( '#wp2static-poll-logs' ).prop( 'disabled', true );
        pollLogs();
    } );
} );
