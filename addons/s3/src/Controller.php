<?php
/**
 * S3 — bundled module.
 *
 * **No AWS SDK.** The add-on this replaces required `aws/aws-sdk-php`: twenty-
 * four megabytes, seven and a half once the unused services are stripped, and
 * the reason the roadmap had this one staying outside the plugin as a separate
 * install. What the deployer asks of it is three signed requests, and a
 * signature is a hundred lines — so it is a hundred lines, in Signer.
 *
 * @package WP2StaticS3
 */

namespace WP2StaticS3;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The slug this module is known by, in the add-ons table and in the value
     * the core passes to `wp2static_deploy`.
     *
     * It stays `wp2static-addon-s3`: it keys the row in the add-ons table, the
     * options table's name and the deploy-cache namespace.
     */
    const SLUG = 'wp2static-addon-s3';

    const TABLE = 'wp2static_addon_s3_options';

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return self::SLUG;
    }

    public function name() : string {
        return 'S3';
    }

    public function description() : string {
        return 'Uploads the generated site to Amazon S3, and tells CloudFront what changed';
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic#s3';
    }

    /**
     * The option names are the original add-on's, and that is deliberate: the
     * options table has the same name too, so an installation that had the old
     * add-on keeps its bucket, its region and its credentials instead of
     * finding the module unconfigured.
     *
     * `cfMaxPathsToInvalidate` has a real default rather than zero, and that is
     * the fix for the defect that cost money: with it unset the original ended
     * up invalidating `/*` — the whole distribution — on every single deploy.
     *
     * `cfAccessKeyID` and `cfSecretAccessKey` are optional and fall back to the
     * S3 ones. They exist because the original had them: a separate IAM user
     * for CloudFront is unusual, but somebody configured one, and taking the
     * option away would break their deploy without saying so.
     */
    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                self::TABLE,
                [
                    's3Bucket' => [ 'string', '' ],
                    's3Region' => [ 'string', 'us-east-1' ],
                    's3RemotePath' => [ 'string', '' ],
                    's3AccessKeyID' => [ 'string', '' ],
                    's3SecretAccessKey' => [ 'password', '' ],
                    's3ObjectACL' => [ 'string', '' ],
                    's3CacheControl' => [ 'string', '' ],
                    'cfDistributionID' => [ 'string', '' ],
                    'cfMaxPathsToInvalidate' => [ 'int', '1000' ],
                    'cfAccessKeyID' => [ 'string', '' ],
                    'cfSecretAccessKey' => [ 'password', '' ],
                ]
            );
        }

        return $this->options;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function fields() : array {
        return [
            's3Bucket' => [ __( 'Bucket', 'vibestatic' ), '' ],
            's3Region' => [
                __( 'Region', 'vibestatic' ),
                __(
                    'The region the bucket is in, for example eu-south-1. It is part of the signature, so a wrong one is refused rather than redirected.',
                    'vibestatic'
                ),
            ],
            's3RemotePath' => [
                __( 'Path in the bucket', 'vibestatic' ),
                __( 'A folder inside the bucket. Leave empty to publish at its root.', 'vibestatic' ),
            ],
            's3AccessKeyID' => [ __( 'Access key ID', 'vibestatic' ), '' ],
            's3SecretAccessKey' => [
                __( 'Secret access key', 'vibestatic' ),
                __( 'Stored encrypted. Leave blank to keep the saved one.', 'vibestatic' ),
            ],
            's3ObjectACL' => [
                __( 'Object ACL', 'vibestatic' ),
                __(
                    'Leave empty unless the bucket needs one. A bucket created since 2023 has ACLs disabled and refuses a request that carries one; older buckets serving a public site want public-read.',
                    'vibestatic'
                ),
            ],
            's3CacheControl' => [
                __( 'Cache-Control', 'vibestatic' ),
                __(
                    'Optional, sent with every object, for example max-age=3600. There is no safe default: what suits a hashed asset is the opposite of what suits a page.',
                    'vibestatic'
                ),
            ],
            'cfDistributionID' => [
                __( 'CloudFront distribution ID', 'vibestatic' ),
                __(
                    'Optional. With one set, the paths that changed are invalidated after each deploy.',
                    'vibestatic'
                ),
            ],
            'cfMaxPathsToInvalidate' => [
                __( 'Most paths to invalidate', 'vibestatic' ),
                __(
                    'Above this many changes it invalidates everything instead, which is one request rather than thousands. CloudFront gives a thousand paths a month free and charges beyond that.',
                    'vibestatic'
                ),
            ],
            'cfAccessKeyID' => [
                __( 'CloudFront access key ID', 'vibestatic' ),
                __( 'Optional. Leave empty to invalidate with the S3 credentials above.', 'vibestatic' ),
            ],
            'cfSecretAccessKey' => [
                __( 'CloudFront secret access key', 'vibestatic' ),
                __( 'Optional, stored encrypted. Leave blank to keep the saved one.', 'vibestatic' ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'The credentials need only s3:PutObject and s3:DeleteObject on the bucket, plus cloudfront:CreateInvalidation if a distribution is set. An account with more than that is an account that can do more than publish a site.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'wp2static s3', [ CLI::class, 's3' ] );
        }
    }

    protected function runDeploy( string $processed_site_path ) : void {
        \WP2Static\WsLog::l( 'S3 Addon deploying' );

        ( new Deployer() )->deploy( $processed_site_path );
    }

    /**
     * Create and seed this module's options table.
     *
     * Called by WP2Static\Modules::installTables(), from Schema::install().
     */
    public static function installTables() : void {
        self::instance()->options()->install();
    }
}
