<?php

namespace tests\oihana\arango\clients\options ;

use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\enums\ConnectionMode ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ClientOptions} — typed configuration object for the
 * ArangoDB client.
 *
 * Covers the named-parameter constructor, the {@see ClientOptions::fromArray()}
 * mapping from a free-form configuration array (TOML interop), and the
 * legacy `type` alias for `authType`.
 */
#[CoversClass( ClientOptions::class )]
class ClientOptionsTest extends TestCase
{
    // =========================================================================
    // Constructor defaults
    // =========================================================================

    public function testDefaultsAreSafe() :void
    {
        $options = new ClientOptions() ;

        $this->assertNull ( $options->database  ) ;
        $this->assertSame ( []                                          , $options->endpoints      ) ;
        $this->assertSame ( AuthType::BASIC                             , $options->authType       ) ;
        $this->assertNull ( $options->user      ) ;
        $this->assertNull ( $options->password  ) ;
        $this->assertNull ( $options->token     ) ;
        $this->assertSame ( ConnectionMode::KEEP_ALIVE                  , $options->connection     ) ;
        $this->assertSame ( ClientOptions::DEFAULT_TIMEOUT              , $options->timeout        ) ;
        $this->assertSame ( ClientOptions::DEFAULT_CONNECT_TIMEOUT      , $options->connectTimeout ) ;
        $this->assertSame ( ClientOptions::DEFAULT_TIMEOUT              , $options->requestTimeout ) ;
        $this->assertNull ( $options->maxRuntime ) ;
        $this->assertSame ( ClientOptions::DEFAULT_BATCH_SIZE           , $options->batchSize      ) ;
        $this->assertFalse( $options->allowDirtyRead ) ;
        $this->assertTrue ( $options->reconnect ) ;
        $this->assertTrue ( $options->create    ) ;
        $this->assertFalse( $options->debug     ) ;
    }

    public function testEndpointReturnsNullWhenNoEndpointsConfigured() :void
    {
        $this->assertNull( ( new ClientOptions() )->endpoint() ) ;
    }

    // =========================================================================
    // Named-parameter construction
    // =========================================================================

    public function testNamedParameterConstructionWiresAllFields() :void
    {
        $options = new ClientOptions
        (
            database   : 'mydb' ,
            endpoints  : [ 'tcp://primary:8529' , 'tcp://failover:8529' ] ,
            authType   : AuthType::BASIC ,
            user       : 'root' ,
            password   : 'secret' ,
            connection : ConnectionMode::CLOSE ,
            timeout    : 12 ,
            maxRuntime : 90 ,
            batchSize  : 5000 ,
            reconnect  : false ,
            create     : false ,
            debug      : true ,
        ) ;

        $this->assertSame ( 'mydb'                  , $options->database   ) ;
        $this->assertSame ( 'tcp://primary:8529'    , $options->endpoint() ) ;
        $this->assertCount( 2                       , $options->endpoints  ) ;
        $this->assertSame ( 'root'                  , $options->user       ) ;
        $this->assertSame ( 'secret'                , $options->password   ) ;
        $this->assertSame ( ConnectionMode::CLOSE   , $options->connection ) ;
        $this->assertSame ( 12                      , $options->timeout    ) ;
        $this->assertSame ( 90                      , $options->maxRuntime ) ;
        $this->assertSame ( 5000                    , $options->batchSize  ) ;
        $this->assertFalse( $options->reconnect ) ;
        $this->assertFalse( $options->create    ) ;
        $this->assertTrue ( $options->debug     ) ;
    }

    // =========================================================================
    // fromArray() — TOML interop
    // =========================================================================

