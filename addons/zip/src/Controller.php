<?php

namespace WP2StaticZip;

class Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     */
    const SLUG = 'wp2static-addon-zip';

    /**
     * The admin page's slug.
     *
     * There used to be two, and that is the whole of the defect: the page was
     * registered as `wp2static-addon-zip` while the "Refresh page" link and the
     * redirect after deleting both pointed at `wp2static-zip`, which does not
     * exist. Both of them landed on "you are not authorized". A constant so
     * there is one name and not three spellings of it.
     */
    const PAGE = 'wp2static-addon-zip';

    /**
     * The archive's filename under uploads.
     */
    const FILENAME = 'wp2static-processed-site.zip';

    public function run() : void {
        add_action(
            'admin_post_wp2static_zip_delete',
            [ $this, 'deleteZip' ],
            15,
            1
        );

        add_action(
            'admin_post_wp2static_zip_download',
            [ $this, 'downloadZip' ],
            15,
            1
        );

        add_action(
            'wp2static_deploy',
            [ $this, 'generateZip' ],
            15,
            2
        );

        add_action( 'admin_menu', [ $this, 'addOptionsPage' ], 15, 1 );

        add_action( 'init', [ $this, 'registerAddon' ] );

        if ( defined( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static zip', [ CLI::class, 'zip' ] );
        }
    }

    public function registerAddon() : void {
        do_action(
            'wp2static_register_addon',
            self::SLUG,
            'deploy',
            'ZIP',
            'https://github.com/lignazio/vibestatic#zip',
            'Packs the generated site into a downloadable ZIP archive'
        );
    }

    /**
     * Absolute path of the archive.
     */
    public static function path() : string {
        return \WP2Static\SiteInfo::getPath( 'uploads' ) . self::FILENAME;
    }

    public static function renderZipPage() : void {
        $zip_path = self::path();
        $exists = is_file( $zip_path );

        $view = [
            'nonce_action' => 'wp2static-zip-actions',
            'page' => self::PAGE,
            'zip_exists' => $exists,
            'zip_size' => $exists ? size_format( (int) filesize( $zip_path ) ) : '',
            'zip_created' => $exists ? self::formatTime( (int) filemtime( $zip_path ) ) : '',
        ];

        require __DIR__ . '/../views/zip-page.php';
    }

    /**
     * A timestamp in the site's own date and time format.
     *
     * The two options are read into variables and checked: `get_option()`
     * returns whatever is in the row or whatever a filter made of it, so
     * casting it to string is asking a question with no answer when somebody
     * has put an array there.
     */
    private static function formatTime( int $timestamp ) : string {
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        $format = ( is_string( $date_format ) ? $date_format : 'Y-m-d' )
            . ' ' . ( is_string( $time_format ) ? $time_format : 'H:i' );

        return (string) wp_date( $format, $timestamp );
    }

    /**
     * Delete the archive.
     *
     * No parameter. It is registered on `admin_post_*`, which WordPress fires
     * with no arguments at all, so declaring one made every click on "Delete
     * ZIP" an ArgumentCountError — a white screen, every time, since PHP 8. The
     * argument was never used.
     */
    public function deleteZip() : void {
        /*
         * Capability first, then nonce, and both before anything is written.
         * The original logged "Deleting deployable site ZIP file." on the line
         * *above* check_admin_referer() and never asked who was asking: the
         * same order the core corrected in three of its own handlers.
         */
        \WP2Static\Controller::authorize( 'wp2static-zip-actions' );

        $zip_path = self::path();

        if ( is_file( $zip_path ) ) {
            \WP2Static\WsLog::l( 'Deleting deployable site ZIP file.' );

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a local file this module wrote.
            unlink( $zip_path );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    /**
     * Hand the archive over as a download.
     *
     * Through admin-post rather than as a link straight at the uploads URL: a
     * guessable address that can be pasted anywhere becomes an action that asks
     * who is asking.
     *
     * **It does not make the archive private, and it should not be read as
     * doing so.** The file stays where it was written, under uploads, and is
     * still readable there — as is `wp2static-processed-site/`, the whole
     * generated site as loose files, which answers 200 today. Where generated
     * artefacts live is a question for the core, and worth asking properly
     * rather than half-answering here.
     */
    public function downloadZip() : void {
        \WP2Static\Controller::authorize( 'wp2static-zip-actions' );

        $zip_path = self::path();

        if ( ! is_file( $zip_path ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
            exit;
        }

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . self::FILENAME . '"' );
        header( 'Content-Length: ' . (string) filesize( $zip_path ) );
        header( 'X-Content-Type-Options: nosniff' );

        // readfile() streams: a site's archive can be hundreds of megabytes,
        // and file_get_contents() would ask PHP to hold all of it in memory.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $zip_path );
        exit;
    }

    /**
     * @param string $processed_site_path The processed site's directory.
     * @param string $enabled_deployer    Slug of the deployer the user selected.
     */
    public function generateZip( string $processed_site_path, string $enabled_deployer = '' ) : void {
        if ( self::SLUG !== $enabled_deployer ) {
            return;
        }

        ( new ZipArchiver() )->generateArchive( $processed_site_path );
    }

    public function addOptionsPage() : void {
        /*
         * Through the core, which also gives the hidden page a title. The
         * original hung the page off `options.php` and then added a
         * `parent_file` filter that rewrote the global `$plugin_page` so the
         * VibeStatic menu would stay highlighted — a workaround for a parent
         * that was wrong to begin with, and one that left the page with no
         * title, so `admin-header.php` called strip_tags( null ).
         */
        \WP2Static\Controller::addHiddenPage(
            __( 'ZIP Deployment', 'vibestatic' ),
            self::PAGE,
            [ $this, 'renderZipPage' ]
        );
    }
}
