<?php
/**
 * Upload the generated site to S3, and tell CloudFront what changed.
 *
 * The plan-driven loop is in WP2Static\PlanDrivenDeployer; here is the
 * transport, which is three signed HTTP requests and no SDK. See Signer for
 * why.
 *
 * @package WP2StaticS3
 */

namespace WP2StaticS3;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    const DEFAULT_NAMESPACE = 'wp2static-addon-s3';

    /**
     * CloudFront is a global service and signs against us-east-1, whatever
     * region the bucket is in.
     */
    const CLOUDFRONT_REGION = 'us-east-1';

    const CLOUDFRONT_HOST = 'cloudfront.amazonaws.com';

    const CLOUDFRONT_API = '2020-05-31';

    /**
     * @var Client|null Injectable, so the upload logic can be exercised
     *                  without an AWS account on the other end.
     */
    private $client = null;

    /**
     * @var string[] Keys that changed, for CloudFront.
     */
    private $invalidate = [];

    /**
     * @var Signer|null
     */
    private $signer = null;

    /**
     * @var string
     */
    private $host = '';

    public function __construct( ?Client $client = null ) {
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return self::DEFAULT_NAMESPACE;
    }

    protected function label() : string {
        return 'S3 deployment';
    }

    /**
     * A prefix inside the bucket, or nothing for its root.
     */
    protected function root() : string {
        $prefix = trim( Controller::getValue( 's3RemotePath' ), '/' );

        return '' === $prefix ? '' : '/' . $prefix;
    }

    protected function connect() : bool {
        $bucket = Controller::getValue( 's3Bucket' );
        $region = Controller::getValue( 's3Region' );
        $access_key = Controller::getValue( 's3AccessKeyID' );

        $secret_key = (string) \WP2Static\CoreOptions::encrypt_decrypt(
            'decrypt',
            Controller::getValue( 's3SecretAccessKey' )
        );

        foreach (
            [ 'bucket' => $bucket, 'region' => $region, 'access key' => $access_key,
                'secret key' => $secret_key ] as $what => $value
        ) {
            if ( '' === $value ) {
                WsLog::l( 'S3 ' . $what . ' is not set.' );

                return false;
            }
        }

        $this->host = $bucket . '.s3.' . $region . '.amazonaws.com';
        $this->signer = new Signer( $access_key, $secret_key, $region, 's3' );
        $this->invalidate = [];

        $this->client = $this->client ?: new Client( [ 'http_errors' => false ] );

        return true;
    }

    protected function disconnect() : void {
        $this->invalidateCloudFront();
    }

    /**
     * @param string $local       Absolute local path.
     * @param string $destination Key in the bucket, prefix included.
     */
    protected function put( string $local, string $destination ) : bool {
        if ( ! is_readable( $local ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this plugin wrote.
        $body = (string) file_get_contents( $local );

        $headers = [ 'Content-Type' => MimeTypes::forPath( $local ) ];

        /*
         * Both optional, and empty by default rather than guessed.
         *
         * An ACL is refused outright by a bucket with Object Ownership
         * enforced, which is how every bucket made since 2023 starts — so
         * sending `public-read` uninvited would break the common case to serve
         * the old one. Cache-Control has no safe default either: what belongs
         * on a hashed asset and what belongs on a page are opposites.
         */
        $acl = Controller::getValue( 's3ObjectACL' );

        if ( '' !== $acl ) {
            $headers['x-amz-acl'] = $acl;
        }

        $cache_control = Controller::getValue( 's3CacheControl' );

        if ( '' !== $cache_control ) {
            $headers['Cache-Control'] = $cache_control;
        }

        $sent = $this->request( 'PUT', $destination, $headers, $body );

        if ( $sent ) {
            $this->invalidate[] = $destination;
        }

        return $sent;
    }

    protected function delete( string $destination ) : bool {
        $removed = $this->request( 'DELETE', $destination, [], '' );

        if ( $removed ) {
            $this->invalidate[] = $destination;
        }

        return $removed;
    }

    /**
     * S3 has no directories.
     *
     * What looks like one is a prefix on a key, and it stops existing when the
     * last key carrying it does. Answering false ends the base class's walk up
     * at once, which is the right answer rather than a stub: there is nothing
     * to remove.
     */
    protected function removeDirectory( string $destination ) : bool {
        return false;
    }

    /**
     * One signed request.
     *
     * @param array<string, string> $headers Headers to send and sign.
     */
    private function request( string $method, string $key, array $headers, string $body ) : bool {
        if ( null === $this->signer || null === $this->client ) {
            return false;
        }

        $path = Signer::encodePath( $key );

        try {
            $response = $this->client->request(
                $method,
                'https://' . $this->host . $path,
                [
                    'headers' => $this->signer->headers(
                        $method,
                        $this->host,
                        $path,
                        $headers,
                        $body
                    ),
                    'body' => $body,
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'S3 request failed for ' . $key . ': ' . $exception->getMessage() );

            return false;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            return true;
        }

        /*
         * The body, not just the status. S3 answers a refusal with an XML
         * document naming the reason — `SignatureDoesNotMatch`, `AccessDenied`,
         * `NoSuchBucket` — and those three need three different things done
         * about them. A bare 403 in the log needs a second deploy to find out
         * which.
         */
        WsLog::l(
            sprintf(
                'S3 answered %d for %s: %s',
                $status,
                $key,
                self::errorFrom( (string) $response->getBody() )
            )
        );

        return false;
    }

    /**
     * Ask CloudFront to forget the paths that changed.
     *
     * **The defect this replaces cost money.** The original compared
     * `$cf_max_paths >= count( $stale )` *before* adding a path, so with the
     * maximum unset — zero — the first file passed `0 >= 0` and no other did;
     * the count then ended at one, `1 > 0` was true, and it invalidated `/*`.
     * A full-distribution invalidation, on every deploy, for anyone who had
     * filled in a distribution ID and left the maximum alone. CloudFront gives
     * a thousand free paths a month and charges for the rest, and `/*` counts
     * as one path but throws away the entire cache.
     *
     * Here the maximum is a real ceiling with a real default, the comparison
     * happens after the list is complete, and `/*` is what you get when there
     * genuinely are more changes than it is worth listing.
     */
    private function invalidateCloudFront() : void {
        $distribution = Controller::getValue( 'cfDistributionID' );

        if ( '' === $distribution || ! $this->invalidate ) {
            return;
        }

        $paths = array_values( array_unique( $this->invalidate ) );
        $maximum = (int) Controller::getValue( 'cfMaxPathsToInvalidate' );
        $maximum = $maximum > 0 ? $maximum : 1000;

        if ( count( $paths ) > $maximum ) {
            WsLog::l(
                sprintf(
                    'CloudFront: %d paths changed, more than the %d allowed, so invalidating everything.',
                    count( $paths ),
                    $maximum
                )
            );

            $paths = [ '/*' ];
        }

        $body = self::invalidationBody( $paths );
        $path = '/' . self::CLOUDFRONT_API . '/distribution/' . $distribution . '/invalidation';

        /*
         * CloudFront's own credentials when they are set, the S3 ones
         * otherwise. The original add-on had the pair and somebody will have
         * filled them in — a separate IAM user for invalidations is unusual but
         * not wrong — and dropping the option would break their deploy without
         * saying why.
         */
        $access_key = Controller::getValue( 'cfAccessKeyID' );
        $secret_option = 'cfSecretAccessKey';

        if ( '' === $access_key ) {
            $access_key = Controller::getValue( 's3AccessKeyID' );
            $secret_option = 's3SecretAccessKey';
        }

        $secret_key = (string) \WP2Static\CoreOptions::encrypt_decrypt(
            'decrypt',
            Controller::getValue( $secret_option )
        );

        $signer = new Signer( $access_key, $secret_key, self::CLOUDFRONT_REGION, 'cloudfront' );

        if ( null === $this->client ) {
            return;
        }

        try {
            $response = $this->client->request(
                'POST',
                'https://' . self::CLOUDFRONT_HOST . $path,
                [
                    'headers' => $signer->headers(
                        'POST',
                        self::CLOUDFRONT_HOST,
                        $path,
                        [ 'Content-Type' => 'text/xml' ],
                        $body
                    ),
                    'body' => $body,
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'CloudFront invalidation failed: ' . $exception->getMessage() );

            return;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            WsLog::l( sprintf( 'CloudFront: %d path(s) invalidated.', count( $paths ) ) );

            return;
        }

        WsLog::l(
            sprintf(
                'CloudFront answered %d: %s',
                $status,
                self::errorFrom( (string) $response->getBody() )
            )
        );
    }

    /**
     * @param string[] $paths Keys to invalidate.
     */
    private static function invalidationBody( array $paths ) : string {
        $items = '';

        foreach ( $paths as $path ) {
            $items .= '<Path>' . htmlspecialchars( $path, ENT_XML1 ) . '</Path>';
        }

        /*
         * The caller reference has to differ between invalidations: CloudFront
         * treats a repeat of one it has seen as the same request and answers
         * with the earlier one, so a fixed string would mean the second deploy
         * of the day invalidated nothing.
         */
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/'
            . self::CLOUDFRONT_API . '/">'
            . '<Paths><Quantity>' . count( $paths ) . '</Quantity>'
            . '<Items>' . $items . '</Items></Paths>'
            . '<CallerReference>vibestatic-' . uniqid( '', true ) . '</CallerReference>'
            . '</InvalidationBatch>';
    }

    /**
     * The reason out of an AWS error document, or the status on its own.
     */
    private static function errorFrom( string $body ) : string {
        if ( '' === trim( $body ) ) {
            return 'no reason given';
        }

        if ( preg_match( '#<Code>([^<]+)</Code>#', $body, $match ) ) {
            $reason = $match[1];

            if ( preg_match( '#<Message>([^<]+)</Message>#', $body, $message ) ) {
                $reason .= ' — ' . $message[1];
            }

            return $reason;
        }

        return substr( $body, 0, 200 );
    }
}
