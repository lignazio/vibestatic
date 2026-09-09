<?php
/**
 * Upload the generated site to Netlify.
 *
 * No DeployCache, and that is right: Netlify's deploy API is itself a digest
 * protocol. You send it every path with the SHA-1 of its contents, it answers
 * with the SHAs it does not already hold, and you upload only those. The
 * incremental decision is made by the side that knows what it has.
 *
 * @package WP2StaticNetlify
 */

namespace WP2StaticNetlify;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer {

    const API = 'https://api.netlify.com/';

    /**
     * @var Client|null Injectable, so the upload logic can be exercised
     *                  without an account on the other end.
     */
    private $client = null;

    /**
     * @param Client|null $client Null to build one against the real API.
     */
    public function __construct( ?Client $client = null ) {
        $this->client = $client;
    }

    public function uploadFiles( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        $options = Controller::instance()->options();

        $site_id = $options->get( 'siteID' );

        if ( '' === $site_id ) {
            WsLog::l( 'Netlify site ID is not set.' );

            return;
        }

        // plain() decrypts; get() would hand back the ciphertext.
        $access_token = $options->plain( 'accessToken' );

        if ( '' === $access_token ) {
            WsLog::l( 'Netlify access token is not set.' );

            return;
        }

        $digest = $this->digest( $processed_site_path );

        if ( ! $digest ) {
            WsLog::l( 'Nothing to deploy: the processed site is empty.' );

            return;
        }

        $client = $this->client ?: new Client( [ 'base_uri' => self::API ] );

        $deploy = $this->openDeploy( $client, $site_id, $access_token, $digest );

        if ( null === $deploy ) {
            return;
        }

        [ $deploy_id, $required ] = $deploy;

        $this->upload( $client, $access_token, $deploy_id, $required, $digest, $processed_site_path );
    }

