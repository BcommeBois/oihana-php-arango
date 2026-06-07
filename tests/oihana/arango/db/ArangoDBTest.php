<?php

namespace tests\oihana\arango\db;

use ReflectionProperty;
use stdClass;

use oihana\arango\clients\ArangoClient;
use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;

use org\schema\Thing;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A plain (non-{@see Thing}) DTO used to exercise the reflection-hydration
 * branch of {@see ArangoDB::hydrateDocument()}.
 */
class ArangoDBPlainDto
{
    public ?string $name = null ;
}

/**
 * Characterization coverage for the {@see ArangoDB} façade core — query
 * execution, result shaping and document hydration — driven over mocked
 * `Database` / `Cursor` / `ArangoClient` collaborators (the constructor, which
 * opens an HTTP client, is bypassed by {@see ArangoDBTestCase}).
 *
 * @package tests\oihana\arango\db
 * @author  Marc Alcaraz
 */
#[CoversClass( ArangoDB::class )]
#[AllowMockObjectsWithoutExpectations]
class ArangoDBTest extends ArangoDBTestCase
{
    // ---- constructor ----------------------------------------------------

    public function testConstructorAppliesBatchSizeAndMaxRuntime() :void
    {
        // construction is offline (the HTTP client connects lazily on the first query)
        $db = new ArangoDB
        ([
            'endpoint'                => 'http://127.0.0.1:8529' ,
            'database'                => 'testdb' ,
            'username'                => 'u' ,
            'password'                => 'p' ,
            ArangoConfig::BATCH_SIZE  => 500 ,
            ArangoConfig::MAX_RUNTIME => 2.5 ,
        ]) ;

        $this->assertSame( 500 , ( new ReflectionProperty( $db , 'batchSize' ) )->getValue( $db ) ) ;
        $this->assertSame( 2.5 , ( new ReflectionProperty( $db , 'maxRuntime' ) )->getValue( $db ) ) ;
    }

    public function testConstructorKeepsDefaultsWithoutOptionalConfig() :void
    {
        $db = new ArangoDB
        ([
            'endpoint' => 'http://127.0.0.1:8529' ,
            'database' => 'testdb' ,
            'username' => 'u' ,
            'password' => 'p' ,
        ]) ;

        $this->assertSame( 10000 , ( new ReflectionProperty( $db , 'batchSize' ) )->getValue( $db ) ) ;
        $this->assertNull( ( new ReflectionProperty( $db , 'maxRuntime' ) )->getValue( $db ) ) ;
    }

    // ---- auth delegation ------------------------------------------------

    public function testLoginDelegatesToClient() :void
    {
        $client = $this->createMock( ArangoClient::class ) ;
        $client->method( 'login' )->with( 'root' , 'secret' )->willReturn( 'jwt-token' ) ;

        $this->assertSame( 'jwt-token' , $this->newArangoDB( null , $client )->login( 'root' , 'secret' ) ) ;
    }

    public function testUseBasicAuthDelegatesToClient() :void
    {
        $client = $this->createMock( ArangoClient::class ) ;
        $client->expects( $this->once() )->method( 'useBasicAuth' )->with( 'root' , 'secret' ) ;

        $this->newArangoDB( null , $client )->useBasicAuth( 'root' , 'secret' ) ;
    }

    public function testUseBearerAuthDelegatesToClient() :void
    {
        $client = $this->createMock( ArangoClient::class ) ;
        $client->expects( $this->once() )->method( 'useBearerAuth' )->with( 'tok' ) ;

        $this->newArangoDB( null , $client )->useBearerAuth( 'tok' ) ;
    }

    // ---- cursor metadata ------------------------------------------------

    public function testGetCursorReturnsTheCurrentCursor() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;

