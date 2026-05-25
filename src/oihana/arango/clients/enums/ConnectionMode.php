<?php

namespace oihana\arango\clients\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * HTTP connection persistence modes supported by the ArangoDB client.
 *
 * Values match the canonical `Connection` HTTP header values, so the
 * enumeration doubles as a header builder when the transport layer
 * needs to set the connection mode explicitly.
 *
 * @package oihana\arango\clients\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ConnectionMode
{
    use ConstantsTrait ;

    /**
     * Open a fresh TCP connection for every request and close it on completion.
     *
     * Useful for short-lived CLI scripts or environments where the server
     * limits the lifetime of inactive connections.
     */
    public const string CLOSE = 'Close' ;

    /**
     * Reuse the underlying TCP connection across requests (HTTP keep-alive).
     *
     * Lower latency and CPU cost for chatty workloads — recommended for
     * server-to-server use.
     */
    public const string KEEP_ALIVE = 'Keep-Alive' ;
}
