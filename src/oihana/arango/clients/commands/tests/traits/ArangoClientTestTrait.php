<?php

namespace oihana\arango\clients\commands\tests\traits ;

use Symfony\Component\Console\Input\InputInterface ;
use Symfony\Component\Console\Input\InputOption ;
use Symfony\Component\Console\Style\SymfonyStyle ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;

/**
 * Shared options + client-construction helpers for the
 * `arango:test:clients` live integration command.
 *
 * Plays the same role as
 * {@see \oihana\api\commands\tests\auth\traits\ApiClientTrait} does for
 * the `auth:test:*` HTTP commands, but the target is the ArangoDB HTTP
 * API directly (no Bearer / OIDC) and the resolved client is an
 * {@see ArangoClient} rather than a raw Guzzle client.
 *
 * The trait reads the project's `[arango]` configuration through
 * {@see ClientOptions::fromArray()} and lets the operator override any
 * connection field through CLI options:
 *
 * - `--endpoint <url>` — override the server endpoint (default: TOML).
 * - `--user <name>`    — override the user (default: TOML).
 * - `--password <pw>`  — override the password (default: TOML).
 * - `--database <db>`  — override the **default** database (the test
 *   command always works on its own ephemeral database; this option
 *   only changes the fallback the {@see ArangoClient} resolves to).
 * - `--no-cleanup`     — keep the test database around after the run.
 * - `--step <range>`   — limit the suite to a subset of steps.
 *
 * @package oihana\arango\clients\commands\tests\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait ArangoClientTestTrait
{
    /**
     * DI key carrying the `[arango]` configuration array.
     *
     * Forwarded through the `init` array at construction time. The
     * trait reads it via {@see initializeArangoTestClient()} and keeps
     * the resolved options for later builds.
     */
    public const string ARANGO_CONFIG = 'arangoConfig' ;

    public const string OPTION_DATABASE   = 'database'   ;
    public const string OPTION_ENDPOINT   = 'endpoint'   ;
    public const string OPTION_NO_CLEANUP = 'no-cleanup' ;
    public const string OPTION_PASSWORD   = 'password'   ;
    public const string OPTION_USER       = 'user'       ;

    /**
     * Resolved configuration array, captured at construction time.
     *
     * @var array<string, mixed>
     */
    protected array $arangoConfig = [] ;

    /**
     * Builds an {@see ArangoClient} from the trait's stored config,
     * applying any CLI override from `$input`. Reports the resolved
     * endpoint / user / database to `$io` for transparency. Returns
     * null when the config is missing or incomplete.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     *
     * @return ArangoClient|null
     */
    protected function buildArangoClient( InputInterface $input , SymfonyStyle $io ) : ?ArangoClient
    {
        $config = $this->arangoConfig ;

        $endpoint = $this->stringOption( $input , self::OPTION_ENDPOINT ) ;
        $user     = $this->stringOption( $input , self::OPTION_USER     ) ;
        $password = $this->stringOption( $input , self::OPTION_PASSWORD ) ;
        $database = $this->stringOption( $input , self::OPTION_DATABASE ) ;

        if ( $endpoint !== null ) { $config[ ClientOptions::ENDPOINT ] = $endpoint ; }
        if ( $user     !== null ) { $config[ ClientOptions::USER     ] = $user     ; }
        if ( $password !== null ) { $config[ ClientOptions::PASSWORD ] = $password ; }
        if ( $database !== null ) { $config[ ClientOptions::DATABASE ] = $database ; }

        $options = ClientOptions::fromArray( $config ) ;

        if ( $options->endpoint() === null )
        {
            $io->error( 'No ArangoDB endpoint configured (set it in [arango] in config.toml or pass --endpoint).' ) ;
            return null ;
        }

        $io->writeln( '  endpoint : <info>' . $options->endpoint() . '</info>' ) ;
        $io->writeln( '  user     : <info>' . ( $options->user ?? '<none>' ) . '</info>' ) ;
        $io->writeln( '  database : <info>' . ( $options->database ?? '_system' ) . '</info>' ) ;

        return new ArangoClient( $options ) ;
    }

    /**
     * Adds the trait's CLI options to the consuming command.
     */
    protected function configureArangoTestOptions() : void
    {
        $this->addOption( self::OPTION_ENDPOINT   , 'E'  , InputOption::VALUE_OPTIONAL , 'Override the ArangoDB endpoint URL (default: [arango].endpoint in config.toml).' ) ;
        $this->addOption( self::OPTION_USER       , 'U'  , InputOption::VALUE_OPTIONAL , 'Override the ArangoDB user (default: [arango].user in config.toml).' ) ;
        $this->addOption( self::OPTION_PASSWORD   , 'P'  , InputOption::VALUE_OPTIONAL , 'Override the ArangoDB password (default: [arango].password in config.toml).' ) ;
        $this->addOption( self::OPTION_DATABASE   , 'D'  , InputOption::VALUE_OPTIONAL , 'Override the fallback database for the ArangoClient (the test database is created separately).' ) ;
        $this->addOption( self::OPTION_NO_CLEANUP , null , InputOption::VALUE_NONE     , 'Skip dropping the ephemeral test database at the end of the run.' ) ;
    }

    /**
     * Captures the `[arango]` configuration injected through the
     * command's `init` array. Called from the consuming command's
     * constructor.
     *
     * @param array<string, mixed> $init
     */
    protected function initializeArangoTestClient( array $init ) : void
    {
        $config = $init[ self::ARANGO_CONFIG ] ?? null ;
        $this->arangoConfig = is_array( $config ) ? $config : [] ;
    }

    /**
     * Reads a string CLI option, returning null when missing or empty.
     */
    private function stringOption( InputInterface $input , string $name ) : ?string
    {
        $value = $input->getOption( $name ) ;
        return is_string( $value ) && $value !== '' ? $value : null ;
    }

    /**
     * Returns true when `--no-cleanup` is NOT set.
     */
    protected function shouldCleanup( InputInterface $input ) : bool
    {
        return ! $input->getOption( self::OPTION_NO_CLEANUP ) ;
    }

    /**
     * Reports a single assertion to `$io` and returns the updated
     * `[ passed , errors ]` counters.
     *
     * Same contract as the `auth:test:*` `ApiAssertionsTrait::check()`,
     * inlined here so the new arango command does not depend on the
     * auth namespace.
     *
     * @return array{0: int, 1: int}
     */
    protected function check( SymfonyStyle $io , mixed $condition , string $label , int $passed , int $errors ) : array
    {
        if ( $condition )
        {
            $io->writeln( "  <info>✓</info> $label" ) ;
            return [ $passed + 1 , $errors ] ;
        }

        $io->writeln( "  <error>✗</error> $label" ) ;
        return [ $passed , $errors + 1 ] ;
    }
}