    /**
     * Every path in the processed site, with the SHA-1 of its contents.
     *
     * Keyed by remote path — the shape Netlify's API asks for, and the shape
     * that cannot lose anything. The original built a second map keyed by
     * *content hash* to drive the uploads, so two files with the same contents
     * collapsed into one entry.
     *
     * @param string $processed_site_path Directory to describe.
     * @return array<string, string> Remote path => sha1.
     */
    private function digest( string $processed_site_path ) : array {
        /** @var iterable<string, \SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $processed_site_path,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $digest = [];

        foreach ( $iterator as $filename => $file_object ) {
            if ( ! $file_object->isFile() ) {
                continue;
            }

            $hash = sha1_file( $filename );

            if ( false === $hash ) {
                WsLog::l( 'Could not read for deployment: ' . $filename );

                continue;
            }

            // Windows separators normalised. The original followed this with a
            // `! is_string()` check on the result of str_replace, which cannot
            // be anything else.
            $entry = str_replace( '\\', '/', $filename );

            $digest[ str_replace( $processed_site_path, '', $entry ) ] = $hash;
        }

        return $digest;
    }

    /**
     * Open a deploy and find out what Netlify still needs.
     *
     * @param Client                $client       HTTP client.
     * @param string                $site_id      Netlify site ID.
     * @param string                $access_token Personal access token.
     * @param array<string, string> $digest       Remote path => sha1.
     * @return array{0: string, 1: string[]}|null Deploy id and required hashes.
     */
    private function openDeploy( Client $client, string $site_id, string $access_token, array $digest ) {
        try {
            $response = $client->request(
                'POST',
                "/api/v1/sites/$site_id/deploys",
                [
                    'headers' => $this->headers( $access_token ),
                    'json' => [ 'files' => $digest ],
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'Netlify refused the deploy: ' . $exception->getMessage() );

            return null;
        }

        /*
         * Checked, not assumed. The original read `$response->id` straight off
         * the decoded body, so an expired token — the single most likely thing
         * to go wrong here — surfaced as `Attempt to read property "id" on
         * null`, which says nothing about tokens.
         */
        $body = json_decode( (string) $response->getBody() );

        if ( ! is_object( $body ) || ! isset( $body->id ) || ! is_string( $body->id ) ) {
            WsLog::l( 'Netlify returned a deploy without an id: check the site ID and token.' );

            return null;
        }

        $required = [];

        // Filtered rather than cast: `required` comes off the wire, and a SHA
        // that is not a string is the API having said something unexpected —
        // worth dropping rather than turning into the word "Array".
        $api_required = $body->required ?? [];

        foreach ( is_array( $api_required ) ? $api_required : [] as $sha ) {
            if ( is_string( $sha ) ) {
                $required[] = $sha;
            }
        }

        return [ $body->id, $required ];
    }

    /**
     * Upload the contents Netlify asked for.
     *
     * **One upload per required hash, not per path**, which is the protocol:
     * `required` is a list of SHAs, and Netlify attaches the content to every
     * path that declared that SHA in the digest. Two identical files are
     * therefore uploaded once and land at both paths.
     *
     * The original arrived at the same behaviour by accident — its upload map
     * was keyed by hash, so a duplicate silently replaced the entry before it —
     * and left a `TODO: rm duplicate hashes` beside it. Doing it deliberately
     * is the difference between "uploads once" and "happens to upload once,
     * and nobody knows which of the two paths it used".
     *
     * @param Client                $client       HTTP client.
     * @param string                $access_token Personal access token.
     * @param string                $deploy_id    The open deploy.
     * @param string[]              $required     SHAs Netlify does not hold.
     * @param array<string, string> $digest       Remote path => sha1.
     * @param string                $processed_site_path Local root.
     */
    private function upload(
        Client $client,
        string $access_token,
        string $deploy_id,
        array $required,
        array $digest,
        string $processed_site_path
    ) : void {
        $wanted = array_flip( $required );
        $sent = [];
        $uploaded = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ( $digest as $remote_path => $hash ) {
            if ( ! isset( $wanted[ $hash ] ) ) {
                $unchanged++;

                continue;
            }

            if ( isset( $sent[ $hash ] ) ) {
                // Same contents as a path already sent: Netlify resolves the
                // rest by SHA from the digest.
                $unchanged++;

                continue;
            }

            if ( $this->put( $client, $access_token, $deploy_id, $remote_path, $processed_site_path ) ) {
                $sent[ $hash ] = true;
                $uploaded++;

                continue;
            }

            $failed++;
        }

        WsLog::l(
            "Netlify deploy complete: $uploaded uploaded, $unchanged unchanged, $failed failed."
        );
    }

    /**
     * @param Client $client       HTTP client.
     * @param string $access_token Personal access token.
     * @param string $deploy_id    The open deploy.
     * @param string $remote_path  Path at the destination, leading slash included.
     * @param string $processed_site_path Local root.
     */
    private function put(
        Client $client,
        string $access_token,
        string $deploy_id,
        string $remote_path,
        string $processed_site_path
    ) : bool {
        $local = $processed_site_path . $remote_path;

        /*
         * A handle and not the file's contents: Netlify wants the bytes as a
         * stream, and reading a whole upload into memory first would make the
         * largest publishable file a function of PHP's memory_limit. WP_Filesystem
         * has no streaming read to offer instead — `get_contents()` is the only
         * thing it has, and that is the version being avoided.
         */
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a stream is the point; see above.
        $handle = fopen( $local, 'r' );

        if ( false === $handle ) {
            WsLog::l( 'Could not open for upload: ' . $local );

            return false;
        }

        try {
            $client->request(
                'PUT',
                '/api/v1/deploys/' . $deploy_id . '/files' . $this->encodePath( $remote_path ),
                [
                    'headers' => $this->headers( $access_token, 'application/octet-stream' ),
                    'body' => $handle,
                ]
            );
        } catch ( GuzzleException $exception ) {
            /*
             * Counted as a failure, and said so. The original never checked:
             * every PUT incremented the "deployed" total whether it had arrived
             * or not, so a deploy that lost half the site reported success.
             */
            WsLog::l( 'Netlify rejected ' . $remote_path . ': ' . $exception->getMessage() );

            return false;
        }

        return true;
    }

    /**
     * Percent-encode a path without destroying it.
     *
     * `urlencode()` turns `/` into `%2F`, and the original applied it to the
     * whole path: every file in a subdirectory was asked for under a name with
     * the separators encoded into it. Each segment is encoded on its own, and
     * rawurlencode because a space in a path is `%20`, not `+`.
     */
    private function encodePath( string $remote_path ) : string {
        $segments = explode( '/', $remote_path );

        return implode( '/', array_map( 'rawurlencode', $segments ) );
    }

    /**
     * @return array<string, string>
     */
    private function headers( string $access_token, string $content_type = '' ) : array {
        $headers = [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
        ];

        if ( '' !== $content_type ) {
            $headers['Content-Type'] = $content_type;
        }

        return $headers;
    }
}
