<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\clients\Database;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\db\ArangoDB;

use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Minimal host exposing the protected {@see ArangoClientTrait::buildDatabase()}
 * and {@see ArangoClientTrait::resolveFacade()} so they can be unit-tested in
 * isolation.
 */
class ArangoClientTraitStub
{
    use ArangoClientTrait ;

    public function __construct()
    {
        $this->endpoint = 'tcp://127.0.0.1:8529' ;
        $this->database = 'mydb' ;
        $this->username = 'root' ;
        $this->password = '' ;
    }

    public function build( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->buildDatabase( $endpoint , $username , $password , $database ) ;
    }

    public function facade( InputInterface $input ) :?ArangoDB
    {
        return $this->resolveFacade( $input ) ;
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

    /** An input answering every connection option (unset → falls back to the host config). */
    private function input( array $options = [] ) :ArrayInput
    {
        $definition = new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
        ]) ;

        return new ArrayInput( $options , $definition ) ;
    }

    public function testResolveFacadeBuildsTheFacadeFromTheConfig() :void
    {
        $facade = new ArangoClientTraitStub()->facade( $this->input() ) ;

        $this->assertInstanceOf( ArangoDB::class , $facade ) ;
        $this->assertSame( 'mydb' , $facade->database()->getName() ) ;
    }

    public function testResolveFacadeHonoursTheCliOverrides() :void
    {
        $facade = new ArangoClientTraitStub()->facade( $this->input( [ '--' . ArangoCommandOption::DATABASE => 'other' ] ) ) ;

        $this->assertSame( 'other' , $facade->database()->getName() ) ;
    }

    public function testResolveFacadeReturnsNullWhenDatabaseEmpty() :void
    {
        $this->assertNull( new ArangoClientTraitStub()->facade( $this->input( [ '--' . ArangoCommandOption::DATABASE => '' ] ) ) ) ;
    }

    public function testResolveFacadeReturnsNullWhenEndpointEmpty() :void
    {
        $this->assertNull( new ArangoClientTraitStub()->facade( $this->input( [ '--' . ArangoCommandOption::ENDPOINT => '' ] ) ) ) ;
    }
}
