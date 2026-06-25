<?php

namespace tests\oihana\arango\models\traits;

use DI\Container;

use Generator;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\ArangoDB;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;

use oihana\models\enums\Alter;

/**
 * Bare host exposing {@see ArangoTrait} for isolated testing. The protected
 * `$arangodb` reference is injected through the constructor so each test can
 * wire a mock ArangoDB (or leave it null to exercise the nullsafe branches).
 */
class ArangoTraitHost
{
    use ArangoTrait ;

    public function __construct( ?ArangoDB $db = null , ?string $collection = null )
    {
        $this->arangodb   = $db ;
        $this->collection = $collection ;
    }

    /** Nullable view on the protected reference (getDatabase() is typed non-nullable). */
    public function rawDatabase() :?ArangoDB
    {
        return $this->arangodb ;
    }
}

/**
 * Characterization coverage for {@see ArangoTrait} — the thin facade the
 * models expose over the ArangoDB client: collection management delegations,
 * the query seams (getDocuments/getFirstResult/getObject/getResult/
 * streamDocuments), prepareAndExecute, registerProperty, and the
 * initializeCollection / initializeDatabase bootstrap helpers.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversTrait( ArangoTrait::class )]
class ArangoTraitTest extends TestCase
{
    /**
     * Builds a mock ArangoDB whose `prepare()` / `execute()` chain returns the
     * mock itself, so the trait's `prepareAndExecute()` works, and whose fetch
     * methods return the supplied canned values.
     */
    private function makeArango( array $stub = [] ) :ArangoDB
    {
        $methods = array_unique( array_merge
        (
            [ 'prepare' , 'execute' ] ,
            array_keys( $stub )
        ) ) ;

        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods( $methods )
            ->getMock() ;

        $db->method( 'prepare' )->willReturnSelf() ;
        $db->method( 'execute' )->willReturnSelf() ;

        foreach ( $stub as $name => $value )
        {
            $db->method( $name )->willReturn( $value ) ;
        }

        return $db ;
    }

    // ---------------------------------------------------------------- collection delegations (nullsafe)

    public function testCollectionDelegationsReturnFalseWhenNoDatabase() :void
    {
        $host = new ArangoTraitHost( null ) ;

        $this->assertFalse( $host->collectionCreate  ( 'c' ) ) ;
        $this->assertFalse( $host->collectionDrop    ( 'c' ) ) ;
        $this->assertFalse( $host->collectionExists  ( 'c' ) ) ;
        $this->assertFalse( $host->collectionRename  ( 'a' , 'b' ) ) ;
        $this->assertFalse( $host->collectionTruncate( 'c' ) ) ;
    }

    public function testCollectionDelegationsForwardToTheDatabase() :void
    {
        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'collectionCreate' , 'collectionDrop' , 'collectionExists' , 'collectionRename' , 'collectionTruncate' ])
            ->getMock() ;

        $db->method( 'collectionCreate'   )->willReturn( true ) ;
        $db->method( 'collectionDrop'     )->willReturn( true ) ;
        $db->method( 'collectionExists'   )->willReturn( true ) ;
        $db->method( 'collectionRename'   )->willReturn( true ) ;
        $db->method( 'collectionTruncate' )->willReturn( true ) ;

        $host = new ArangoTraitHost( $db ) ;

        $this->assertTrue( $host->collectionCreate  ( 'c' ) ) ;
        $this->assertTrue( $host->collectionDrop    ( 'c' ) ) ;
        $this->assertTrue( $host->collectionExists  ( 'c' ) ) ;
        $this->assertTrue( $host->collectionRename  ( 'a' , 'b' ) ) ;
        $this->assertTrue( $host->collectionTruncate( 'c' ) ) ;
    }

    // ---------------------------------------------------------------- simple accessors

    public function testCreateIndexForwardsToTheDatabase() :void
    {
        $db   = $this->makeArango([ 'createIndex' => [ 'id' => 'idx/1' ] ]) ;
        $host = new ArangoTraitHost( $db ) ;

        $this->assertSame( [ 'id' => 'idx/1' ] , $host->createIndex( 'things' , [ 'type' => 'persistent' , 'fields' => [ 'x' ] ] ) ) ;
    }

    public function testFoundRowsExtraAndDatabaseAccessors() :void
    {
        $db   = $this->makeArango([ 'getFoundRows' => 42 , 'getExtra' => [ 'stats' => 1 ] ]) ;
        $host = new ArangoTraitHost( $db ) ;

        $this->assertSame( 42 , $host->foundRows() ) ;
        $this->assertSame( [ 'stats' => 1 ] , $host->getExtra() ) ;
        $this->assertSame( $db , $host->getDatabase() ) ;
    }

    // ---------------------------------------------------------------- query seams

    public function testGetDocumentsReturnsAlteredAndRawResults() :void
    {
        $db   = $this->makeArango([ 'getDocuments' => [ [ 'a' => 1 ] ] ]) ;
        $host = new ArangoTraitHost( $db ) ;

        // no alters configured → alter() is a passthrough
        $this->assertSame( [ [ 'a' => 1 ] ] , $host->getDocuments( 'FOR d IN c RETURN d' ) ) ;
        $this->assertSame( [ [ 'a' => 1 ] ] , $host->getDocuments( 'FOR d IN c RETURN d' , [] , [] , true ) ) ;
    }

    public function testGetFirstResultGetObjectGetResultSeams() :void
    {
        $object = (object) [ 'k' => 'v' ] ;

        $host = new ArangoTraitHost( $this->makeArango
        ([
            'getFirstResult' => 'first' ,
            'getObject'      => $object ,
            'getResult'      => [ 1 , 2 , 3 ] ,
        ]) ) ;

        $this->assertSame( 'first'     , $host->getFirstResult( 'Q' ) ) ;
        $this->assertSame( $object     , $host->getObject( 'Q' ) ) ;
        $this->assertSame( [ 1 , 2 , 3 ] , $host->getResult( 'Q' ) ) ;
    }

    public function testStreamDocumentsYieldsAlteredThenRaw() :void
    {
        $makeGen = static function() :Generator
        {
            yield [ 'a' => 1 ] ;
            yield [ 'b' => 2 ] ;
        };

        // a fresh generator is needed per call (generators are single-use)
        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'prepare' , 'execute' , 'streamDocuments' ])
            ->getMock() ;

        $db->method( 'prepare' )->willReturnSelf() ;
        $db->method( 'execute' )->willReturnSelf() ;
        $db->method( 'streamDocuments' )->willReturnOnConsecutiveCalls( $makeGen() , $makeGen() ) ;

        $host = new ArangoTraitHost( $db ) ;

        $altered = iterator_to_array( $host->streamDocuments( 'Q' ) ) ;          // alter loop branch
        $raw     = iterator_to_array( $host->streamDocuments( 'Q' , [] , [] , true ) ) ; // yield-from branch

        $this->assertSame( [ [ 'a' => 1 ] , [ 'b' => 2 ] ] , $altered ) ;
        $this->assertSame( [ [ 'a' => 1 ] , [ 'b' => 2 ] ] , $raw ) ;
    }

    // ---------------------------------------------------------------- $context forwarded to alter()

    /**
     * Each fetch seam forwards its `$context` argument to the real `alter()`, which
     * hands it to the `Alter::MAP` callbacks as their 6th argument.
     */
    public function testQuerySeamsForwardContextToMapCallbacks() :void
    {
        $seen    = [] ;
        $capture = function( $document , $container , $key , $value , $params , $context = [] ) use ( &$seen )
        {
            $seen[] = $context ;
            return $value ;
        } ;
        $alters = [ 'a' => [ Alter::MAP , $capture ] ] ;
        $ctx    = [ Arango::SKIN => 'full' ] ;

        $makeHost = function( array $stub ) use ( $alters ) :ArangoTraitHost
        {
            $host = new ArangoTraitHost( $this->makeArango( $stub ) ) ;
            $host->container = new Container() ;
            $host->alters    = $alters ;
            return $host ;
        } ;

        $makeHost([ 'getDocuments'   => [ [ 'a' => 1 ] ] ] )->getDocuments  ( 'Q' , [] , [] , false , null , $ctx ) ;
        $makeHost([ 'getObject'      => (object) [ 'a' => 1 ] ] )->getObject( 'Q' , [] , [] , false , null , $ctx ) ;
        $makeHost([ 'getFirstResult' => [ 'a' => 1 ] ] )->getFirstResult    ( 'Q' , [] , [] , false , null , $ctx ) ;
        $makeHost([ 'getResult'      => [ [ 'a' => 1 ] ] ] )->getResult      ( 'Q' , [] , [] , false , null , $ctx ) ;

        // streamDocuments must return a Generator (single-use), not an array.
        $gen = ( static function() { yield [ 'a' => 1 ] ; } )() ;
        iterator_to_array( $makeHost([ 'streamDocuments' => $gen ] )->streamDocuments( 'Q' , [] , [] , false , null , $ctx ) ) ;

        // 5 seams × 1 document each → 5 captured contexts, all equal to the passed $ctx.
        $this->assertSame( [ $ctx , $ctx , $ctx , $ctx , $ctx ] , $seen ) ;
    }

    // ---------------------------------------------------------------- prepareAndExecute / registerProperty

    public function testPrepareAndExecuteReturnsSelf() :void
    {
        $host = new ArangoTraitHost( $this->makeArango() ) ;

        $this->assertSame( $host , $host->prepareAndExecute( 'FOR d IN c RETURN d' , [ 'k' => 1 ] ) ) ;
    }

    public function testRegisterPropertyAppendsBindAndValueExpression() :void
    {
        $host   = new ArangoTraitHost( null ) ;
        $binds  = [] ;
        $values = [] ;

        $host->registerProperty( 'name' , 'Alice' , $binds , $values ) ;

        $this->assertSame( [ 'name: @name' ] , $values ) ;
        $this->assertSame( [ 'name' => 'Alice' ] , $binds ) ;
    }

    // ---------------------------------------------------------------- bootstrap helpers

    public function testInitializeCollectionLazilyCreatesAndRegistersIndexes() :void
    {
        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'collectionExists' , 'collectionCreate' , 'createIndex' ])
            ->getMock() ;

        $db->method( 'collectionExists' )->willReturn( false ) ;   // not there yet → triggers create
        $db->expects( $this->once() )->method( 'collectionCreate' )->willReturn( true ) ;
        $db->expects( $this->once() )->method( 'createIndex' )->willReturn( [ 'id' => 'idx/1' ] ) ;

        $host = new ArangoTraitHost( $db ) ;

        $result = $host->initializeCollection
        ([
            Arango::COLLECTION => 'things' ,
            Arango::INDEXES    => [ [ 'type' => 'persistent' , 'fields' => [ 'x' ] ] ] ,
        ]) ;

        $this->assertSame( $host , $result ) ;
        $this->assertSame( 'things' , $host->collection ) ;
    }

    public function testInitializeIndexesNormalizesASingleIndexOptionsToAList() :void
    {
        $index = new PersistentIndexOptions([ IndexOptions::NAME => 'id' , IndexOptions::FIELDS => [ 'id' ] , IndexOptions::UNIQUE => true ]) ;

        $host   = new ArangoTraitHost( null ) ;
        $result = $host->initializeIndexes([ Arango::INDEXES => $index ]) ;

        $this->assertSame( $host , $result ) ;
        $this->assertSame( [ $index ] , $host->indexes ) ;
    }

    public function testInitializeIndexesKeepsAListAsIs() :void
    {
        $list = [ [ 'type' => 'persistent' , 'fields' => [ 'x' ] ] ] ;

        $host = new ArangoTraitHost( null ) ;
        $host->initializeIndexes([ Arango::INDEXES => $list ]) ;

        $this->assertSame( $list , $host->indexes ) ;
    }

    public function testInitializeCollectionSkipsCreationWhenNotLazy() :void
    {
        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'collectionExists' , 'collectionCreate' ])
            ->getMock() ;

        $db->expects( $this->never() )->method( 'collectionCreate' ) ;

        $host = new ArangoTraitHost( $db ) ;

        $host->initializeCollection([ Arango::COLLECTION => 'things' , Arango::LAZY => false ]) ;

        $this->assertSame( 'things' , $host->collection ) ;
    }

    public function testInitializeDatabaseResolvesTheServiceFromTheContainer() :void
    {
        $db        = $this->makeArango() ;
        $container = new Container() ;
        $container->set( 'arango.db' , $db ) ;

        $host = new ArangoTraitHost( null ) ;

        $result = $host->initializeDatabase( [ Arango::DATABASE => 'arango.db' ] , $container ) ;

        $this->assertSame( $host , $result ) ;
        $this->assertSame( $db , $host->getDatabase() ) ;
    }

    public function testInitializeDatabaseLeavesReferenceUntouchedWhenServiceIsUnknown() :void
    {
        $host = new ArangoTraitHost( null ) ;

        $host->initializeDatabase( [ Arango::DATABASE => 'missing' ] , new Container() ) ;

        $this->assertNull( $host->rawDatabase() ) ;
    }
}
