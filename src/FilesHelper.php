<?php

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveArrayIterator;
use RecursiveDirectoryIterator;

class FilesHelper {

    /**
     * Recursively delete a directory
     *
     * @throws WP2StaticException
     */
    public static function deleteDirWithFiles( string $dir ) : void {
        if ( is_dir( $dir ) ) {
            $dir_files = scandir( $dir );

            if ( ! $dir_files ) {
                $err = 'Trying to delete nonexistent dir: ' . $dir;
                WsLog::l( $err );
                throw new WP2StaticException( esc_html( $err ) );
            }

            $files = array_diff( $dir_files, [ '.', '..' ] );

            foreach ( $files as $file ) {
                ( is_dir( "$dir/$file" ) ) ?
                self::deleteDirWithFiles( "$dir/$file" ) :
                unlink( "$dir/$file" );
            }

            rmdir( $dir );
        }
    }

    /**
     * How much of the site may vanish in a single run.
     *
     * This exists because a failure upstream does not present as an error: it
     * presents as a shorter list. A sitemap that stops responding, a custom
     * post type not yet registered when the job starts, an uploads directory
     * mounted late — none of these throw anything, and from downstream they
     * are indistinguishable from a user who really did delete half the site.
     * Above this fraction there is no choosing: nothing is deleted and the
     * reason is logged, which is the only answer that cannot unpublish a live
     * site by mistake.
     *
     * Half, because below it sits every plausible failure of a single detector
     * — on WordPress the bulk of the list is `wp-includes` and theme assets,
     * which come from the filesystem and not from an HTTP request — and above
     * it sits only the case where a whole link of the chain has gone.
     */
    const MAX_SHRINK_FRACTION = 0.5;

    /**
     * Whether a site shrinking by this much is to be believed.
     *
     * One threshold for all three places where something is forgotten, because
     * they are the same question: two separate thresholds would mean raising
     * one leaves the others blocking, and blocking halfway is worse than either
     * whole answer.
     *
     * On a total of zero there is nothing to doubt, and the division is not
     * performed.
     */
    public static function shrinkIsPlausible( int $going, int $total ) : bool {
        if ( $total < 1 ) {
            return false;
        }

        // See Crawler::__construct(): a filter returns whatever the code
        // hooking it decides, and here a string or a null would become zero —
        // that is, "never remove anything", silently.
        $filtered = apply_filters(
            'wp2static_max_stale_fraction',
            self::MAX_SHRINK_FRACTION
        );

        $max_fraction = is_numeric( $filtered )
            ? (float) $filtered
            : self::MAX_SHRINK_FRACTION;

        return ( $going / $total ) <= $max_fraction;
    }

    /**
     * Whether the published site is allowed to shrink.
     *
     * It governs all three places where something is forgotten — the crawl
     * queue, the crawled site, the processed site — because they are one
     * decision taken three times, and switching off only one would leave the
     * directories out of step with each other.
     *
     * It exists because a wrong prune unpublishes a live site, and that is the
     * only category of damage this code can do: someone in that situation needs
     * to stop it without having to know which paths to rescue one by one.
     */
    public static function pruningEnabled() : bool {
        return (bool) apply_filters( 'wp2static_prune_stale_files', true );
    }

    /**
     * Remove from a directory the files that are not in the list, and the
     * directories left empty.
     *
     * The paths, both those in the list and those returned, are relative to the
     * root and start with `/`: the same shape `StaticSite::add()` and
     * `ProcessedSite::add()` write and the deploy plan reads. The comparison is
     * on the path as it sits on disk, not on `realpath()`, because that is how
     * the file was written.
     *
     * **An empty list deletes nothing.** Empty means "I do not know", not "the
     * site is empty", and between those two readings sits a published site that
     * disappears. Anyone who really wants to empty it has `StaticSite::delete()`
     * and `ProcessedSite::delete()`, which exist for that.
     *
     * @param string   $dir  Root to clean up.
     * @param string[] $keep Relative paths to keep.
     * @return string[] Paths removed.
     */
    public static function removePathsNotIn( string $dir, array $keep ) : array {
        if ( ! $keep || ! is_dir( $dir ) ) {
            return [];
        }

        $dir = rtrim( $dir, '/' );
        $keep_index = array_fill_keys( $keep, true );

        /*
         * The scan finishes before anything is deleted: deleting while the
         * iterator is walking the same directory leaves its state behind the
         * disk, and what gets skipped is an arbitrary entry.
         */
        $to_remove = [];
        $scanned = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ( $iterator as $filename => $file_object ) {
            /**
             * @var string $filename
             */

            $path = substr( $filename, strlen( $dir ) );

            $scanned++;

            if ( ! isset( $keep_index[ $path ] ) ) {
                $to_remove[ $filename ] = $path;
            }
        }

        /*
         * The last safety catch, and it is on a different quantity from the one
         * detection already looked at: there it was the queue, here it is the
         * files. The two do not match if the queue got shorter by a route that
         * does not pass through the comparison with detection — emptied by hand
         * from the Caches page, say, and then only partly refilled. This is the
         * function that actually deletes: it is the last point at which one can
         * still stop.
         */
        if ( ! self::shrinkIsPlausible( count( $to_remove ), $scanned ) ) {
            WsLog::l(
                sprintf(
                    'Refusing to prune %s: %d of its files have nothing that explains them, ' .
                    'which looks more like a step that did not finish than a smaller site. ' .
                    'Nothing was removed.',
                    $dir,
                    count( $to_remove )
                )
            );

            return [];
        }

        $removed = [];
        $emptied = [];

        foreach ( $to_remove as $filename => $path ) {
            if ( unlink( (string) $filename ) ) {
                $removed[] = $path;
                $emptied[ dirname( (string) $filename ) ] = true;
            }
        }

        /*
         * Deepest first, walking up for as long as `rmdir` accepts: a directory
         * can be left empty because the only subdirectory it held was emptied,
         * and in that case its own name never came through here. `rmdir` fails
         * on its own for a non-empty directory, so there is no need to check
         * first.
         */
        krsort( $emptied );

        foreach ( array_keys( $emptied ) as $directory ) {
            while ( 0 === strpos( $directory, $dir . '/' ) && @rmdir( $directory ) ) {
                $directory = dirname( $directory );
            }
        }

        sort( $removed );

        return $removed;
    }