    public function testFromArrayWithLegacyTomlSection() :void
    {
        // Mirrors the project's `[arango]` TOML section.
        $config = [
            'database'   => 'mydb' ,
            'endpoint'   => 'tcp://127.0.0.1:8529' ,
            'user'       => 'root' ,
            'password'   => 'secret' ,
            'passphrase' => 'unused' , // ignored — unknown key
            'batchSize'  => 20000 ,
            'connection' => 'Keep-Alive' ,
            'create'     => true ,
            'debug'      => true ,
            'encrypt'    => true , // ignored
            'lazy'       => true , // ignored
            'reconnect'  => true ,
            'timeout'    => 6 ,
            'type'       => 'Basic' , // legacy alias
        ] ;

        $options = ClientOptions::fromArray( $config ) ;

        $this->assertSame ( 'mydb'                  , $options->database   ) ;
        $this->assertSame ( 'tcp://127.0.0.1:8529'  , $options->endpoint() ) ;
        $this->assertSame ( [ 'tcp://127.0.0.1:8529' ] , $options->endpoints ) ;
        $this->assertSame ( AuthType::BASIC         , $options->authType   ) ;
        $this->assertSame ( 'root'                  , $options->user       ) ;
        $this->assertSame ( 'secret'                , $options->password   ) ;
        $this->assertSame ( 'Keep-Alive'            , $options->connection ) ;
        $this->assertSame ( 6                       , $options->timeout    ) ;
        $this->assertSame ( 20000                   , $options->batchSize  ) ;
        $this->assertTrue ( $options->reconnect ) ;
        $this->assertTrue ( $options->create    ) ;
        $this->assertTrue ( $options->debug     ) ;
    }

    public function testFromArrayWithExplicitEndpointsListWins() :void
    {
        $options = ClientOptions::fromArray( [
            'endpoint'  => 'tcp://primary:8529' ,
            'endpoints' => [ 'tcp://node-a:8529' , 'tcp://node-b:8529' ] ,
        ] ) ;

        // `endpoints` is preserved and the singleton `endpoint` is prepended
        // because it is not already present in the list.
        $this->assertSame
        (
            [ 'tcp://primary:8529' , 'tcp://node-a:8529' , 'tcp://node-b:8529' ] ,
            $options->endpoints ,
        ) ;
        $this->assertSame( 'tcp://primary:8529' , $options->endpoint() ) ;
    }

    public function testFromArrayDoesNotDuplicateSingletonEndpointAlreadyInList() :void
    {
        $options = ClientOptions::fromArray( [
            'endpoint'  => 'tcp://primary:8529' ,
            'endpoints' => [ 'tcp://primary:8529' , 'tcp://failover:8529' ] ,
        ] ) ;

        $this->assertSame
        (
            [ 'tcp://primary:8529' , 'tcp://failover:8529' ] ,
            $options->endpoints ,
        ) ;
    }

    public function testFromArrayIgnoresNonArrayEndpoints() :void
    {
        // A non-array `endpoints` value (e.g. a scalar from a malformed config)
        // is discarded in favour of an empty list rather than blowing up.
        $options = ClientOptions::fromArray( [ 'endpoints' => 'tcp://oops:8529' ] ) ;

        $this->assertSame( [] , $options->endpoints ) ;
    }

    public function testFromArrayAuthTypeWinsOverLegacyTypeAlias() :void
    {
        $options = ClientOptions::fromArray( [
            'authType' => AuthType::JWT ,
            'type'     => AuthType::BASIC ,
            'token'    => 'eyJ...' ,
        ] ) ;

        $this->assertSame( AuthType::JWT , $options->authType ) ;
        $this->assertSame( 'eyJ...'      , $options->token    ) ;
    }

    public function testFromArrayWithEmptyArrayUsesDefaults() :void
    {
        $options = ClientOptions::fromArray( [] ) ;

        $this->assertNull ( $options->database ) ;
        $this->assertSame ( []                                          , $options->endpoints      ) ;
        $this->assertSame ( AuthType::BASIC                             , $options->authType       ) ;
        $this->assertSame ( ConnectionMode::KEEP_ALIVE                  , $options->connection     ) ;
        $this->assertSame ( ClientOptions::DEFAULT_TIMEOUT              , $options->timeout        ) ;
        $this->assertSame ( ClientOptions::DEFAULT_CONNECT_TIMEOUT      , $options->connectTimeout ) ;
        $this->assertSame ( ClientOptions::DEFAULT_TIMEOUT              , $options->requestTimeout ) ;
        $this->assertSame ( ClientOptions::DEFAULT_BATCH_SIZE           , $options->batchSize      ) ;
        $this->assertTrue ( $options->reconnect ) ;
        $this->assertTrue ( $options->create    ) ;
        $this->assertFalse( $options->debug     ) ;
    }

