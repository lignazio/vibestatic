<?php
/**
 * The one directory under uploads that the plugin writes into.
 *
 * Everything the plugin produces on disk — the crawled site, the post-processed
 * site, the ZIP module's archive — used to sit directly in the uploads root,
 * one entry each, and each of them answered 200 to anyone who guessed the name.
 * `wp-content/uploads/wp2static-processed-site/wp-config-backup.html` is not a
 * hypothetical: whatever the crawl picked up was published there before the
 * user had chosen to publish anything.
 *
 * The directory's name is the plugin's slug, which is what the directory
 * guidelines ask for, and it is resolved at runtime from `wp_upload_dir()`
 * through SiteInfo — never a constant, so a site with a moved uploads
 * directory, or a multisite where every site has its own, gets its own.
 *
 * Creating it also writes the three files that keep a web server from serving
 * what is inside: `.htaccess` for Apache, `web.config` for IIS, and an
 * `index.php` so that a server with directory listing on and neither of those
 * honoured still has nothing to show. nginx honours none of the three — there
 * is no per-directory configuration to write — which is why the archive is
 * handed over through an admin-post handler that checks the capability and the
 * nonce, rather than by linking at its URL.
 *
 * @package WP2Static
 */

namespace WP2Static;

class StorageDir {

    /**
     * The directory's name under uploads: the plugin's slug.
     */
    const DIRNAME = 'vibestatic';

    /**
     * Whether this request has already made sure the directory is there.
     *
     * `path()` is called from inside the crawl loop, once per saved file on a
     * site that can have fifteen thousand of them. Three `is_file()` calls per
     * saved file is three stat syscalls that answer the same thing every time.
     *
     * @var bool
     */
    private static $ensured = false;

    /**
     * Absolute path of the directory, trailing-slashed, created if it is not.
     */
    public static function path() : string {
        $path = self::pathWithoutEnsuring();

        if ( ! self::$ensured ) {
            self::ensure();
        }

        return $path;
    }

    /**
     * The same path, without the side effect.
     *
     * For the callers that only want to know where it would be — uninstall,
     * the crawl's exclusion test — and have no reason to create it.
     */
    public static function pathWithoutEnsuring() : string {
        return SiteInfo::getPath( 'uploads' ) . self::DIRNAME . '/';
    }

    /**
     * Create the directory and protect it from direct access.
     *
     * Idempotent, and cheap once the files are there: the protection files are
     * only written when missing, so a user who has replaced them with something
     * their host needs keeps it.
     *
     * @return bool True when the directory exists and is writable afterwards.
     */
    public static function ensure() : bool {
        self::$ensured = true;

        $path = self::pathWithoutEnsuring();

        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
            WsLog::l( 'Could not create the plugin storage directory: ' . $path );

            return false;
        }

        foreach ( self::protectionFiles() as $filename => $contents ) {
            if ( is_file( $path . $filename ) ) {
                continue;
            }

            /*
             * Direct, and it stays. WP_Filesystem abstracts over FTP and sFTP
             * as much as over the local disk: on a site configured for one of
             * those it would open a network connection to write four hundred
             * bytes into a directory that is on this machine, inside uploads,
             * which this method has just created.
             */
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- see above.
            file_put_contents( $path . $filename, $contents );
        }

        return wp_is_writable( $path );
    }

    /**
     * The files that keep the directory from being served.
     *
     * @return array<string, string> Filename to contents.
     */
    private static function protectionFiles() : array {
        return [
            '.htaccess' =>
                "# Written by VibeStatic. Nothing in this directory is meant to be\n" .
                "# reachable over HTTP.\n" .
                "<IfModule mod_authz_core.c>\n" .
                "    Require all denied\n" .
                "</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n" .
                "    Order allow,deny\n" .
                "    Deny from all\n" .
                "</IfModule>\n",
            'web.config' =>
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<configuration>\n" .
                "    <system.webServer>\n" .
                "        <authorization>\n" .
                "            <deny users=\"*\" />\n" .
                "        </authorization>\n" .
                "    </system.webServer>\n" .
                "</configuration>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
        ];
    }

    /**
     * True when a path is inside the plugin's own storage.
     *
     * The crawl walks the uploads directory looking for files to publish, and
     * the plugin's own output is in there. It used to be kept out by two names
     * in the user-editable "Directory and File Names to Ignore" list, which is
     * the wrong place for it: the list is a preference, and a user who prunes
     * it has the crawler publishing the previous crawl's copy of the site into
     * the next one, doubling on every run.
     *
     * @param string $path An absolute path, with either separator.
     */
    public static function contains( string $path ) : bool {
        $storage = self::pathWithoutEnsuring();

        return 0 === strpos( str_replace( '\\', '/', $path ), $storage );
    }

    /**
     * Move what earlier versions left in the uploads root into the directory.
     *
     * Called from Schema::install(), so it runs on activation and on the
     * upgrade that raises the schema version, and it is safe to run again: the
     * second time there is nothing left in the old places.
     *
     * The crawled and processed sites are caches and could simply be deleted —
     * the next run rebuilds them. They are moved instead because the archive
     * cannot be: a user who exported last week and has not downloaded the ZIP
     * yet would find it gone, with nothing in the interface to say why.
     */
    public static function migrateLegacyPaths() : void {
        if ( ! self::ensure() ) {
            return;
        }

        $uploads = SiteInfo::getPath( 'uploads' );
        $storage = self::pathWithoutEnsuring();

        $legacy = [
            'wp2static-crawled-site' => 'crawled-site',
            'wp2static-processed-site' => 'processed-site',
            'wp2static-processed-site.zip' => 'processed-site.zip',
        ];

        foreach ( $legacy as $old_name => $new_name ) {
            $old = $uploads . $old_name;
            $new = $storage . $new_name;

            if ( ! file_exists( $old ) || file_exists( $new ) ) {
                continue;
            }

            /*
             * Direct, for the same reason as above, and with the same file: a
             * path under uploads moving to another path under uploads. Failure
             * is logged and not thrown — an upgrade is not the place to stop
             * the site over a leftover cache that the next export rebuilds.
             */
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_rename -- see above.
            if ( ! rename( $old, $new ) ) {
                WsLog::l( "Could not move $old into the plugin storage directory." );

                continue;
            }

            WsLog::l( "Moved $old_name into " . self::DIRNAME . '/' . $new_name );
        }
    }
}
