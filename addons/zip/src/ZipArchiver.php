<?php
/**
 * Pack the processed site into one archive.
 *
 * No DeployCache here, and that is right rather than an oversight: an archive
 * is rebuilt whole or it is not an archive of the current site. The incremental
 * plan describes what changed at a destination that keeps its previous state,
 * and a zip does not keep one.
 *
 * @package WP2StaticZip
 */

namespace WP2StaticZip;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP2Static\WsLog;
use ZipArchive;

class ZipArchiver {

    public function generateArchive( string $processed_site_path ) : void {
        WsLog::l( 'Generating deployable ZIP file...' );

        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        $archive_path = rtrim( $processed_site_path, '/' );
        $temp_zip = $archive_path . '.tmp';
        $zip_path = $archive_path . '.zip';

        $zip_archive = new ZipArchive();

        if ( true !== $zip_archive->open( $temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $error = 'Could not create zip: ' . $temp_zip;
            WsLog::l( $error );

            throw new \WP2Static\WP2StaticException( esc_html( $error ) );
        }

        $added = $this->addFiles( $zip_archive, $processed_site_path );
        $added += $this->addRedirects( $zip_archive );

        $zip_archive->close();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- local file.
        rename( $temp_zip, $zip_path );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod -- local file.
        chmod( $zip_path, 0644 );

        WsLog::l( "Completed deployable ZIP file generation: $added entries." );
    }

    /**
     * @param ZipArchive $zip_archive  Open archive.
     * @param string     $processed_site_path Directory to pack.
     * @return int How many entries were added.
     */
    private function addFiles( ZipArchive $zip_archive, string $processed_site_path ) : int {
        /** @var iterable<string, \SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $processed_site_path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $added = 0;

        foreach ( $iterator as $filename => $file_object ) {
            $real_filepath = realpath( $filename );

            if ( ! $real_filepath ) {
                $error = 'Trying to add unknown file to Zip: ' . $filename;
                WsLog::l( $error );

                throw new \WP2Static\WP2StaticException( esc_html( $error ) );
            }

            /*
             * Windows separators normalised. The original followed this with
             * `if ( ! is_string( $filename ) ) { continue; }` — str_replace on
             * a string returns a string, so the branch could never be taken.
             */
            $entry = str_replace( '\\', '/', $filename );

            if ( ! $zip_archive->addFile( $real_filepath, str_replace( $processed_site_path, '.', $entry ) ) ) {
                $error = 'Could not add file: ' . $entry;
                WsLog::l( $error );

                throw new \WP2Static\WP2StaticException( esc_html( $error ) );
            }

            $added++;
        }

        return $added;
    }

    /**
     * Write the redirects into the archive as a manifest.
     *
     * The module never asked for them, so a site's 301s simply were not in the
     * archive: whoever unpacked it got the pages and no record that half a
     * dozen old addresses were meant to point at them. The S3 deployer has
     * consulted `wp2static_list_redirects` all along — this is the same filter,
     * and it is alive in the core.
     *
     * A text file rather than server configuration, because a zip does not know
     * what will unpack it: Apache, nginx and Netlify each want a different
     * shape, and guessing wrong is worse than handing over the list.
     *
     * @param ZipArchive $zip_archive Open archive.
     * @return int 1 when a manifest was written, 0 when there was nothing to write.
     */
    private function addRedirects( ZipArchive $zip_archive ) : int {
        /** @var mixed $redirects */
        $redirects = apply_filters( 'wp2static_list_redirects', [] );

        if ( ! is_array( $redirects ) || ! $redirects ) {
            return 0;
        }

        $lines = [];

        foreach ( $redirects as $from => $to ) {
            // Skipped rather than stringified: an entry that is not a pair of
            // strings is a filter having returned something unexpected, and
            // writing `Array` into the manifest hides that rather than showing
            // it.
            if ( ! is_string( $from ) || ! is_string( $to ) ) {
                continue;
            }

            $lines[] = $from . ' ' . $to;
        }

        if ( ! $lines ) {
            return 0;
        }

        $zip_archive->addFromString( './_redirects', implode( "\n", $lines ) . "\n" );

        WsLog::l( sprintf( 'Added %d redirect(s) to the archive.', count( $lines ) ) );

        return 1;
    }
}
