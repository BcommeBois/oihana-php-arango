<?php

namespace oihana\arango\clients\http ;

/**
 * Value object returned by {@see HttpTransport::request()}.
 *
 * Carries the HTTP status, the response headers (in PSR-7 shape — header
 * name => `array<int, string>` of values), the parsed body (typically a
 * JSON-decoded `array`, but may be any scalar or null), and the raw body
 * string for diagnostics.
 *
 * @package oihana\arango\clients\http
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class HttpResponse
{
    /**
     * @param int                          $status  HTTP status code returned by the server.
     * @param array<string, array<string>> $headers Response headers, PSR-7 style (name => array of values).
     * @param mixed                        $body    Decoded body (array for JSON, scalar for plain text, null when empty).
     * @param string|null                  $raw     Raw response body string (kept for diagnostics / logging).
     */
    public function __construct
    (
        public int     $status ,
        public array   $headers = [] ,
        public mixed   $body    = null ,
        public ?string $raw     = null ,
    )
    {
    }

    /**
     * Returns the first value of the given header (case-insensitive), or null when absent.
     *
     * @param string $name
     * @return string|null
     */
    public function header( string $name ) : ?string
    {
        $needle = strtolower( $name ) ;
        foreach ( $this->headers as $headerName => $values )
        {
            if ( strtolower( $headerName ) === $needle )
            {
                if ( is_array( $values ) )
                {
                    return $values[ 0 ] ?? null ;
                }
                return (string) $values ;
            }
        }
        return null ;
    }

    /**
     * Returns true when the HTTP status falls in the 2xx range.
     *
     * @return bool
     */
    public function isSuccess() : bool
    {
        return $this->status >= 200 && $this->status < 300 ;
    }
}
