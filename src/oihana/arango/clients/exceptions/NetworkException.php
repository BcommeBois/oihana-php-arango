<?php

namespace oihana\arango\clients\exceptions ;

/**
 * Thrown when the request cannot reach the server (DNS failure, TCP refused,
 * connect/read timeout, TLS handshake error, …) — that is, before any HTTP
 * response is received.
 *
 * The default retry safety is conservative (false): retrying a POST that
 * timed out may produce a duplicate write if the server actually processed
 * the request. The retry policy at transport level decides per-method.
 *
 * @package oihana\arango\clients\exceptions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class NetworkException extends ArangoException
{
}
