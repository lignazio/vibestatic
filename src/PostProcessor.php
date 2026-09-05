<?php
/*
    PostProcessor

    Processes each file in StaticSite, saving to ProcessedSite
*/

namespace WP2Static;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class PostProcessor {

    /**
     * PostProcessor constructor
     */
    public function __construct() {
    }

    /**
     * Remove from the processed site whatever the crawled site no longer has.
     *
     * The loop above never deletes: it copies and rewrites, so a file that left
     * the crawled site stayed in the processed one forever, and
     * `DeployCache::plan()` saw it as "unchanged" and republished it on every
     * run. It was the missing piece between a crawl that already knew how to
     * forget and a deploy that already knew how to remove.
     *
     * As in the Crawler, the guarantee is that this is only reached once the
     * run has finished: the list is what the iterator saw in full. An
     * interrupted post-processing run never gets here.
     *
     * And as in the Crawler, an empty list deletes nothing — an empty crawled
     * site is a crawl that never ran, not a site that no longer exists.
     *
     * @param string[] $processed_paths Paths just written.
     */
    private function pruneProcessedSite( array $processed_paths ) : void {
        if ( ! FilesHelper::pruningEnabled() ) {
            return;
        }

        $removed = ProcessedSite::prune( $processed_paths );

        if ( $removed ) {
            WsLog::l(
                sprintf(
                    'Pruned processed site: %d file(s) no longer in the crawled site.',
                    count( $removed )
                )
            );
        }
    }

    /**
     * Process StaticSite
     *
     * Iterates on each file, not directory
     *
     * @param string $static_site_path Static site path
     * @throws WP2StaticException
     */
    public function processStaticSite(
        string $static_site_path
    ) : void {
        WsLog::l(
            'Processing crawled site.'
        );

        if ( ! is_dir( $static_site_path ) ) {
            WsLog::l(
                'No static site directory to process.'
            );

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $static_site_path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $processed_paths = [];

        foreach ( $iterator as $filename => $file_object ) {
            /**
             * @var string $filename
             */

            $save_path = str_replace( $static_site_path, '', $filename );

            // copy file to ProcessedSite dir, then process it
            // this allows external processors to have their way with it
            ProcessedSite::add( $filename, $save_path );

            $file_processor = new FileProcessor();

            $file_processor->processFile( ProcessedSite::getPath() . $save_path );

            /*
             * `ProcessedSite::add()` writes to `getPath() . "/$save_path"` and
             * $save_path already starts with a slash: the file lands at the
             * path with a single slash, but the string carries two. What is
             * needed here is the normalised form, which is what anyone scanning
             * the directory reads back.
             */
            $processed_paths[] = '/' . ltrim( $save_path, '/' );
        }

        WsLog::l( 'Finished processing crawled site.' );

        $this->pruneProcessedSite( $processed_paths );

        do_action( 'wp2static_post_process_complete', ProcessedSite::getPath() );
    }
}
