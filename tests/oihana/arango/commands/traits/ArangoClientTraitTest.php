<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\clients\Database;
use oihana\arango\commands\traits\ArangoClientTrait;

use PHPUnit\Framework\TestCase;

/**
 * Minimal host exposing the protected {@see ArangoClientTrait::buildDatabase()}
 * builder so it can be unit-tested in isolation.
 */
class ArangoClientTraitStub
{
    use ArangoClientTrait ;

    public function build( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->buildDatabase( $endpoint , $username , $password , $database ) ;
    }
}

/**
 * Unit coverage for {@see ArangoClientTrait::buildDatabase()}.
 *
 * Only the construction contract is exercised — no network I/O is
 * triggered (the HTTP API is only hit when a request method such as
 * Database::collections() is called, which these tests never do).
 */
class ArangoClientTraitTest extends TestCase
{
    public function testBuildReturnsDatabaseForValidInputs() :void
    {
        $stub     = new ArangoClientTraitStub() ;
        $database = $stub->build( 'tcp://127.0.0.1:8529' , 'root' , '' , 'mydb' ) ;

        $this->assertInstanceOf( Database::class , $database ) ;
        $this->assertSame( 'mydb' , $database->getName() ) ;
    }

    public function testBuildReturnsNullWhenDatabaseEmpty() :void
    {
        $stub = new ArangoClientTraitStub() ;
        $this->assertNull( $stub->build( 'tcp://127.0.0.1:8529' , 'root' , '' , '' ) ) ;
    }

    public function testBuildReturnsNullWhenEndpointEmpty() :void
    {
        $stub = new ArangoClientTraitStub() ;
        $this->assertNull( $stub->build( '' , 'root' , '' , 'mydb' ) ) ;
    }
}
