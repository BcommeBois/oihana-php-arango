<?php

namespace oihana\arango\clients\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Server modes reported by the ArangoDB availability endpoint
 * (`GET /_admin/server/availability`).
 *
 * The endpoint returns one of these values in the `mode` field of its
 * JSON response when the server is up. A 503 status code is used instead
 * to signal that the server is shutting down or in maintenance mode —
 * that branch is surfaced by {@see \oihana\arango\clients\ArangoClient::availability()}
 * as a boolean `false` rather than a `ServerMode` value.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/administration/#return-whether-the-server-is-available
 *
 * @package oihana\arango\clients\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ServerMode
{
    use ConstantsTrait ;

    /**
     * Server is up and accepts both reads and writes.
     */
    public const string DEFAULT = 'default' ;

    /**
     * Server is up but rejects writes (failover member, manual switch, …).
     */
    public const string READONLY = 'readonly' ;
}
