<?php
/**
 * S3 — bundled module.
 *
 * **No AWS SDK.** The add-on this replaces required `aws/aws-sdk-php`: twenty-
 * four megabytes, seven and a half once the unused services are stripped, and
 * the reason the roadmap had this one staying outside the plugin as a separate
 * install. What the deployer asks of it is three signed requests, and a
 * signature is a hundred lines — so it is a hundred lines, in Signer, and this
 * is a module like the others with nothing added to the release zip.
 *
 * The slug stays `wp2static-addon-s3`: it keys the row in the add-ons table,
 * the options table's name and the deploy-cache namespace.
 *
 * @package WP2StaticS3
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WP2STATIC_S3_PATH', __DIR__ . '/' );
define( 'WP2STATIC_S3_VERSION', VIBESTATIC_VERSION );

require_once WP2STATIC_S3_PATH . 'autoload.php';

WP2Static\Modules::registerInstaller(
    'wp2static-addon-s3',
    [ 'WP2StaticS3\Controller', 'installTables' ]
);

( new WP2StaticS3\Controller() )->run();
