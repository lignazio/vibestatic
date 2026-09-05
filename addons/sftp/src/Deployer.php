<?php
/**
 * Upload the generated site to a remote server over sFTP.
 *
 * Rewritten to send only what changed and to remove what has gone. It used to
 * walk the whole processed site on every deploy, ask `DeployCache::fileisCached()`
 * one file at a time, and never delete anything: a page removed from the site
 * stayed on the remote server for good. The core now says what changed — see
 * WP2Static\DeployPlan — in a single query.
 *
 * @package WP2StaticSFTP
 */

namespace WP2StaticSFTP;

use WP2Static\DeployCache;
use WP2Static\WsLog;
/*
 * Unprefixed, because that is how the add-on's own vendor/ ships it. phpseclib
 * is a common dependency and two plugins loading different majors of it in one
 * process is the same collision the core solved for Guzzle with Strauss; doing
 * the same here is a packaging change, recorded rather than smuggled in with a
 * behaviour fix. The pinned version is 2.0.27, from 2020.
 */
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;

class Deployer {

    /**
     * The namespace these files are recorded under in the core's DeployCache.
     *
     * Not `default`, which is what this add-on used before: the cache is shared
     * between deployers, and with two of them writing to `default` each would
     * see the other's uploads as its own and skip files it had never sent.
     */
    const DEFAULT_NAMESPACE = 'wp2static-addon-sftp';

    /**
     * @var SFTP|null Injectable so the upload logic can be exercised without a
     *                server on the other end.
     */
    private $connection = null;

    /**
     * @param SFTP|null $connection Null to open one from the saved options.
     */
    public function __construct( $connection = null ) {
        $this->connection = $connection;
    }

    /**
     * @param string $processed_site_path The processed site's directory.
     */
    public function upload_files( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        $connection = $this->connection ?: $this->connect();

        if ( ! $connection ) {
            return;
        }

        $plan = DeployCache::plan( self::DEFAULT_NAMESPACE );

        WsLog::l( $plan->summary() );

        /*
         * Only the trailing slash is trimmed. Trimming both ends turns an
         * absolute remote root into a relative one, and phpseclib then happily
         * creates the whole path under the SFTP user's home instead: the deploy
         * reports every file as uploaded, because it was — just not where the
         * user asked. An empty root means the home directory, which is what the
         * server gives us anyway.
         */
        $root = rtrim( (string) Controller::getValue( 'remote_root' ), '/' );
        $uploaded = 0;
        $failed = 0;

        foreach ( $plan->toDeploy() as $path ) {
            if ( $this->put( $connection, $processed_site_path . $path, $root . $path ) ) {
                DeployCache::addFile( $path, self::DEFAULT_NAMESPACE );

                $uploaded++;
                continue;
            }

            $failed++;

            /*
             * One line per failure, up to a point. A misconfigured server fails
             * for every file, and this add-on used to write one log row each:
             * on a site of eighteen hundred pages that is eighteen hundred rows
             * saying the same thing, which buries the deploy that actually ran.
             */
            if ( $failed <= 10 ) {
                WsLog::l( "sFTP put failed for $path" );
            }
        }

        if ( $failed > 10 ) {
            WsLog::l( sprintf( 'sFTP put failed for %d more file(s).', $failed - 10 ) );
        }

        $removed = $this->removeFiles( $connection, $plan->toDelete(), $root );

        // The cache is updated AFTER: if the deploy stops halfway, what was not
        // uploaded has to still be pending on the next run.
        DeployCache::rmPaths( $plan->toDelete(), self::DEFAULT_NAMESPACE );

        WsLog::l( "sFTP deployment complete: $uploaded uploaded, $removed removed, $failed failed." );
    }

