<?php
/**
 * Updates for anyone who installs the plugin from a zip.
 *
 * Without this, whoever does not use Composer receives not even a security
 * patch: the `Update URI` header tells WordPress NOT to look for updates on
 * wordpress.org — rightly, because the `vibestatic` slug is not ours over there
 * — but points it at nothing else. The plugin would stay on the version it was
 * downloaded as, forever, and say nothing about it.
 *
 * There is no library here. Since WordPress 5.8 the `update_plugins_<hostname>`
 * filter does exactly this, and it is the native mechanism:
 * `plugin-update-checker` would solve the same problem while adding a
 * production dependency, and there is currently exactly one (Guzzle) — a number
 * worth defending. WordPress compares the versions itself: this class always
 * returns the latest release, not "the update if one is needed".
 *
 * @package WP2Static
 */

namespace WP2Static;

class Updater {

    /**
     * @var string Where GitHub's answer is remembered.
     */
    const TRANSIENT = 'vibestatic_latest_release';

    /**
     * @var int How long a good answer is kept.
     */
    const TTL_OK = 12 * HOUR_IN_SECONDS;

    /**
     * @var int How long a miss is kept.
     *
     * Failure is remembered too, and that is half the work: without it, a
     * repository that is not there, or GitHub's rate limit — sixty requests an
     * hour per IP address, unauthenticated — would restart the call on every
     * update check.
     */
    const TTL_FAIL = HOUR_IN_SECONDS;

    public static function registerHooks() : void {
        $host = wp_parse_url( self::updateUri(), PHP_URL_HOST );

        if ( ! is_string( $host ) || '' === $host ) {
            return;
        }

        add_filter( "update_plugins_$host", [ self::class, 'checkForUpdate' ], 10, 3 );
        add_filter( 'plugins_api', [ self::class, 'pluginInformation' ], 10, 3 );
    }

    /**
     * The URI declared in the plugin header, which is also the update's `id` as
     * far as WordPress is concerned.
     */
    private static function updateUri() : string {
        return 'https://github.com/lignazio/vibestatic';
    }

    private static function slug() : string {
        return 'vibestatic';
    }

    /**
     * Answer WordPress for OUR plugin and for nobody else's.
     *
     * The filter name contains only the host name, so
     * `update_plugins_github.com` is shared by every installed plugin with an
     * `Update URI` on GitHub. A callback that answered for all of them would
     * redirect other people's updates to our releases. Hence the comparison
     * against the declared `UpdateURI`, and `$update` returned untouched when
     * it is none of our business.
     *
     * @param array<string, mixed>|false $update      Whatever came before us.
     * @param array<string, string>      $plugin_data Headers of the plugin being asked about.
     * @param string                     $plugin_file Its main file.
     * @return array<string, mixed>|false
     */
    public static function checkForUpdate( $update, array $plugin_data, string $plugin_file ) {
        if ( ! isset( $plugin_data['UpdateURI'] )
            || untrailingslashit( $plugin_data['UpdateURI'] ) !== self::updateUri()
        ) {
            return $update;
        }

        $release = self::latestRelease();

        if ( ! $release ) {
            return $update;
        }

        return [
            'id' => self::updateUri(),
            'slug' => self::slug(),
            'plugin' => $plugin_file,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '8.2',
            'tested' => $release['tested'],
        ];
    }

    /**
     * Fill in the "View version details" panel.
     *
     * Without it, that link opens a modal which asks wordpress.org about a slug
     * that does not exist there, and shows an error: the update itself would
     * work, but the one thing the user can click before installing it would not.
     *
     * @param object|array<string,mixed>|false $result Whatever came before us.
     * @param string                           $action What WordPress is asking for.
     * @param object                           $args   Arguments, including the slug.
     * @return object|array<string,mixed>|false
     */
    public static function pluginInformation( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== self::slug() ) {
            return $result;
        }

        $release = self::latestRelease();

        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name' => 'VibeStatic',
            'slug' => self::slug(),
            'version' => $release['version'],
            'author' => '<a href="https://lucenti.studio">Ignazio Lucenti</a>',
            'homepage' => self::updateUri(),
            'requires' => '6.5',
            'requires_php' => '8.2',
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'changelog' => $release['notes'],
            ],
        ];
    }

    /**
     * The latest published release, or null.
     *
     * @return array{version: string, url: string, package: string, tested: string, notes: string}|null
     */
    private static function latestRelease() : ?array {
        $cached = get_transient( self::TRANSIENT );

        if ( is_array( $cached ) ) {
            /** @var array{version: string, url: string, package: string, tested: string, notes: string} $cached */
            return $cached;
        }

        if ( 'none' === $cached ) {
            return null;
        }

        $release = self::fetchLatestRelease();

        if ( ! $release ) {
            set_transient( self::TRANSIENT, 'none', self::TTL_FAIL );
            return null;
        }

        set_transient( self::TRANSIENT, $release, self::TTL_OK );

        return $release;
    }

    /**
     * @return array{version: string, url: string, package: string, tested: string, notes: string}|null
     */
    private static function fetchLatestRelease() : ?array {
        $path = (string) wp_parse_url( self::updateUri(), PHP_URL_PATH );

        $response = wp_remote_get(
            'https://api.github.com/repos' . untrailingslashit( $path ) . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) || ! is_string( $body['tag_name'] ) ) {
            return null;
        }

        $package = self::zipAssetUrl( $body );

        /*
         * No asset, no update — and no falling back on the zipball GitHub
         * generates itself. That one unpacks into a directory named
         * `lignazio-vibestatic-<sha>`: WordPress would install the plugin in
         * there, next to the real one, leaving the user with two copies and no
         * update. The zip built by `tools/build_release.sh` has `vibestatic/`
         * at the top instead, and is the only thing that can be handed to the
         * installer.
         */
        if ( ! $package ) {
            return null;
        }

        return [
            'version' => ltrim( $body['tag_name'], 'v' ),
            'url' => isset( $body['html_url'] ) && is_string( $body['html_url'] )
                ? $body['html_url']
                : self::updateUri(),
            'package' => $package,
            'tested' => self::testedUpTo(),
            'notes' => isset( $body['body'] ) && is_string( $body['body'] )
                ? wp_kses_post( nl2br( esc_html( $body['body'] ) ) )
                : '',
        ];
    }

    /**
     * The URL of the zip attached to the release.
     *
     * The keys are `mixed` rather than `string` because that is what
     * `json_decode( …, true )` promises; this method reads exactly one of them
     * and needs to know nothing more.
     *
     * @param array<mixed, mixed> $release The release as GitHub returned it.
     */
    private static function zipAssetUrl( array $release ) : ?string {
        if ( ! isset( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
            return null;
        }

        foreach ( $release['assets'] as $asset ) {
            if ( ! is_array( $asset )
                || ! isset( $asset['browser_download_url'] )
                || ! is_string( $asset['browser_download_url'] )
            ) {
                continue;
            }

            if ( str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * `Tested up to`, read from readme.txt, which is where it is already
     * updated on every release: repeating it here would mean keeping two in
     * step.
     */
    private static function testedUpTo() : string {
        if ( ! defined( 'VIBESTATIC_PATH' ) ) {
            return '';
        }

        $readme = VIBESTATIC_PATH . 'readme.txt';

        if ( ! is_readable( $readme ) ) {
            return '';
        }

        $contents = (string) file_get_contents( $readme );

        if ( preg_match( '/^Tested up to:\s*(.+)$/mi', $contents, $matches ) ) {
            return trim( $matches[1] );
        }

        return '';
    }
}
