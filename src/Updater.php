<?php
/**
 * Aggiornamenti per chi installa il plugin da zip.
 *
 * Senza questo, chi non usa Composer non riceve nemmeno una patch di sicurezza:
 * l'header `Update URI` dice a WordPress di NON cercare aggiornamenti su
 * wordpress.org — cosa giusta, perche' li' lo slug `vibestatic` non e' nostro —
 * ma non gliene indica altri. Il plugin resterebbe fermo alla versione con cui
 * e' stato scaricato, per sempre e senza dirlo.
 *
 * Non c'e' una libreria. Da WordPress 5.8 il filtro `update_plugins_<hostname>`
 * fa esattamente questo, ed e' il meccanismo nativo: `plugin-update-checker`
 * risolverebbe lo stesso problema portandosi dietro una dipendenza di
 * produzione, che oggi e' una sola (Guzzle) ed e' un numero che vale la pena
 * difendere. Il confronto fra le versioni lo fa WordPress da se`: qui si
 * restituisce sempre l'ultima release, non «l'aggiornamento se serve».
 *
 * @package WP2Static
 */

namespace WP2Static;

class Updater {

    /**
     * @var string Dove si ricorda la risposta di GitHub.
     */
    const TRANSIENT = 'vibestatic_latest_release';

    /**
     * @var int Quanto si tiene una risposta buona.
     */
    const TTL_OK = 12 * HOUR_IN_SECONDS;

    /**
     * @var int Quanto si tiene un buco nell'acqua.
     *
     * Anche il fallimento si ricorda, ed e' la meta' del lavoro: senza,
     * un repository che non c'e' o un rate limit di GitHub — sessanta
     * richieste all'ora per indirizzo IP, senza autenticazione — fanno
     * ripartire la chiamata a ogni controllo degli aggiornamenti.
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
     * L'URI dichiarato nell'header del plugin, che e' anche l'`id`
     * dell'aggiornamento per WordPress.
     */
    private static function updateUri() : string {
        return 'https://github.com/lignazio/vibestatic';
    }

    private static function slug() : string {
        return 'vibestatic';
    }

    /**
     * Risponde a WordPress per il NOSTRO plugin e per nessun altro.
     *
     * Il nome del filtro contiene solo il nome dell'host, quindi
     * `update_plugins_github.com` e' condiviso da ogni plugin installato che
     * abbia un `Update URI` su GitHub. Un callback che rispondesse a tutti
     * dirotterebbe gli aggiornamenti altrui verso le nostre release. Da qui il
     * confronto sull'`UpdateURI` dichiarato, e il `$update` restituito intatto
     * quando non e' affare nostro.
     *
     * @param array<string, mixed>|false $update      Quello che ha detto chi viene prima.
     * @param array<string, string>      $plugin_data Header del plugin interrogato.
     * @param string                     $plugin_file Il suo file principale.
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
     * Riempie la finestra «Visualizza i dettagli della versione».
     *
     * Senza, quel link apre una modale che interroga wordpress.org per uno slug
     * che li' non esiste, e mostra un errore: l'aggiornamento funzionerebbe, ma
     * l'unica cosa che l'utente puo' cliccare prima di installarlo no.
     *
     * @param object|array<string,mixed>|false $result Quello che ha detto chi viene prima.
     * @param string                           $action Cosa sta chiedendo WordPress.
     * @param object                           $args   Argomenti, fra cui lo slug.
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
     * L'ultima release pubblicata, o null.
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
         * Nessun asset, nessun aggiornamento — e non si ripiega sullo zipball
         * che GitHub genera da se`. Quello si scompatta in una cartella che si
         * chiama `lignazio-vibestatic-<sha>`: WordPress installerebbe il plugin
         * li' dentro, accanto a quello vero, e l'utente si ritroverebbe due
         * copie e nessun aggiornamento. Lo zip costruito da
         * `tools/build_release.sh` ha invece `vibestatic/` in cima, ed e'
         * l'unica cosa che si puo' consegnare all'installer.
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
     * L'URL dello zip allegato alla release.
     *
     * Le chiavi sono `mixed` e non `string` perche' e' quello che
     * `json_decode( …, true )` promette; il metodo ne legge una sola e non ha
     * bisogno di sapere altro.
     *
     * @param array<mixed, mixed> $release La release come l'ha data GitHub.
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
     * `Tested up to` letto da readme.txt, che e' dove si aggiorna gia' a ogni
     * release: ripeterlo qui vorrebbe dire tenerne allineati due.
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
