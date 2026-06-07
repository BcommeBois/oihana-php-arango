<?php

namespace tests\oihana\arango\db;

use ReflectionClass;
use ReflectionProperty;

use oihana\arango\clients\ArangoClient;
use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\db\ArangoDB;

use Psr\Log\LoggerInterface;

use PHPUnit\Framework\TestCase;

/**
 * Shared harness for the {@see ArangoDB} façade and its traits.
 *
 * The real constructor opens an HTTP client, so tests build the instance
 * without it ({@see ReflectionClass::newInstanceWithoutConstructor()}) and
 * inject doubles for the `database` / `client` / `cursor` / `logger`
 * collaborators through reflection.
 *
 * @package tests\oihana\arango\db
 * @author  Marc Alcaraz
 */
abstract class ArangoDBTestCase extends TestCase
{
    /**
     * Builds an {@see ArangoDB} with its constructor bypassed and the given
     * collaborators injected. Missing `database` / `client` default to bare
     * mocks.
     *
     * @param Database|null        $database
     * @param ArangoClient|null    $client
     * @param Cursor|null          $cursor
     * @param LoggerInterface|null $logger
     *
     * @return ArangoDB
     */
    protected function newArangoDB
    (
        ?Database        $database = null ,
        ?ArangoClient    $client   = null ,
        ?Cursor          $cursor   = null ,
        ?LoggerInterface $logger   = null ,
    )
    :ArangoDB
    {
        $arangoDB = ( new ReflectionClass( ArangoDB::class ) )->newInstanceWithoutConstructor() ;

        $this->setProperty( $arangoDB , 'database' , $database ?? $this->createMock( Database::class ) ) ;
        $this->setProperty( $arangoDB , 'client'   , $client   ?? $this->createMock( ArangoClient::class ) ) ;
        $this->setProperty( $arangoDB , 'logger'   , $logger ) ;

        if ( $cursor !== null )
        {
            $this->setProperty( $arangoDB , 'cursor' , $cursor ) ;
        }

        return $arangoDB ;
    }

    /**
     * Sets a (possibly private) property on an object via reflection.
     *
     * @param object $object
     * @param string $name
     * @param mixed  $value
     *
     * @return void
     */
    protected function setProperty( object $object , string $name , mixed $value ) :void
    {
        $property = new ReflectionProperty( $object , $name ) ;
        $property->setValue( $object , $value ) ;
    }
}
