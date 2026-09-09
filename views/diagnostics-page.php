<?php
/**
 * @package WP2Static

 */

namespace WP2Static;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string, mixed> $view */

$yes_no = function ( bool $ok ) : void {
    ?>
    <span
        class="dashicons <?php echo esc_attr( $ok ? 'dashicons-yes' : 'dashicons-no' ); ?>"
        style="color: <?php echo esc_attr( $ok ? 'green' : 'red' ); ?>;"
        aria-hidden="true"
    ></span>
    <span class="screen-reader-text">
        <?php echo $ok ? esc_html__( 'OK', 'vibestatic' ) : esc_html__( 'Needs attention', 'vibestatic' ); ?>
    </span>
    <?php
};

/** @var int $max_execution_time */
$max_execution_time = $view['maxExecutionTime'];

/** @var bool $uploads_writable */
$uploads_writable = $view['uploadsWritable'];

/** @var bool $curl_supported */
$curl_supported = $view['curlSupported'];

/** @var bool $permalinks_ok */
$permalinks_ok = $view['permalinksAreCompatible'];

/** @var bool $php_out_of_date */
$php_out_of_date = $view['phpOutOfDate'];

/** @var string $memory_limit */
$memory_limit = $view['memoryLimit'];

/** @var string[] $extensions */
$extensions = $view['extensions'];

/** @var list<object{label: string, value: string, type: string}> $core_options */
$core_options = $view['coreOptions'];

/** @var array<string, string> $site_info */
$site_info = $view['site_info'];

?>

