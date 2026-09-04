<?php

namespace WP2Static;

use Exception;

class URLHelper {
    /**
     * Legge una variabile di $_SERVER.
     *
     * Non usa filter_input( INPUT_SERVER, ... ): sotto PHP-FPM, sul server
     * integrato di PHP e in WP-Cron quella funzione legge l'array originale
     * della richiesta, che spesso è vuoto, e restituisce null anche quando
     * $_SERVER ha il valore. Il risultato era che getCurrent() costruiva
     * l'URL "http://" e modifyUrl() lo rifiutava con "Unable to parse URL",
     * cioè una schermata bianca sulle pagine Crawl Queue e Crawl Cache.
     */
    private static function server( string $key ) : string {
        if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
            return '';
        }

        return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
    }

    public static function isSecure() : bool {
        // is_ssl() di WordPress copre anche i proxy davanti al sito, che
        // parlano http all'origine e https al mondo.
        if ( function_exists( 'is_ssl' ) && is_ssl() ) {
            return true;
        }

        $https = self::server( 'HTTPS' );

        return ( $https !== '' && strtolower( $https ) !== 'off' ) ||
            self::server( 'SERVER_PORT' ) === '443';
    }

    /*
     * Returns the current full URL including querystring
     *
     * @return string
     */
    public static function getCurrent() : string {
        $scheme = self::isSecure() ? 'https' : 'http';
        $host = self::server( 'HTTP_HOST' );

        if ( $host === '' ) {
            // Nessun host nella richiesta: WP-Cron, WP-CLI, un test. L'URL del
            // sito è la risposta giusta, e comunque è parsabile.
            return function_exists( 'home_url' ) ? strval( home_url( '/' ) ) : '';
        }

        $url = $scheme . '://' . $host;

        // Only include port number if needed
        $port = self::server( 'SERVER_PORT' );

        // L'host può già portare la porta con sé, come 'localhost:8080'.
        if ( $port !== '' && ! in_array( $port, [ '80', '443' ], true ) &&
            ! str_contains( $host, ':' ) ) {
            $url .= ':' . $port;
        }

        $request_uri = self::server( 'REQUEST_URI' );

        return $url . ( $request_uri !== '' ? $request_uri : '/' );
    }

    /**
     * Returns a URL with given querystring modifications
     *
     * @param array<string|int> $changes  List of querystring params to set
     * @param string $url             A complete URL. Leave empty to use current URL
     * @return string                 The new URL
     * @throws WP2StaticException
     */
    public static function modifyUrl( array $changes, string $url = '' ) : string {
        // If $url wasn't passed in, use the current url
        if ( $url === '' ) {
            $url = self::getCurrent();
        }

        // Parse the url into pieces
        $url_array = (array) parse_url( $url );

        // The original URL had a query string, modify it.
        if ( array_key_exists( 'query', $url_array ) ) {
            parse_str( $url_array['query'], $query_array );
            foreach ( $changes as $key => $value ) {
                $query_array[ $key ] = $value;
            }
        } else {
            // The original URL didn't have a query string, add it.
            $query_array = $changes;
        }

        if (
            ! isset( $url_array['scheme'] ) ||
            ! isset( $url_array['host'] ) ||
            ! isset( $url_array['path'] )
        ) {
            throw new WP2StaticException( 'Unable to parse URL' );
        }

        return $url_array['scheme'] . '://' .
            $url_array['host'] . $url_array['path'] . '?' .
            http_build_query( $query_array );
    }

    /*
     * Takes either an http or https URL and returns a // protocol-relative URL
     *
     * @param string URL either http or https
     * @return string URL protocol-relative
     */
    public static function getProtocolRelativeURL( string $url ) : string {
        $protocol_relative_url = str_replace(
            [
                'https:',
                'http:',
            ],
            [
                '',
                '',
            ],
            $url
        );

        return $protocol_relative_url;
    }

    public static function startsWithHash( string $url ) : bool {
        // TODO: this won't fire for absolute URLs unless strip site_url first?
        // quickly abort for invalid URLs
        if ( $url[0] === '#' ) {
            return true;
        }

        return false;
    }

    public static function isMailto( string $url ) : bool {
        if ( substr( $url, 0, 7 ) == 'mailto:' ) {
            return true;
        }

        return false;
    }

    public static function isProtocolRelative( string $url ) : bool {
        if ( $url[0] === '/' ) {
            if ( $url[1] === '/' ) {
                return true;
            }
        }

        return false;
    }

    public static function protocolRelativeToAbsoluteURL(
        string $url,
        string $site_url
    ) : string {

        $url = str_replace(
            self::getProtocolRelativeURL( $site_url ),
            $site_url,
            $url
        );

        return $url;
    }

    /*
     * Detect if a URL belongs to our WP site
     * We check against known internal prefixes and WP site host
     *
     */
    public static function isInternalLink(
        string $url,
        string $site_url_host
    ) : bool {
        // quickly match known internal links   ./   ../   /
        $first_char = $url[0];

        // TODO: // was false-positive for things like //fonts.google.com
        // add better detection for doc/site root relative protocol-rel URLs
        if ( $first_char === '.' ) {
            return true;
        }

        // site root relative URLs, like /alink
        if ( $url[0] === '/' ) {
            if ( $url[1] !== '/' ) {
                return true;
            }
        }

        $url_host = parse_url( $url, PHP_URL_HOST );

        if ( $url_host === $site_url_host ) {
            return true;
        }

        return false;
    }
}
