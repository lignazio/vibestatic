<?php
/**
 * Every documentation link the plugin shows, asked whether it exists.
 *
 *     php tools/check_links.php
 *
 * The Add-ons page has two icons per row. The gear was broken for every add-on
 * until 8.1.1 — it pointed at a page that does not exist — and the book beside
 * it was broken too, in a way that is worse because it does not look broken:
 * every module's `docs_url` was `…/vibestatic#s3`, an anchor the README does not
 * have, so the browser opened the repository at the top and appeared to work.
 *
 * `Addons::registerAddon()` rewrites `docs_url` on every registration, so a URL
 * corrected in the source does reach an existing installation. Nothing checks
 * where it goes, though, which is what this is for.
 *
 * Exit status is 1 if anything is unreachable, so it can be a CI step — a
 * non-blocking one: a link that rots is worth knowing about and is not worth
 * stopping a release for.
 *
 * @package WP2Static
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
$links = [];

// docsUrl() and the documentation URL passed to wp2static_register_addon.
foreach ( glob( "$root/addons/*/src/Controller.php" ) as $file ) {
    if ( preg_match_all( "#'(https://[^']+)'#", (string) file_get_contents( $file ), $matches ) ) {
        foreach ( $matches[1] as $url ) {
            $links[ $url ][] = str_replace( "$root/", '', $file );
        }
    }
}

// Plugin URI, Update URI and the rest of the header.
foreach ( array_merge( [ "$root/vibestatic.php" ], glob( "$root/addons/*/module.php" ) ) as $file ) {
    if ( preg_match_all( '#^ \* \w+ URI:\s+(https://\S+)$#m', (string) file_get_contents( $file ), $matches ) ) {
        foreach ( $matches[1] as $url ) {
            $links[ $url ][] = str_replace( "$root/", '', $file );
        }
    }
}

if ( ! $links ) {
    fwrite( STDERR, "Nessun link trovato: il rilevamento e' rotto.\n" );
    exit( 1 );
}

$failures = 0;

foreach ( $links as $url => $where ) {
    [ $status, $body ] = fetch( $url );

    $problem = '';

    if ( $status < 200 || $status >= 400 ) {
        $problem = "HTTP $status";
    } elseif ( str_contains( $url, '#' ) ) {
        /*
         * An anchor that is not there does not 404: the page opens at the top
         * and looks fine. That is exactly how six broken links survived.
         */
        $fragment = substr( $url, strpos( $url, '#' ) + 1 );

        if ( ! str_contains( strtolower( $body ), 'user-content-' . strtolower( $fragment ) )
            && ! str_contains( strtolower( $body ), 'id="' . strtolower( $fragment ) . '"' )
        ) {
            $problem = "l'ancora #$fragment non esiste nella pagina";
        }
    }

    if ( '' === $problem ) {
        printf( "  ok    %s\n", $url );

        continue;
    }

    ++$failures;

    printf( "  ROTTO %s\n        %s\n        da: %s\n", $url, $problem, implode( ', ', array_unique( $where ) ) );
}

printf( "\n%d link, %d rotti.\n", count( $links ), $failures );

exit( $failures > 0 ? 1 : 0 );

/**
 * @return array{0: int, 1: string} Status and body.
 */
function fetch( string $url ) : array {
    $context = stream_context_create(
        [
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "User-Agent: vibestatic-check-links\r\n",
            ],
        ]
    );

    $body = @file_get_contents( $url, false, $context );

    $status = 0;

    foreach ( $http_response_header ?? [] as $line ) {
        if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
            $status = (int) $m[1];
        }
    }

    return [ $status, is_string( $body ) ? $body : '' ];
}