        $this->assertSame( $cursor , $this->newArangoDB( null , null , $cursor )->getCursor() ) ;
    }

    public function testGetFoundRowsReadsTheFullCount() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getFullCount' )->willReturn( 123 ) ;

        $this->assertSame( 123 , $this->newArangoDB( null , null , $cursor )->getFoundRows() ) ;
    }

    public function testGetExtraReadsTheCursorExtra() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getExtra' )->willReturn( [ 'stats' => [ 'fullCount' => 9 ] ] ) ;

        $this->assertSame( [ 'stats' => [ 'fullCount' => 9 ] ] , $this->newArangoDB( null , null , $cursor )->getExtra() ) ;
    }

    // ---- prepare + execute ----------------------------------------------

    public function testExecuteSplitsRootAndNestedOptionsAndStoresCursor() :void
    {
        $cursor   = $this->createMock( Cursor::class ) ;
        $captured = null ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturnCallback
        (
            function( string $query , array $binds , array $options ) use ( &$captured , $cursor ) :Cursor
            {
                $captured = [ $query , $binds , $options ] ;
                return $cursor ;
            }
        ) ;

        $db = $this->newArangoDB( $database ) ;

        $result = $db->prepare
        ([
            CursorField::QUERY        => 'FOR x IN c RETURN x' ,
            CursorField::BIND_VARS    => [ 'a' => 1 ] ,
            CursorField::COUNT        => true ,      // root option
            CursorField::MEMORY_LIMIT => 1000 ,      // root option
            'customOption'            => 'v' ,       // nested under OPTIONS
            'skipped'                 => null ,       // null values are dropped
        ])->execute() ;

        $this->assertSame( $db , $result ) ;
        $this->assertSame( $cursor , $db->getCursor() ) ;

        [ $query , $binds , $root ] = $captured ;

        $this->assertSame( 'FOR x IN c RETURN x' , $query ) ;
        $this->assertSame( [ 'a' => 1 ] , $binds ) ;
        $this->assertTrue( $root[ CursorField::COUNT ] ) ;
        $this->assertSame( 10000 , $root[ CursorField::BATCH_SIZE ] ) ; // injected by prepare()
        $this->assertSame( 1000 , $root[ CursorField::MEMORY_LIMIT ] ) ;
        $this->assertSame( [ 'customOption' => 'v' ] , $root[ CursorField::OPTIONS ] ) ; // nested
        $this->assertArrayNotHasKey( 'skipped' , $root ) ;
    }

    // ---- results --------------------------------------------------------

    public function testGetResultReturnsNullWithoutCursor() :void
    {
        $this->assertNull( $this->newArangoDB()->getResult() ) ;
    }

    public function testGetResultReturnsNullOnEmptyCursor() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [] ) ;

        $this->assertNull( $this->newArangoDB( null , null , $cursor )->getResult() ) ;
    }

    public function testGetResultHydratesArrayRowsToObjects() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ '_key' => 'a' ] , [ '_key' => 'b' ] ] ) ;

        $result = $this->newArangoDB( null , null , $cursor )->getResult() ;

        $this->assertContainsOnlyInstancesOf( stdClass::class , $result ) ;
        $this->assertSame( 'a' , $result[ 0 ]->_key ) ;
    }

    public function testGetDocumentsReturnsEmptyArrayWithoutCursor() :void
    {
        $this->assertSame( [] , $this->newArangoDB()->getDocuments() ) ;
    }

    public function testGetFirstResultReturnsTheFirstRowOrNull() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ '_key' => 'first' ] ] ) ;

        $this->assertSame( 'first' , $this->newArangoDB( null , null , $cursor )->getFirstResult()->_key ) ;
        $this->assertNull( $this->newArangoDB()->getFirstResult() ) ; // no cursor → null
    }

    public function testGetObjectNormalizesToObjectOrNull() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ '_key' => 'x' ] ] ) ;

        $this->assertInstanceOf( stdClass::class , $this->newArangoDB( null , null , $cursor )->getObject() ) ;

        // no cursor → first is null → getObject returns null
        $this->assertNull( $this->newArangoDB()->getObject() ) ;
    }

    public function testGetObjectReturnsNullWhenFirstIsScalar() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ 'a-scalar-row' ] ) ; // string row → passthrough → not object

        $this->assertNull( $this->newArangoDB( null , null , $cursor )->getObject() ) ;
    }

    // ---- streamDocuments ------------------------------------------------

    public function testStreamDocumentsYieldsHydratedRowsThenResetsState() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getIterator' )->willReturnCallback( function() :\Generator
        {
            yield [ '_key' => 'a' ] ;
            yield [ '_key' => 'b' ] ;
        } ) ;

        $db = $this->newArangoDB( null , null , $cursor ) ;

        $rows = iterator_to_array( $db->streamDocuments() ) ;

        $this->assertCount( 2 , $rows ) ;
        $this->assertSame( 'a' , $rows[ 0 ]->_key ) ;

        // the `finally` resets the cursor to null (read via reflection — getCursor()
        // is typed `: Cursor` non-nullable so it cannot be called in this state)
        $cursorProp = new \ReflectionProperty( $db , 'cursor' ) ;
        $this->assertNull( $cursorProp->getValue( $db ) ) ;
    }

    public function testStreamDocumentsIsEmptyWithoutCursor() :void
    {
        $this->assertSame( [] , iterator_to_array( $this->newArangoDB()->streamDocuments() ) ) ;
    }

    // ---- hydrateDocument schema branches --------------------------------

    public function testHydrateWithThingClassSchemaBuildsThings() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'name' => 'X' ] ] ) ;

        $result = $this->newArangoDB( null , null , $cursor )->getResult( Thing::class ) ;

        $this->assertInstanceOf( Thing::class , $result[ 0 ] ) ;
    }

    public function testHydrateWithNonThingClassSchemaUsesReflectionHydration() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'name' => 'Alice' ] ] ) ;

        $result = $this->newArangoDB( null , null , $cursor )->getResult( ArangoDBPlainDto::class ) ;

        $this->assertInstanceOf( ArangoDBPlainDto::class , $result[ 0 ] ) ;
        $this->assertSame( 'Alice' , $result[ 0 ]->name ) ;
    }

    public function testHydrateWithClosureSchemaReturningClass() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'name' => 'Y' ] ] ) ;

        $result = $this->newArangoDB( null , null , $cursor )->getResult( fn( $doc ) => Thing::class ) ;

        $this->assertInstanceOf( Thing::class , $result[ 0 ] ) ;
    }

    public function testHydrateWithClosureSchemaReturningNonStringFallsBackToObject() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'name' => 'Z' ] ] ) ;

        $result = $this->newArangoDB( null , null , $cursor )->getResult( fn( $doc ) => null ) ;

        $this->assertInstanceOf( stdClass::class , $result[ 0 ] ) ;
        $this->assertSame( 'Z' , $result[ 0 ]->name ) ;
    }

    public function testHydrateWithUnknownStringClassFallsBackToObject() :void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'name' => 'W' ] ] ) ;

        // a non-class string schema → neither Thing nor hydratable → object cast
        $result = $this->newArangoDB( null , null , $cursor )->getResult( 'Not\\A\\Real\\Class' ) ;

        $this->assertInstanceOf( stdClass::class , $result[ 0 ] ) ;
    }
}
