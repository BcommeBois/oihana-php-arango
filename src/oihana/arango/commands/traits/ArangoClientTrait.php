<?php

namespace oihana\arango\commands\traits;

use Throwable;

use Symfony\Component\Console\Input\InputInterface;

use oihana\arango\clients\ArangoClient;
use oihana\arango\clients\Database;
use oihana\arango\clients\options\ClientOptions;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;

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
    use ArangoConfigTrait ;

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

    /**
     * Builds the best-effort {@see Database} HTTP client of an action run:
     * every connection setting reads its CLI option first
     * (`--database` / `--endpoint` / `--user` / `--password`) and falls
     * back on the command configuration ({@see ArangoConfigTrait}).
     *
     * One-stop shop for the actions — see {@see buildDatabase()} for the
     * null-on-failure semantics.
     *
     * @param InputInterface $input The action input carrying the optional CLI overrides.
     *
     * @return Database|null
     */
    protected function resolveDatabase( InputInterface $input ) :?Database
    {
        return $this->buildDatabase
        (
            endpoint : $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ,
            username : $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ,
            password : $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ,
            database : $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ,
        ) ;
    }

    /**
     * Builds the best-effort high-level {@see ArangoDB} façade of an action
     * run, from the same resolved connection settings as
     * {@see resolveDatabase()} — the migration engine hands this façade to
     * every {@see \oihana\arango\migrations\Migration}. Null when no usable
     * endpoint is configured or the façade cannot be constructed.
     *
     * @param InputInterface $input The action input carrying the optional CLI overrides.
     *
     * @return ArangoDB|null
     */
    protected function resolveFacade( InputInterface $input ) :?ArangoDB
    {
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;
        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;

        if ( $endpoint === Char::EMPTY || $database === Char::EMPTY )
        {
            return null ;
        }

        try
        {
            return new ArangoDB
            ([
                ArangoConfig::ENDPOINT => $endpoint ,
                ArangoConfig::DATABASE => $database ,
                ArangoConfig::USER     => $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ,
                ArangoConfig::PASSWORD => $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ,
            ]) ;
        }
        // @codeCoverageIgnoreStart
        catch ( Throwable )
        {
            return null ;
        }
        // @codeCoverageIgnoreEnd
    }
}