    // =========================================================================
    // Timeout split — legacy `timeout` + explicit `connectTimeout` / `requestTimeout`
    // =========================================================================

    public function testLegacyTimeoutAliasesRequestTimeoutWhenLatterIsAbsent() :void
    {
        // Single-knob configuration -- requestTimeout falls back to timeout.
        $options = new ClientOptions( timeout : 12 ) ;

        $this->assertSame( 12 , $options->timeout        ) ;
        $this->assertSame( 12 , $options->requestTimeout ) ;
    }

    public function testRequestTimeoutWinsOverLegacyTimeout() :void
    {
        // Explicit requestTimeout overrides the legacy timeout fallback.
        $options = new ClientOptions
        (
            timeout        : 12 ,
            requestTimeout : 45 ,
        ) ;

        $this->assertSame( 12 , $options->timeout        ) ;
        $this->assertSame( 45 , $options->requestTimeout ) ;
    }

    public function testConnectTimeoutIsIndependentOfRequestTimeout() :void
    {
        $options = new ClientOptions
        (
            timeout        : 30 ,
            connectTimeout : 2  ,
            requestTimeout : 60 ,
        ) ;

        $this->assertSame( 30 , $options->timeout        ) ;
        $this->assertSame( 2  , $options->connectTimeout ) ;
        $this->assertSame( 60 , $options->requestTimeout ) ;
    }

    public function testFromArrayWiresExplicitConnectAndRequestTimeouts() :void
    {
        $options = ClientOptions::fromArray
        ([
            'timeout'        => 10 ,
            'connectTimeout' => 3  ,
            'requestTimeout' => 90 ,
        ]) ;

        $this->assertSame( 10 , $options->timeout        ) ;
        $this->assertSame( 3  , $options->connectTimeout ) ;
        $this->assertSame( 90 , $options->requestTimeout ) ;
    }

    public function testFromArrayFallsBackToLegacyTimeoutWhenRequestTimeoutMissing() :void
    {
        // A pre-existing TOML config with only `timeout` keeps working —
        // requestTimeout silently mirrors it.
        $options = ClientOptions::fromArray( [ 'timeout' => 8 ] ) ;

        $this->assertSame( 8 , $options->timeout        ) ;
        $this->assertSame( 8 , $options->requestTimeout ) ;
        $this->assertSame( ClientOptions::DEFAULT_CONNECT_TIMEOUT , $options->connectTimeout ) ;
    }

    // =========================================================================
    // allowDirtyRead — cluster read preference
    // =========================================================================

    public function testAllowDirtyReadDefaultsToFalse() :void
    {
        $this->assertFalse( ( new ClientOptions() )->allowDirtyRead ) ;
    }

    public function testAllowDirtyReadCanBeEnabledThroughNamedParameter() :void
    {
        $options = new ClientOptions( allowDirtyRead : true ) ;

        $this->assertTrue( $options->allowDirtyRead ) ;
    }

    public function testFromArrayWiresAllowDirtyRead() :void
    {
        $on  = ClientOptions::fromArray( [ 'allowDirtyRead' => true  ] ) ;
        $off = ClientOptions::fromArray( [ 'allowDirtyRead' => false ] ) ;

        $this->assertTrue ( $on->allowDirtyRead  ) ;
        $this->assertFalse( $off->allowDirtyRead ) ;
    }

    public function testFromArrayAllowDirtyReadDefaultsToFalseWhenAbsent() :void
    {
        $this->assertFalse( ClientOptions::fromArray( [] )->allowDirtyRead ) ;
    }
}
