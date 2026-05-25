<?php

namespace oihana\arango\clients\http ;

use InvalidArgumentException ;

/**
 * Round-robin ring over a list of ArangoDB server endpoints.
 *
 * Used by {@see HttpTransport} to spread requests across cluster
 * coordinators and to fail over to a different endpoint on transient
 * errors. The ring is stateful: each call to {@see next()} advances the
 * internal cursor (wrapping around at the end of the list).
 *
 * Endpoint URLs are normalised at construction time so that ArangoDB's
 * legacy scheme spellings keep working:
 * - `tcp://host:port`            → `http://host:port`
 * - `ssl://host:port` / `tls://` → `https://host:port`
 *
 * Other schemes (`http://`, `https://`, `unix://`, …) are returned as-is.
 *
 * @package oihana\arango\clients\http
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class HostRing
{
    /**
     * @param array<string> $endpoints Ordered list of endpoint URLs (at least one).
     *
     * @throws InvalidArgumentException When the list is empty.
     */
    public function __construct( array $endpoints )
    {
        if ( count( $endpoints ) === 0 )
        {
            throw new InvalidArgumentException( 'HostRing requires at least one endpoint.' ) ;
        }
        $this->endpoints = array_values
        (
            array_map( static fn( string $endpoint ) : string => self::normalize( $endpoint ) , $endpoints )
        ) ;
    }

    /**
     * Canonical HTTP scheme prefix used as a replacement for the legacy `tcp://` scheme.
     */
    public const string SCHEME_HTTP = 'http://' ;

    /**
     * Canonical HTTPS scheme prefix used as a replacement for the legacy `ssl://` and `tls://` schemes.
     */
    public const string SCHEME_HTTPS = 'https://' ;

    /**
     * Normalised endpoint list (ArangoDB legacy schemes converted to http/https).
     * @var array<string>
     */
    public readonly array $endpoints ;

    /**
     * Internal cursor pointing at the current endpoint.
     */
    private int $cursor = 0 ;

    /**
     * Returns the endpoint currently pointed at by the ring cursor.
     *
     * @return string
     */
    public function current() : string
    {
        return $this->endpoints[ $this->cursor ] ;
    }

    /**
     * Advances the ring cursor by one position (wrapping around at the end of the list)
     * and returns the new current endpoint.
     *
     * @return string
     */
    public function next() : string
    {
        $this->cursor = ( $this->cursor + 1 ) % count( $this->endpoints ) ;
        return $this->current() ;
    }

    /**
     * Normalises a single endpoint URL by translating ArangoDB legacy schemes
     * to their HTTP equivalents.
     *
     * @param string $endpoint
     * @return string
     */
    public static function normalize( string $endpoint ) : string
    {
        if ( preg_match( '#^tcp://#i' , $endpoint ) === 1 )
        {
            return self::SCHEME_HTTP . substr( $endpoint , 6 ) ;
        }
        if ( preg_match( '#^(ssl|tls)://#i' , $endpoint , $matches ) === 1 )
        {
            return self::SCHEME_HTTPS . substr( $endpoint , strlen( $matches[ 0 ] ) ) ;
        }
        return $endpoint ;
    }

    /**
     * Returns the number of endpoints managed by the ring.
     *
     * @return int
     */
    public function size() : int
    {
        return count( $this->endpoints ) ;
    }
}
