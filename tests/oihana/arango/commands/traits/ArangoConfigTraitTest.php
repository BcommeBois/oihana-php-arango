<?php

namespace tests\oihana\arango\commands\traits;

use UnexpectedValueException;

use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\enums\ArangoConfig;

use PHPUnit\Framework\TestCase;

/**
 * Bare host for {@see ArangoConfigTrait}.
 */
class ArangoConfigTraitStub
{
    use ArangoConfigTrait ;
}

/**
 * Unit coverage for {@see ArangoConfigTrait}.
 *
 * Guards the move from `assert()` (compiled out when zend.assertions=-1)
 * to real exceptions, so a missing connection value can never silently
 * flow on as null — and confirms that an empty password is accepted.
 */
class ArangoConfigTraitTest extends TestCase
{
    public function testGettersReturnInitializedValues() :void
    {
        $stub = new ArangoConfigTraitStub() ;
        $stub->initializeArangoDB
        ([
            ArangoConfig::DATABASE => 'mydb' ,
            ArangoConfig::ENDPOINT => 'tcp://127.0.0.1:8529' ,
            ArangoConfig::USER     => 'root' ,
            ArangoConfig::PASSWORD => 'secret' ,
        ]) ;

        $this->assertSame( 'mydb'                 , $stub->getDatabase() ) ;
        $this->assertSame( 'tcp://127.0.0.1:8529' , $stub->getEndpoint() ) ;
        $this->assertSame( 'root'                 , $stub->getUsername() ) ;
        $this->assertSame( 'secret'               , $stub->getPassword() ) ;
    }

    public function testEmptyPasswordIsAccepted() :void
    {
        $stub = new ArangoConfigTraitStub() ;
        $stub->initializeArangoDB( [ ArangoConfig::PASSWORD => '' ] ) ;

        $this->assertSame( '' , $stub->getPassword() ) ;
    }

    public function testGetDatabaseThrowsWhenNull() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        new ArangoConfigTraitStub()->getDatabase() ;
    }

    public function testGetEndpointThrowsWhenNull() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        new ArangoConfigTraitStub()->getEndpoint() ;
    }

    public function testGetPasswordThrowsWhenNull() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        new ArangoConfigTraitStub()->getPassword() ;
    }

    public function testGetUsernameThrowsWhenNull() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        new ArangoConfigTraitStub()->getUsername() ;
    }
}
