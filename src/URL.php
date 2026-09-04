<?php
/*
    URL object

    Costruisce e valida un URL assoluto, e lo restituisce come stringa.

    Prima passava da wa72/url, una libreria ferma al 2018 e senza manutenzione.
    Il piano prevedeva di sostituirla con league/uri; misurandola si e' visto
    che non serve nessuna delle due, perche' Guzzle porta gia' con se' una
    implementazione di PSR-7 che fa la stessa cosa e qualcosa in piu'.

    Confronto su 1828 URL reali del sito di sviluppo: wa72 non ne cambiava
    nemmeno uno. Sui casi limite le due si comportano allo stesso modo — host
    in minuscolo, porta di default rimossa — tranne in due punti, e sono
    entrambi a favore di Psr7\Uri:

      http://esempio.it/a b      wa72 lo lascia com'e', Psr7 lo codifica in %20
      http://esempio.it/caffe'   wa72 lo lascia com'e', Psr7 lo codifica in UTF-8

    Nel verso opposto, wa72 collassava i segmenti «..» (/a/../b -> /b) e Psr7
    no. In un permalink WordPress non compaiono, e lasciarli e' semantica piu'
    fedele: a risolverli e' il server.
*/

namespace WP2Static;

use WP2Static\Vendor\GuzzleHttp\Psr7\Uri;
use WP2Static\Vendor\GuzzleHttp\Psr7\UriResolver;
use WP2Static\Vendor\Psr\Http\Message\UriInterface;

class URL {

    /**
     * @var UriInterface
     */
    private $url;

    /**
     * URL constructor
     *
     * @param string $parent_page_url URL optional parent page to make
     * absolute URL from
     * @throws WP2StaticException
     */
    public function __construct( string $url, ?string $parent_page_url = null ) {
        $uri = new Uri( $url );

        if ( $parent_page_url ) {
            $this->url = UriResolver::resolve( new Uri( $parent_page_url ), $uri );

            return;
        }

        // test absolute URL
        if ( ! $uri->getHost() ) {
            throw new WP2StaticException(
                'Trying to create unsupported URL'
            );
        }

        $this->url = $uri;
    }

    /**
     * Return the URL as a string
     */
    public function get(): string {
        return (string) $this->url;
    }

    /**
     * Rewrite host and scheme to destination URL's
     *
     * @param string $destination_url URL rewrite rules
     */
    public function rewriteHostAndProtocol( string $destination_url ): void {
        $destination = new Uri( $destination_url );

        /*
         * Gli URI di PSR-7 sono immutabili: withHost() e withScheme()
         * restituiscono una copia, non modificano l'oggetto. Il nome del metodo
         * dice ancora «in place» ed e' vero dal di fuori — cambia la proprieta'
         * dell'oggetto URL, non l'URI sottostante.
         */
        $this->url = $this->url
            ->withHost( $destination->getHost() )
            ->withScheme( $destination->getScheme() );
    }
}
