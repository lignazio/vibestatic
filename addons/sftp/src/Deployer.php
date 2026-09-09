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
 * The prefixed copy, from the core's own vendor-prefixed/, not an unprefixed
 * one under the module. phpseclib is a common dependency — plenty of backup
 * plugins ship it — and WordPress loads every plugin in one process, so two
 * different majors of it destroy each other: the first loaded wins and the
 * other gets a class that is not the one it expects. It is the same collision
 * the core solved for Guzzle, solved the same way.
 */
use WP2Static\Vendor\phpseclib3\Crypt\Common\PrivateKey;
use WP2Static\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use WP2Static\Vendor\phpseclib3\Exception\NoKeyLoadedException;
use WP2Static\Vendor\phpseclib3\Net\SFTP;

class Deployer extends \WP2Static\PlanDrivenDeployer {

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

    protected function deployCacheNamespace() : string {
        return self::DEFAULT_NAMESPACE;
    }

    protected function label() : string {
        return 'sFTP deployment';
    }

    /**
     * Only the trailing slash is trimmed.
     *
     * Trimming both ends turns an absolute remote root into a relative one, and
     * phpseclib then happily creates the whole path under the sFTP user's home
     * instead: the deploy reports every file as uploaded, because it was — just
     * not where the user asked. Eighty-two megabytes went into a home directory
     * that way, with the log saying it had all gone fine. An empty root means
     * the home directory, which is what the server gives us anyway.
     */
    protected function root() : string {
        return rtrim( Controller::instance()->options()->get( 'remote_root' ), '/' );
    }

    protected function connect() : bool {
        $this->connection = $this->connection ?: $this->openConnection();

        return (bool) $this->connection;
    }

    /**
     * The name the add-on has always had for this. Kept so anything calling it
     * — the CLI, somebody's script — keeps working.
     */
    public function upload_files( string $processed_site_path ) : void {
        $this->deploy( $processed_site_path );
    }

    /**
     * Open the connection described by the saved options.
     *
     * @return SFTP|null Null when the options are incomplete or login fails.
     */
    private function openConnection() : ?SFTP {
        $options = Controller::instance()->options();

        $host = $options->get( 'host' );

        if ( '' === $host ) {
            WsLog::l( 'No sFTP host set. See WP2Static > Addons > sFTP > Configure.' );

            return null;
        }

        /*
         * The port used to be read only when a password was set:
         * `getValue( 'password' ) ? (int) getValue( 'port' ) : 22`.
         * Whoever configured a non-standard port and authenticated any other
         * way silently got 22, and the deploy failed against a host that was
         * listening one line above in the same form.
         */
        $port = $options->int( 'port' );
        $connection = new SFTP( $host, $port > 0 ? $port : 22 );

        $username = $options->get( 'username' );

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
     * @return PrivateKey|string|null Null when nothing usable is configured.
     */
    private function credential() {
        $options = Controller::instance()->options();

        $key_path = $options->get( 'private_key' );

        if ( '' === $key_path ) {
            // plain() decrypts; get() would hand back the ciphertext.
            return $options->plain( 'password' );
        }

        if ( ! is_readable( $key_path ) ) {
            WsLog::l( 'sFTP private key not readable: ' . $key_path );

            return null;
        }

        $passphrase = $options->plain( 'passphrase' );

        /*
         * PublicKeyLoader works out the format itself, and that is the reason
         * for phpseclib 3 rather than 2 here. Version 2 had `Crypt\RSA` and
         * nothing else: it could not read a key in OpenSSH format — which is
         * what `ssh-keygen` has produced by default for years — so anyone
         * generating a key today was told their key was unreadable, and it
         * could not read an Ed25519 key at all. It throws rather than
         * returning false, so the failure is caught rather than tested for.
         */
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.
            $key = PublicKeyLoader::load( (string) file_get_contents( $key_path ), $passphrase );
        } catch ( NoKeyLoadedException $exception ) {
            WsLog::l( 'sFTP private key could not be read: wrong format, or wrong passphrase.' );

            return null;
        }

        if ( ! $key instanceof PrivateKey ) {
            // A public key where a private one belongs: it loads, and then
            // authentication fails with something far less obvious.
            WsLog::l( 'sFTP private key path holds a public key, not a private one.' );

            return null;
        }

        return $key;
    }

    /**
     * @param string $local       Absolute local path.
     * @param string $destination Path on the remote server, root included.
     */
    protected function put( string $local, string $destination ) : bool {
        if ( ! is_readable( $local ) || null === $this->connection ) {
            return false;
        }

        $directory = dirname( $destination );

        if ( '.' !== $directory && '/' !== $directory ) {
            // mkdir() with the recursive flag, instead of walking the path one
            // segment at a time with a chdir() per segment per file.
            $this->connection->mkdir( $directory, -1, true );
        }

        return (bool) $this->connection->put( $destination, $local, SFTP::SOURCE_LOCAL_FILE );
    }

    protected function delete( string $destination ) : bool {
        if ( null === $this->connection ) {
            return false;
        }

        // `false`: not recursively. These are files, and a recursive delete
        // pointed at a path that turned out to be a directory would take the
        // directory with it.
        return (bool) $this->connection->delete( $destination, false );
    }

    protected function removeDirectory( string $destination ) : bool {
        if ( null === $this->connection ) {
            return false;
        }

        return (bool) $this->connection->rmdir( $destination );
    }
}
