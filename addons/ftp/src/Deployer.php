<?php
/**
 * Upload the generated site over FTP.
 *
 * The plan-driven loop is not here: it is in WP2Static\PlanDrivenDeployer, with
 * the sFTP and directory deployers. What is here is the transport, which is all
 * that is different about FTP.
 *
 * **FTPS by default.** Plain FTP sends the password, and then every byte of the
 * site, in the clear over whatever network is between here and the server. It
 * is still available, because shared hosting sometimes offers nothing else, but
 * it has to be asked for.
 *
 * @package WP2StaticFTP
 */

namespace WP2StaticFTP;

use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    /**
     * The namespace these files are recorded under in the core's DeployCache.
     *
     * Its own, not shared with sFTP: the two modules can be configured to
     * different servers, and a shared namespace would have each of them see the
     * other's uploads as its own and skip files it had never sent.
     */
    const DEFAULT_NAMESPACE = 'wp2static-addon-ftp';

    /**
     * @var resource|\FTP\Connection|null Injectable, so the upload logic can be
     *                                    exercised without a server.
     */
    private $connection = null;

    /**
     * @var array<string, true> Directories already made in this run.
     */
    private $made = [];

    /**
     * @param resource|\FTP\Connection|null $connection Null to open one from
     *                                                  the saved options.
     */
    public function __construct( $connection = null ) {
        $this->connection = $connection;
    }

    protected function deployCacheNamespace() : string {
        return self::DEFAULT_NAMESPACE;
    }

    protected function label() : string {
        return 'FTP deployment';
    }

    /**
     * Only the trailing slash is trimmed.
     *
     * Trimming both ends turns an absolute remote root into a relative one, and
     * the server then creates the whole tree under the login directory instead:
     * the deploy reports every file as uploaded, because it was — just not
     * where the user asked. That is not hypothetical, it is what happened to
     * the sFTP module, and eighty-two megabytes went into a home directory with
     * the log saying it had all gone fine.
     */
    protected function root() : string {
        return rtrim( Controller::instance()->options()->get( 'remote_root' ), '/' );
    }

    protected function connect() : bool {
        if ( null === $this->connection ) {
            $this->connection = $this->openConnection();
        }

        $this->made = [];

        return null !== $this->connection;
    }

    protected function disconnect() : void {
        if ( null !== $this->connection ) {
            @ftp_close( $this->connection );
        }

        $this->connection = null;
    }

    /**
     * Open the connection described by the saved options.
     *
     * `\FTP\Connection` and not `resource`: since PHP 8.1 `ftp_connect()`
     * returns an object, and the union said this could still be a resource on
     * a plugin whose minimum is 8.2. It never can.
     *
     * @return \FTP\Connection|null Null when the options are incomplete or the
     *                              login fails.
     */
    private function openConnection() {
        $host = Controller::instance()->options()->get( 'host' );

        if ( '' === $host ) {
            WsLog::l( 'FTP host is not set.' );

            return null;
        }

        $port = Controller::instance()->options()->int( 'port' );
        $port = $port > 0 ? $port : 21;

        $explicit_tls = Controller::instance()->options()->bool( 'use_tls' );

        /*
         * `ftp_ssl_connect` is explicit TLS — the connection starts in the
         * clear and is upgraded with AUTH TLS. It is not FTPS-over-465, which
         * PHP does not do, and it is not SFTP, which is a different protocol
         * entirely and lives in the sFTP module.
         */
        $connection = $explicit_tls
            ? @ftp_ssl_connect( $host, $port, 30 )
            : @ftp_connect( $host, $port, 30 );

        if ( false === $connection ) {
            WsLog::l(
                sprintf(
                    'Could not reach %s:%d over %s.',
                    $host,
                    $port,
                    $explicit_tls ? 'FTPS' : 'FTP'
                )
            );

            return null;
        }

        $options = Controller::instance()->options();

        $username = $options->get( 'username' );
        // plain() decrypts; get() would hand back the ciphertext.
        $password = $options->plain( 'password' );

        if ( ! @ftp_login( $connection, $username, $password ) ) {
            WsLog::l( 'FTP login failed for ' . $username . '@' . $host );
            @ftp_close( $connection );

            return null;
        }

        /*
         * Passive mode, and it is the default rather than an option because
         * active mode asks the *server* to open a connection back to the web
         * server — which almost no firewall allows and almost no host expects.
         * A deploy that hangs on the first file is what active mode looks like
         * from the outside.
         */
        if ( ! @ftp_pasv( $connection, true ) ) {
            WsLog::l( 'FTP server refused passive mode; transfers may hang.' );
        }

        return $connection;
    }

    /**
     * @param string $local       Absolute local path.
     * @param string $destination Path on the remote server, root included.
     */
    protected function put( string $local, string $destination ) : bool {
        if ( ! is_readable( $local ) || null === $this->connection ) {
            return false;
        }

        if ( ! $this->makeDirectory( dirname( $destination ) ) ) {
            return false;
        }

        /*
         * Binary, always. FTP's ASCII mode rewrites line endings in transit,
         * which corrupts every image, font and archive on the site — and is
         * the default on some servers.
         */
        return @ftp_put( $this->connection, $destination, $local, FTP_BINARY );
    }

    protected function delete( string $destination ) : bool {
        if ( null === $this->connection ) {
            return false;
        }

        return @ftp_delete( $this->connection, $destination );
    }

    protected function removeDirectory( string $destination ) : bool {
        if ( null === $this->connection ) {
            return false;
        }

        $removed = @ftp_rmdir( $this->connection, $destination );

        if ( $removed ) {
            unset( $this->made[ $destination ] );
        }

        return $removed;
    }

    /**
     * Make a remote directory and its parents, once each.
     *
     * FTP has no recursive mkdir: the path has to be walked. It is walked once
     * per directory rather than once per file, because a site with eighteen
     * hundred pages in a hundred directories would otherwise spend eighteen
     * hundred round trips asking for directories that already exist — and a
     * round trip to a remote server is the expensive thing here.
     */
    private function makeDirectory( string $directory ) : bool {
        if ( '' === $directory || '.' === $directory || '/' === $directory ) {
            return true;
        }

        if ( isset( $this->made[ $directory ] ) ) {
            return true;
        }

        if ( ! $this->makeDirectory( dirname( $directory ) ) ) {
            return false;
        }

        if ( null === $this->connection ) {
            return false;
        }

        /*
         * The failure is not checked, and deliberately: `ftp_mkdir` fails both
         * when the directory cannot be made and when it is already there, and
         * FTP gives no reliable way to tell those apart. What settles it is
         * whether the file then arrives, which the caller finds out anyway.
         */
        @ftp_mkdir( $this->connection, $directory );

        $this->made[ $directory ] = true;

        return true;
    }
}
