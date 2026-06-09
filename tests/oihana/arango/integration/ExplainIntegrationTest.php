<?php

namespace tests\oihana\arango\integration;

use ReflectionClass;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\PersistentIndex;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\results\ExplainResult;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live validation of {@see ArangoDB::explain()} and the typed {@see ExplainResult}.
 *
 * A collection is seeded with a persistent index on `age`; the explain of a query
 * that filters on `age` must report the index as actually used and surface the
 * optimizer rules — proving the value-objects reflect a real server plan, not a
 * hand-built fixture. `explain` is a core API, so this runs on any ArangoDB
 * (no experimental flag required).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ExplainIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_explain_it' ;

    private const string COLLECTION = 'users' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $users = $db->collection( self::COLLECTION ) ;
        $users->create() ;
        for ( $i = 0 ; $i < 20 ; $i++ )
        {
            $users->insert( [ 'name' => "u$i" , 'age' => 20 + $i ] ) ;
        }
        $users->createIndex( new PersistentIndex( fields : [ 'age' ] ) ) ;
    }

    /**
     * Builds an {@see ArangoDB} façade over the already-connected disposable
     * database (constructor bypassed, collaborators injected) so the live test
     * exercises the façade's `explain()` end to end.
     */
    private function facade() :ArangoDB
    {
        $facade = ( new ReflectionClass( ArangoDB::class ) )->newInstanceWithoutConstructor() ;

        $set = static function ( string $name , mixed $value ) use ( $facade ) : void
        {
            $p = new \ReflectionProperty( ArangoDB::class , $name ) ;
            $p->setValue( $facade , $value ) ;
        } ;

        $set( 'database' , self::$db ) ;
        $set( 'client'   , self::$client ) ;
        $set( 'logger'   , null ) ;

        return $facade ;
    }

    public function testExplainReportsTheIndexActuallyUsed() :void
    {
        $result = $this->facade()->explain
        (
            'FOR u IN ' . self::COLLECTION . ' FILTER u.age > @a SORT u.name LIMIT 5 RETURN u' ,
            [ 'a' => 30 ] ,
        ) ;

        $this->assertInstanceOf( ExplainResult::class , $result ) ;
        $this->assertSame( [ self::COLLECTION ] , $result->collections() ) ;
        $this->assertContains( 'use-indexes' , $result->rules() ) ;

        $this->assertTrue( $result->usesIndex() , 'the age filter should be index-accelerated' ) ;

        $indexes = $result->indexesUsed() ;
        $this->assertNotEmpty( $indexes ) ;
        $this->assertSame( 'persistent' , $indexes[ 0 ]->type ) ;
        $this->assertSame( self::COLLECTION , $indexes[ 0 ]->collection ) ;
        $this->assertSame( [ 'age' ] , $indexes[ 0 ]->fields ) ;
    }

    public function testExplainOfAFullScanReportsNoIndex() :void
    {
        // Filtering on a non-indexed attribute → no IndexNode, full collection scan.
        $result = $this->facade()->explain
        (
            'FOR u IN ' . self::COLLECTION . ' FILTER u.name == @n RETURN u' ,
            [ 'n' => 'u3' ] ,
        ) ;

        $this->assertFalse( $result->usesIndex() ) ;
        $this->assertSame( [] , $result->indexesUsed() ) ;
    }
}