<div class="wrap">
    <?php
    /*
     * The page's own title, asked of WordPress rather than written again here.
     *
     * An admin page is expected to carry exactly one h1 inside `.wrap`: it is
     * what a screen reader announces on arrival, and what WordPress hangs
     * `.wp-header-end` off when it decides where to put admin notices. Every
     * view in this plugin but two opened with a `<br>` instead.
     *
     * get_admin_page_title() returns what add_submenu_page() was given, so the
     * heading cannot drift from the menu entry — and for the pages that have no
     * menu entry, Controller::setHiddenPageTitle() has already filled it in.
     */
    ?>
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Health check', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Status', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Advice', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>PHP max_execution_time</td>
                <td>
                    <?php
                    if ( 0 === $max_execution_time ) {
                        esc_html_e( 'Unlimited', 'vibestatic' );
                    } else {
                        printf(
                            esc_html(
                                /* translators: %s: a number of seconds. */
                                _n( '%s second', '%s seconds', $max_execution_time, 'vibestatic' )
                            ),
                            esc_html( number_format_i18n( $max_execution_time ) )
                        );
                    }

                    $yes_no( 0 === $max_execution_time );
                    ?>
                </td>
                <td><?php esc_html_e( 'Generating a static site can involve long-running processes. Set your PHP max_execution_time to unlimited, or find a better webhost if you are prevented from doing so.', 'vibestatic' ); ?></td>
            </tr>
            <tr>
                <td>PHP memory_limit</td>
                <td><?php echo esc_html( $memory_limit ); ?></td>
                <td><?php esc_html_e( 'VibeStatic will use as much memory as is available to it during processing. Allocating more of your system RAM to PHP should improve performance.', 'vibestatic' ); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Uploads directory writable', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    echo $uploads_writable
                        ? esc_html__( 'Writable', 'vibestatic' )
                        : esc_html__( 'Non-writable', 'vibestatic' );

                    $yes_no( $uploads_writable );
                    ?>
                </td>
                <td><?php esc_html_e( 'By default VibeStatic writes the generated static site under the wp-content/uploads directory. Make sure VibeStatic has permission to do so.', 'vibestatic' ); ?></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'PHP version', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    echo esc_html( PHP_VERSION );

                    $yes_no( ! $php_out_of_date );
                    ?>
                </td>
                <td>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: a link whose text is "PHP.net". */
                            esc_html__( 'The currently supported PHP versions are listed on %s.', 'vibestatic' ),
                            '<a href="https://www.php.net/supported-versions.php" target="_blank" rel="noopener">PHP.net</a>'
                        );
                        ?>
                    </p>

                    <p><?php esc_html_e( 'VibeStatic requires PHP 8.2 or newer. It is tested on 8.2, 8.3 and 8.4.', 'vibestatic' ); ?></p>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'cURL extension loaded', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    echo $curl_supported
                        ? esc_html__( 'Yes', 'vibestatic' )
                        : esc_html__( 'No', 'vibestatic' );

                    $yes_no( $curl_supported );
                    ?>
                </td>
                <td>
                    <p><?php esc_html_e( 'VibeStatic needs the cURL extension enabled on your web server to crawl your site and to deploy it to services such as S3, BunnyCDN or Netlify.', 'vibestatic' ); ?></p>

                    <p>
                        <?php
                        printf(
                            /* translators: %s: a link whose text is "report it on GitHub". */
                            esc_html__( 'Enabling it is usually a matter of one line in your PHP configuration, or one click in your host control panel. If you are stuck, %s.', 'vibestatic' ),
                            '<a href="https://github.com/lignazio/vibestatic/issues" target="_blank" rel="noopener">' .
                                esc_html__( 'report it on GitHub', 'vibestatic' ) .
                            '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'WordPress permalinks compatible', 'vibestatic' ); ?></td>
                <td>
                    <?php
                    echo $permalinks_ok
                        ? esc_html__( 'Yes', 'vibestatic' )
                        : esc_html__( 'No', 'vibestatic' );

                    $yes_no( $permalinks_ok );
                    ?>
                </td>
                <td>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: a link whose text is "Permalink Settings". */
                            esc_html__( 'Because of how static sites work, you need a permalink structure defined in your %s, and it must end in a trailing slash.', 'vibestatic' ),
                            '<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' .
                                esc_html__( 'Permalink Settings', 'vibestatic' ) .
                            '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </tbody>
    </table>


    <h4><?php esc_html_e( 'Loaded PHP extensions', 'vibestatic' ); ?></h4>

    <table class="widefat striped">
        <tbody>
    <?php
    natcasesort( $extensions );
    $rows = (int) ceil( count( $extensions ) / 5 );

    if ( $rows < 1 ) {
        echo '<tr><td>' . esc_html__( 'No extensions loaded.', 'vibestatic' ) . '</td></tr>';
    } else {
        foreach ( array_chunk( $extensions, $rows ) as $column ) {
            echo '<tr>';
            foreach ( $column as $item ) {
                echo '<td>' . esc_html( strval( $item ) ) . '</td>';
            }
            echo '</tr>';
        }
    }
    ?>
        </tbody>
    </table>

    <h4><?php esc_html_e( 'VibeStatic Core Options', 'vibestatic' ); ?></h4>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Value', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $core_options as $option ) : ?>
            <tr>
                <td><?php echo esc_html( $option->label ); ?></td>
                <td>
                    <?php
                    /*
                     * A password is never printed here. This page is the one
                     * people screenshot and attach to a support request — the
                     * issue template sends them to it — and `CoreOptions::getAll()`
                     * hands back password options already decrypted, so the HTTP
                     * basic auth password of a staging site was being displayed
                     * in the clear to anyone who could see the screen.
                     *
                     * What this page needs to answer is "is it configured", not
                     * "what is it". `wp vibestatic options get` still returns the
                     * value to someone who asks for that one option on purpose.
                     */
                    if ( 'password' === $option->type ) {
                        echo '' === $option->value
                            ? '<em>' . esc_html__( 'not set', 'vibestatic' ) . '</em>'
                            : esc_html__( 'set (hidden)', 'vibestatic' );
                    } else {
                        echo esc_html( $option->value );
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4><?php esc_html_e( 'WordPress Site Info', 'vibestatic' ); ?></h4>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'vibestatic' ); ?></th>
                <th><?php esc_html_e( 'Value', 'vibestatic' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $site_info as $name => $value ) : ?>
            <tr>
                <td><?php echo esc_html( $name ); ?></td>
                <td><?php echo esc_html( $value ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
