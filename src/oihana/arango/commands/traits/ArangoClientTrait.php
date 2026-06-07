<?php

namespace oihana\arango\commands\traits;

use Throwable;

use oihana\arango\clients\ArangoClient;
use oihana\arango\clients\Database;
use oihana\arango\clients\options\ClientOptions;

use oihana\enums\Char;

/**
 * Best-effort ArangoDB HTTP client builder for the `command:arangodb`
 * actions.
 *
 * The dump / restore actions talk to the database through the
 * `arangodump` / `arangorestore` binaries (a shell-out that does not need
 * the HTTP API). This trait provides an optional, non-fatal bridge to the
 * HTTP client so an action can *additionally* query the live database —
 * e.g. to list or validate collection names before a targeted dump.
 *
 * It is deliberately forgiving: {@see buildDatabase()} returns null
 * (instead of throwing) when no usable endpoint is configured or the
 * client cannot be constructed, so callers can degrade gracefully and let
 * the underlying binary keep working when the HTTP API is unavailable.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait ArangoClientTrait
{
    /**
     * Builds a best-effort {@see Database} HTTP client from the resolved
     * connection settings, or null when no usable endpoint/database is
     * available or the client cannot be constructed.
     *
     * No network I/O happens here — the connection is only exercised when
     * a request method (e.g. {@see Database::collections()}) is later
     * called by the caller.
     *
     * @param string $endpoint The ArangoDB endpoint (e.g. `tcp://127.0.0.1:8529`).
     * @param string $username The connection user.
     * @param string $password The connection password.
     * @param string $database The target database name.
     * @return Database|null
     */
    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        if ( $endpoint === Char::EMPTY || $database === Char::EMPTY )
        {
            return null ;
        }

        try
        {
            $options = ClientOptions::fromArray
            (
                [
                    ClientOptions::DATABASE => $database ,
                    ClientOptions::ENDPOINT => $endpoint ,
                    ClientOptions::PASSWORD => $password ,
                    ClientOptions::USER     => $username ,
                ]
            ) ;

            return new ArangoClient( $options )->database( $database ) ;
        }
        // @codeCoverageIgnoreStart
        catch ( Throwable )
        {
            return null ;
        }
        // @codeCoverageIgnoreEnd
    }
}
