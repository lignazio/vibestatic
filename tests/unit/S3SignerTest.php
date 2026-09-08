<?php

namespace WP2StaticS3;

use WP_Mock\Tools\TestCase;

/**
 * The AWS Signature Version 4 implementation.
 *
 * **Where these expected values come from, because it matters.** They are not
 * what this code happened to produce on the day it was written: each was
 * checked against botocore — AWS's own Python implementation — signing the same
 * request with the same credentials at the same frozen instant, and each
 * matched byte for byte. Three cases, covering the three requests the deployer
 * actually makes: a PUT with a body and a content type, a DELETE with an empty
 * body and a percent-encoded key, and a CloudFront POST, which is a different
 * service and therefore a different signing scope.
 *
 * A signature that is wrong is wrong in a way that gives no clue —
 * `SignatureDoesNotMatch`, and nothing else — so pinning the values is worth
 * more here than in most places.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class S3SignerTest extends TestCase {

    const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private function signature( string $authorization ) : string {
        $parts = explode( 'Signature=', $authorization );

        return $parts[1] ?? '';
    }

    public function testItSignsAPutTheWayAWSDoes() : void {
        $signer = new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 's3' );

        $headers = $signer->headers(
            'PUT',
            'esempio.s3.us-east-1.amazonaws.com',
            '/cartella/pagina.html',
            [ 'Content-Type' => 'text/html' ],
            '<h1>ciao</h1>',
            1369353600
        );

        $this->assertSame(
            '15ff01b7353be6b87dbd85cb86b58586640d5d68a97ce926d104ba077c70b3a1',
            $this->signature( $headers['Authorization'] )
        );
    }

    /**
     * An empty body still has a hash, and it is still signed.
     */
    public function testItSignsADeleteWithAnEncodedKey() : void {
        $signer = new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'eu-south-1', 's3' );

        $headers = $signer->headers(
            'DELETE',
            'esempio.s3.eu-south-1.amazonaws.com',
            '/con%20spazio/x.txt',
            [],
            '',
            1700000000
        );

        $this->assertSame(
            '3de9d7388f49a99ff7aba22881e454a81c6a3acf634b2174f1445fb1fffc787f',
            $this->signature( $headers['Authorization'] )
        );
    }

    /**
     * CloudFront is a different service, so a different scope and a different
     * signature for otherwise identical inputs.
     */
    public function testItSignsACloudFrontRequest() : void {
        $signer = new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 'cloudfront' );

        $headers = $signer->headers(
            'POST',
            'cloudfront.amazonaws.com',
            '/2020-05-31/distribution/E123/invalidation',
            [ 'Content-Type' => 'text/xml' ],
            '<InvalidationBatch/>',
            1700000000
        );

        $this->assertSame(
            '1377d608399b540babf6e12c9fde415f7f8f3982031e96054cff9185d857e3fb',
            $this->signature( $headers['Authorization'] )
        );
    }

    /**
     * The payload hash header goes to S3 and to nothing else.
     *
     * S3 requires it; the other services do not ask for it, and the SDKs do not
     * send it. Sending it anyway would be accepted — extra signed headers are
     * allowed — but it is the kind of difference that makes a signature
     * mismatch harder to reason about when one appears.
     */
    public function testThePayloadHashIsSentToS3AndNotToCloudFront() : void {
        $s3 = ( new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 's3' ) )
            ->headers( 'PUT', 'h.example.com', '/a', [], 'x', 1700000000 );

        $cloudfront = ( new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 'cloudfront' ) )
            ->headers( 'POST', 'h.example.com', '/a', [], 'x', 1700000000 );

        $this->assertArrayHasKey( 'x-amz-content-sha256', $s3 );
        $this->assertArrayNotHasKey( 'x-amz-content-sha256', $cloudfront );
    }

    /**
     * The credential scope names the day, the region and the service, in that
     * order: it is what limits a leaked signature to one of each.
     */
    public function testTheCredentialScopeNarrowsTheSignature() : void {
        $headers = ( new Signer( self::ACCESS_KEY, self::SECRET_KEY, 'eu-south-1', 's3' ) )
            ->headers( 'PUT', 'h.example.com', '/a', [], '', 1700000000 );

        $this->assertStringContainsString(
            'Credential=' . self::ACCESS_KEY . '/20231114/eu-south-1/s3/aws4_request',
            $headers['Authorization']
        );
    }

    /**
     * A key goes into the path with its separators intact and its spaces
     * percent-encoded — `%20`, not `+`, which is the form for a query string
     * and means a literal plus inside a path.
     */
    public function testItEncodesAKeyForAPathAndNotForAQueryString() : void {
        $this->assertSame( '/blog/2019/x.html', Signer::encodePath( '/blog/2019/x.html' ) );
        $this->assertSame( '/con%20spazio/x.html', Signer::encodePath( '/con spazio/x.html' ) );
        $this->assertStringNotContainsString( '+', Signer::encodePath( '/con spazio/x.html' ) );
    }
}