    /**
     * Open the connection described by the saved options.
     *
     * @return SFTP|null Null when the options are incomplete or login fails.
     */
    private function connect() : ?SFTP {
        $host = (string) Controller::getValue( 'host' );

        if ( '' === $host ) {
            WsLog::l( 'No sFTP host set. See WP2Static > Addons > sFTP > Configure.' );

            return null;
        }

        /*
         * The port used to be read only when a password was set:
         * `Controller::getValue( 'password' ) ? (int) getValue( 'port' ) : 22`.
         * Whoever configured a non-standard port and authenticated any other
         * way silently got 22, and the deploy failed against a host that was
         * listening one line above in the same form.
         */
        $port = (int) Controller::getValue( 'port' );
        $connection = new SFTP( $host, $port > 0 ? $port : 22 );

        $username = (string) Controller::getValue( 'username' );

        if ( '' === $username ) {
            WsLog::l( 'No sFTP username set. See WP2Static > Addons > sFTP > Configure.' );

            return null;
        }

        $credential = $this->credential();

        if ( null === $credential ) {
            return null;
        }

        /*
         * The login result is checked whether or not a password is set. The
         * original only attempted a login when both username and password were
         * present, and otherwise carried on to upload over a connection that had
         * never authenticated — which is how a misconfigured add-on produced one
         * failure per file instead of one message saying it could not log in.
         */
        if ( ! $connection->login( $username, $credential ) ) {
            WsLog::l( 'Failed to log in to sFTP with the credentials provided.' );

            return null;
        }

        return $connection;
    }

    /**
     * The password, or the private key when one is configured.
     *
     * The settings page has offered "Private key path" and "Private key
     * passphrase" fields since the beginning, and nothing ever read them: the
     * code carried a `// TODO: use priv key w optional passphrase
     * authentication` and only ever logged in with a password. Two fields that
     * accept input and do nothing are worse than two fields that are not there.
     *
     * @return RSA|string|null Null when nothing usable is configured.
     */
    private function credential() {
        $key_path = (string) Controller::getValue( 'private_key' );

        if ( '' === $key_path ) {
            return \WP2Static\CoreOptions::encrypt_decrypt(
                'decrypt',
                Controller::getValue( 'password' )
            );
        }

        if ( ! is_readable( $key_path ) ) {
            WsLog::l( 'sFTP private key not readable: ' . $key_path );

            return null;
        }

        $key = new RSA();

        $passphrase = \WP2Static\CoreOptions::encrypt_decrypt(
            'decrypt',
            Controller::getValue( 'passphrase' )
        );

        if ( '' !== $passphrase ) {
            $key->setPassword( $passphrase );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.
        if ( ! $key->loadKey( (string) file_get_contents( $key_path ) ) ) {
            WsLog::l( 'sFTP private key could not be read: wrong format, or wrong passphrase.' );

            return null;
        }

        return $key;
    }

    /**
     * @param SFTP   $connection Open connection.
     * @param string $from       Absolute local path.
     * @param string $to         Path on the remote server, relative to its root.
     */
    private function put( SFTP $connection, string $from, string $to ) : bool {
        if ( ! is_readable( $from ) ) {
            return false;
        }

        $directory = dirname( $to );

        if ( '.' !== $directory && '/' !== $directory ) {
            // mkdir() with the recursive flag, instead of walking the path one
            // segment at a time with a chdir() per segment per file.
            $connection->mkdir( $directory, -1, true );
        }

        return (bool) $connection->put( $to, $from, SFTP::SOURCE_LOCAL_FILE );
    }

    /**
     * @param SFTP     $connection Open connection.
     * @param string[] $paths      Paths that have left the site.
     * @param string   $root       Remote root.
     * @return int How many were removed.
     */
    private function removeFiles( SFTP $connection, array $paths, string $root ) : int {
        $removed = 0;
        $emptied = [];

        foreach ( $paths as $path ) {
            if ( $connection->delete( $root . $path, false ) ) {
                $removed++;
                $emptied[ dirname( $root . $path ) ] = true;
            }
        }

        /*
         * A directory left empty is a visible leftover: on a server with
         * directory listings enabled it becomes an indexable empty page, which
         * is the same dead-URL problem the deploy plan exists to avoid.
         *
         * Deepest first, walking up for as long as rmdir accepts: a directory
         * can be left empty because the only subdirectory it held was emptied,
         * and in that case its own name never came through here. rmdir fails on
         * its own for a non-empty directory, so there is no need to check.
         */
        krsort( $emptied );

        $stop = '' === $root ? '.' : $root;

        foreach ( array_keys( $emptied ) as $directory ) {
            while ( $directory !== $stop && strlen( $directory ) > strlen( $stop )
                && $connection->rmdir( $directory ) ) {
                $directory = dirname( $directory );
            }
        }

        return $removed;
    }
}