    /**
     * Get public URLs for all files in a local directory.
     *
     * @param string $dir
     * @param array<string> $filenames_to_ignore
     * @param array<string> $file_extensions_to_ignore
     * @return string[] list of relative, urlencoded URLs
     */
    public static function getListOfLocalFilesByDir(
        string $dir,
        array $filenames_to_ignore,
        array $file_extensions_to_ignore
    ) : array {
        $site_path = SiteInfo::getPath( 'site' );

        $files = [];

        if ( is_dir( $dir ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $filename => $file_object ) {
                /**
                 * @var string $filename
                 */

                $path_crawlable = self::pathLooksCrawlable(
                    $filename,
                    $filenames_to_ignore,
                    $file_extensions_to_ignore
                );

                if ( $path_crawlable ) {
                    $files[] = str_replace( $site_path, '/', $filename );
                }
            }
        }

        return $files;
    }

    /**
     * Ensure a given filepath has an allowed filename and extension.
     *
     * @param string $file_name
     * @param array<string> $filenames_to_ignore
     * @param array<string> $file_extensions_to_ignore
     * @return bool  True if the given file does not have a disallowed filename
     *               or extension.
     */
    public static function pathLooksCrawlable(
        string $file_name,
        array $filenames_to_ignore,
        array $file_extensions_to_ignore
    ) : bool {
        $filename_matches = 0;

        str_ireplace( $filenames_to_ignore, '', $file_name, $filename_matches );

        // If we found matches we don't need to go any further
        if ( $filename_matches ) {
            return false;
        }

        /*
          Prepare the file extension list for regex:
          - Add prepending (escaped) \ for a literal . at the start of
            the file extension
          - Add $ at the end to match end of string
          - Add i modifier for case insensitivity
        */
        foreach ( $file_extensions_to_ignore as $extension ) {
            if ( preg_match( "/\\{$extension}$/i", $file_name ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a filter that is meant to answer a list of strings.
     *
     * `apply_filters()` answers whatever the last callback returned, and these
     * two lists are handed straight to methods declared to take `array<string>`
     * — a filter returning a string, or an array with an object in it, would
     * reach a `str_replace()` or an `in_array()` as itself. The elements are
     * kept only if they are strings, which is the only shape the callers use.
     *
     * @param non-empty-string $hook    Filter name.
     * @param array<string>    $default What to filter, and what to fall back to.
     * @return list<string> The filtered list.
     */
    private static function filteredStringList( string $hook, array $default ) : array {
        $filtered = apply_filters( $hook, $default );

        if ( ! is_array( $filtered ) ) {
            WsLog::l(
                "A $hook filter returned " . gettype( $filtered ) .
                ' instead of an array; using the unfiltered list.'
            );

            return array_values( $default );
        }

        return array_values( array_filter( $filtered, 'is_string' ) );
    }

    /**
     * Ensure a given filepath has an allowed filename and extension.
     *
     * @return bool  True if the given file does not have a disallowed filename
     *               or extension.
     */
    public static function filePathLooksCrawlable( string $file_name ) : bool {
        $filenames_to_ignore = self::filteredStringList(
            'wp2static_filenames_to_ignore',
            CoreOptions::getLineDelimitedBlobValue( 'filenamesToIgnore' )
        );

        $file_extensions_to_ignore = self::filteredStringList(
            'wp2static_file_extensions_to_ignore',
            CoreOptions::getLineDelimitedBlobValue( 'fileExtensionsToIgnore' )
        );

        return self::pathLooksCrawlable(
            $file_name,
            $filenames_to_ignore,
            $file_extensions_to_ignore
        );
    }

    /**
     * Clean all detected URLs before use. Accepts relative and absolute URLs
     * both with and without starting or trailing slashes.
     *
     * @param string[] $urls list of absolute or relative URLs
     * @return string[]|null[] list of relative URLs
     * @throws WP2StaticException
     */
    public static function cleanDetectedURLs( array $urls ) : array {
        $home_url = SiteInfo::getUrl( 'home' );

        $cleaned_urls = array_map(
            // trim hashes/query strings
            function ( $url ) use ( $home_url ) {
                if ( ! $url ) {
                    return;
                }

                // NOTE: 2 x str_replace's significantly faster than
                // 1 x str_replace with search/replace arrays of 2 length
                $url = str_replace(
                    $home_url,
                    '/',
                    $url
                );

                $url = str_replace(
                    '//',
                    '/',
                    $url
                );

                $url = strtok( $url, '#' );

                if ( ! $url ) {
                    return;
                }

                $url = strtok( $url, '?' );

                if ( ! $url ) {
                    return;
                }

                return $url;
            },
            $urls
        );

        if ( empty( $cleaned_urls ) ) {
            $err = 'No valid URLs left after cleaning';
            WsLog::l( $err );
            return [];
        }

        return $cleaned_urls;
    }
}
