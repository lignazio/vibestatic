<?php

namespace WP2Static;

class Utils {

    /**
     * Run a statement that has already been prepared.
     *
     * `wpdb::prepare()` answers null when the placeholders and the arguments do
     * not line up, and `wpdb::query( null )` is a TypeError on PHP 8: passing
     * the one straight into the other turns a mistake in a query into a fatal
     * error in the admin.
     *
     * It is logged rather than cast away. The repositories in this plugin write
     * `(string) $this->db->prepare( ... )`, which does avoid the TypeError —
     * and then runs an empty query, which wpdb answers false to, silently. A
     * null out of prepare() is not a runtime condition: it means the SQL and
     * its arguments disagree, which is a bug, and a bug that says nothing is
     * the expensive kind.
     *
     * Answers what `wpdb::query()` answers — the affected row count, or false
     * — and false for a statement that could not be prepared, because that is
     * the value callers already read as failure. Not a bool: `ensureIndex()`
     * below asks SHOW INDEX how many rows came back, and `0` is the answer that
     * means "create it".
     *
     * @param string|null $prepared The return of `$wpdb->prepare()`.
     * @return int|bool Rows affected, or false.
     */
    public static function runPrepared( ?string $prepared ) {
        if ( null === $prepared ) {
            WsLog::l( 'A query was not prepared: its placeholders and arguments do not match.' );

            return false;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every caller passes the return of $wpdb->prepare() with a literal template, which is what the sniff checks there; it cannot follow a prepared statement through a parameter.
        return $wpdb->query( $prepared );
    }

    /**
     * A field of the current POST request, as one line of text.
     *
     * Read from `$_POST` rather than through `filter_input()`: the latter with
     * no filter named applies FILTER_DEFAULT, which sanitises nothing, and the
     * three readers below are the only place in the plugin a posted value is
     * turned into a string, a URL or a number. WordPress has already slashed
     * the superglobal by the time a plugin sees it, hence `wp_unslash()`
     * before anything else.
     *
     * The empty string when the field was not sent, or was sent as an array.
     * Every caller sits behind Controller::authorize(), which has verified the
     * nonce and the capability before this is asked anything.
     */
    public static function postedText( string $key ) : string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Controller::authorize() in every caller; this only reads.
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
        return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * A field of the current POST request, as a URL fit for storing.
     *
     * `esc_url_raw()` rather than `esc_url()`: the value goes into the
     * database, not into an attribute, and the entities the latter adds would
     * be stored as data.
     */
    public static function postedUrl( string $key ) : string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Controller::authorize() in every caller; this only reads.
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
        return esc_url_raw( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * A field of the current POST request, as a non-negative integer.
     *
     * Zero when absent: the callers are numeric options that treat 0 as
     * "default", which is also what an empty field means.
     */
    public static function postedInt( string $key ) : int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Controller::authorize() in every caller; this only reads.
        if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
        return absint( wp_unslash( (string) $_POST[ $key ] ) );
    }

    /**
     * Lift the execution time limit for the request doing an export.
     *
     * Called from the two places a run starts — the job queue and the headless
     * workflow — and from nowhere else. It used to be called from
     * Controller::init(), which is every request WordPress serves, admin or
     * front end: a limit the host had chosen, quietly removed for everything
     * on the site. A crawl of eighteen hundred pages does need more than
     * thirty seconds; the page that lists them does not.
     *
     * The first call is a probe. `set_time_limit()` answers true even when a
     * host forbids the change, so the value is written and then read back:
     * only where it actually changed is the limit then removed.
     */
    public static function set_max_execution_time() : void {
        if (
            ! function_exists( 'set_time_limit' ) ||
            ! function_exists( 'ini_get' )
        ) {
            return;
        }

        $current_max_execution_time  = ini_get( 'max_execution_time' );
        $proposed_max_execution_time =
            ( $current_max_execution_time == 30 ) ? 31 : 30;
        set_time_limit( $proposed_max_execution_time );
        $current_max_execution_time = ini_get( 'max_execution_time' );

        if ( $proposed_max_execution_time == $current_max_execution_time ) {
            set_time_limit( 0 );
        }
    }
}
