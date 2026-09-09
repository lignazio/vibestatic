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

    /*
     * Adjusts the max_execution_time ini option
     *
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
