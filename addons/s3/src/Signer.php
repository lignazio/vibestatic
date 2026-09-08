<?php
/**
 * AWS Signature Version 4.
 *
 * **Why this is here rather than an SDK.** The add-on this replaces pulled in
 * `aws/aws-sdk-php`: twenty-four megabytes, seven and a half once the unused
 * services are stripped, shipped to every installation so that a minority could
 * deploy to S3. What the deployer actually asks of it is three signed requests
 * — put an object, delete an object, ask CloudFront to forget a path — and a
 * signature is a hundred lines of hashing. So it is a hundred lines here, and
 * the plugin keeps its one production dependency.
 *
 * The procedure is AWS's, unchanged and not improvised: a canonical request, a
 * string to sign, a signing key derived one step at a time, and an
 * Authorization header. Each step is separated out below because each is a
 * place to be wrong in a way that produces the same unhelpful answer —
 * `SignatureDoesNotMatch` — and being able to compare one step at a time is
 * what makes that debuggable.
 *
 * @package WP2StaticS3
 */

namespace WP2StaticS3;

class Signer {

    const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * @var string
     */
    private $access_key;

    /**
     * @var string
     */
    private $secret_key;

    /**
     * @var string
     */
    private $region;

    /**
     * @var string
     */
    private $service;

    public function __construct(
        string $access_key,
        string $secret_key,
        string $region,
        string $service
    ) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region = $region;
        $this->service = $service;
    }

    /**
     * The headers a request needs to be accepted, the Authorization among them.
     *
     * @param string                $method  GET, PUT, DELETE, POST.
     * @param string                $host    The host the request goes to.
     * @param string                $path    Absolute path, already encoded.
     * @param array<string, string> $headers Headers the caller wants signed.
     * @param string                $payload The body, empty for a GET.
     * @param int|null              $now     Timestamp; null for the real one.
     * @return array<string, string> Headers to send, the caller's included.
     */
    public function headers(
        string $method,
        string $host,
        string $path,
        array $headers,
        string $payload,
        ?int $now = null
    ) : array {
        $now = $now ?? time();
        $amz_date = gmdate( 'Ymd\THis\Z', $now );
        $datestamp = gmdate( 'Ymd', $now );
        $payload_hash = hash( 'sha256', $payload );

        $headers = array_merge(
            $headers,
            [
                'Host' => $host,
                'x-amz-date' => $amz_date,
            ]
        );

        /*
         * `x-amz-content-sha256` for S3, and only for S3. It is required there
         * even when the body is empty — the service checks it against the hash
         * in the canonical request — and it is not sent to the others, which is
         * what the SDKs do: an extra signed header is accepted, but a request
         * that carries one the service never asked for is a request that reads
         * as though somebody guessed.
         */
        if ( 's3' === $this->service ) {
            $headers['x-amz-content-sha256'] = $payload_hash;
        }

        $canonical_headers = '';
        $signed_headers = [];

        $lowercased = [];

        foreach ( $headers as $name => $value ) {
            $lowercased[ strtolower( $name ) ] = trim( $value );
        }

        ksort( $lowercased );

        foreach ( $lowercased as $name => $value ) {
            $canonical_headers .= $name . ':' . $value . "\n";
            $signed_headers[] = $name;
        }

        $signed = implode( ';', $signed_headers );

        $canonical_request = implode(
            "\n",
            [
                $method,
                $path,
                // No query string is used by any of the three requests. It is
                // still a field, and an empty one is a blank line, not a
                // missing line.
                '',
                $canonical_headers,
                $signed,
                $payload_hash,
            ]
        );

        $scope = $datestamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $string_to_sign = implode(
            "\n",
            [
                self::ALGORITHM,
                $amz_date,
                $scope,
                hash( 'sha256', $canonical_request ),
            ]
        );

        $signature = hash_hmac(
            'sha256',
            $string_to_sign,
            $this->signingKey( $datestamp )
        );

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->access_key,
            $scope,
            $signed,
            $signature
        );

        return $headers;
    }

    /**
     * The signing key, derived one narrowing step at a time.
     *
     * Each step binds the key to one more thing — the day, the region, the
     * service — so a key that leaks is worth one service in one region for one
     * day. `true` for raw output on every step but the last: they are keys, not
     * text.
     */
    private function signingKey( string $datestamp ) : string {
        $key = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
        $key = hash_hmac( 'sha256', $this->region, $key, true );
        $key = hash_hmac( 'sha256', $this->service, $key, true );

        return hash_hmac( 'sha256', 'aws4_request', $key, true );
    }

    /**
     * A key as it goes into a URL path.
     *
     * Each segment on its own, so the separators survive; rawurlencode because
     * a space in an object key is `%20` and not `+`. S3 signs the path exactly
     * as it is sent — single-encoded, unlike every other AWS service, which
     * encodes it twice.
     */
    public static function encodePath( string $path ) : string {
        return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
    }
}
