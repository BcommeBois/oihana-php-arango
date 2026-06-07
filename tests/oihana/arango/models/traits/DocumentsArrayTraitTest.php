<?php

namespace tests\oihana\arango\models\traits;

use Closure;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;
use oihana\arango\models\enums\Side;
use oihana\arango\models\traits\DocumentsArrayTrait;

use oihana\exceptions\UnsupportedOperationException;

use org\schema\helpers\SchemaResolver;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see DocumentsArrayTrait}. It mounts the trait (which pulls
 * ArangoTrait + BindTrait + HasUpdateSignals) and overrides only the three fetch
 * seams — getObject(), getResult(), getFirstResult() — to capture the executed
 * query + binds and return canned results. `bind()` / `bindCollection()` run for
 * real, so the asserted AQL and bind variables are the genuine ones.
 */
class DocumentsArrayTraitStub
{
    use DocumentsArrayTrait ;

    public string  $lastQuery    = '' ;
    public array   $lastBinds    = [] ;
    public ?object $objectResult = null ;
    public mixed   $firstResult  = 0 ;
    public array   $resultRows   = [] ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'Playlist' ;
        $this->initializeArrays
        ([
            Arango::ARRAYS =>
            [
                'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
                'tags'   => ArrayMode::SET ,
                'genres' => ArrayMode::SORTED_SET ,
            ] ,
        ]) ;
    }

    public function getObject( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null ) :?object
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->objectResult ;
    }

    public function getResult( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null ) :?array
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->resultRows ;
    }

    public function getFirstResult( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null ) :mixed
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->firstResult ;
    }

    public function debugQuery( string $method , string $query , ?array $binds ) :void {}
}

/**
 * Characterization coverage for {@see DocumentsArrayTrait}: every method × array
 * mode, the per-field counter, the touch flag, the sortedSet/move guard, and the
 * two return shapes of arrayPurgeRef. The auto-generated `q_\d+` bind names are
 * normalised to a stable sequence so the AQL can be asserted verbatim.
 */
final class DocumentsArrayTraitTest extends TestCase
{
    private function stub() :DocumentsArrayTraitStub
    {
        return new DocumentsArrayTraitStub() ;
    }

    /** Normalise the auto-generated `q_\d+` bind tokens to `q_0`, `q_1`, … in query + binds. */
    private function normalize( string $query , array $binds ) :array
    {
        preg_match_all( '/q_\d+/' , $query , $matches ) ;
        $map = [] ;
        $i   = 0 ;
        foreach ( $matches[ 0 ] as $token )
        {
            if ( !isset( $map[ $token ] ) )
            {
                $map[ $token ] = 'q_' . $i++ ;
            }
        }
        $normBinds = [] ;
        foreach ( $binds as $key => $value )
        {
            $normBinds[ $map[ $key ] ?? $key ] = $value ;
        }
        return [ strtr( $query , $map ) , $normBinds ] ;
    }

    // ---------------------------------------------------------------- arrayInsert

    public function testInsertListAppendsWithCounterAndModified() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => [ 'A' , 'B' ] ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = APPEND(doc.tracks,@q_1) UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => [ 'A' , 'B' ] , '@collection' => 'Playlist' ] , $binds ) ;
    }

    public function testInsertSetIsUniqueAndHasNoCounter() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'jazz' ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = APPEND(doc.tags,@q_1,true) UPDATE doc WITH { tags: __arr, modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        // scalar value is normalised to an array for APPEND
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => [ 'jazz' ] , '@collection' => 'Playlist' ] , $binds ) ;
    }

    public function testInsertSortedSetWrapsInSortedUnique() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'genres' , Arango::VALUE => 'rock' ] ) ;

        $this->assertStringContainsString( 'LET __arr = SORTED_UNIQUE(APPEND(doc.genres,@' , $stub->lastQuery ) ;
    }

    public function testInsertLeftSwapsOperands() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'C' , Arango::SIDE => Side::LEFT ] ) ;

        $this->assertStringContainsString( 'LET __arr = APPEND(@' , $stub->lastQuery ) ;
        $this->assertStringContainsString( ',doc.tracks)' , $stub->lastQuery ) ;
    }

    public function testInsertTouchFalseOmitsModified() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'X' , Arango::TOUCH => false ] ) ;

        $this->assertStringNotContainsString( 'modified' , $stub->lastQuery ) ;
        $this->assertStringContainsString( 'numberOfTracks: LENGTH(__arr)' , $stub->lastQuery ) ;
    }

    public function testInsertUndeclaredFieldDefaultsToListWithoutCounter() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'unknown' , Arango::VALUE => 'A' ] ) ;

        // LIST → APPEND without the `true` unique flag, and no counter field
        $this->assertStringContainsString( 'LET __arr = APPEND(doc.unknown,@' , $stub->lastQuery ) ;
        $this->assertStringNotContainsString( ',true)' , $stub->lastQuery ) ;
        $this->assertStringContainsString( 'UPDATE doc WITH { unknown: __arr, modified:' , $stub->lastQuery ) ;
    }

    public function testInsertModeOverrideForcesUnique() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'unknown' , Arango::VALUE => 'A' , Arango::MODE => ArrayMode::SET ] ) ;

        $this->assertStringContainsString( 'APPEND(doc.unknown,@' , $stub->lastQuery ) ;
        $this->assertStringContainsString( ',true)' , $stub->lastQuery ) ;
    }

    public function testInsertHonoursCustomKeyAttribute() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'abc' , Arango::FIELD => 'tags' , Arango::VALUE => 'x' , Arango::KEY => 'id' ] ) ;

        $this->assertStringContainsString( 'FILTER doc.id == @' , $stub->lastQuery ) ;
    }

    // ---------------------------------------------------------------- arrayRemove

    public function testRemoveScalarUsesRemoveValue() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = REMOVE_VALUE(doc.tracks,@q_1) UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => 'A' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    public function testRemoveArrayUsesRemoveValues() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => [ 'A' , 'B' ] ] ) ;

        $this->assertStringContainsString( 'LET __arr = REMOVE_VALUES(doc.tracks,@' , $stub->lastQuery ) ;
    }

    // ---------------------------------------------------------------- arrayMove

    public function testMoveBuildsSliceReinsertExpression() :void
    {
        $stub = $this->stub() ;
        $stub->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::POSITION => 2 ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __rm = REMOVE_VALUE(doc.tracks,@q_1) LET __arr = APPEND(PUSH(SLICE(__rm,0,2),@q_1,true),SLICE(__rm,2)) UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        // owner + the (single, reused) value bind
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => 'A' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    public function testMoveOnSortedSetThrows() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->stub()->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'genres' , Arango::VALUE => 'rock' , Arango::POSITION => 0 ] ) ;
    }

    // ---------------------------------------------------------------- arrayContains

    public function testContainsBuildsLengthSubquery() :void
    {
        $stub = $this->stub() ;
        $stub->firstResult = 1 ;
        $result = $stub->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'jazz' ] ) ;
        [ $query ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertTrue( $result ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key == @q_0 && POSITION(doc.tags,@q_1) RETURN 1) > 0' ,
            $query ,
        ) ;
    }

    public function testContainsReturnsFalseWhenAbsent() :void
    {
        $stub = $this->stub() ;
        $stub->firstResult = 0 ;
        $this->assertFalse( $stub->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'nope' ] ) ) ;
    }

    // ---------------------------------------------------------------- arrayPurgeRef

    public function testPurgeRefReturnsModifiedDocuments() :void
    {
        $stub = $this->stub() ;
        $stub->resultRows = [ (object) [ '_key' => 'p1' ] , (object) [ '_key' => 'p2' ] ] ;

        $result = $stub->arrayPurgeRef( [ Arango::FIELD => 'tracks' , Arango::VALUE => 'A' ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame( $stub->resultRows , $result ) ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER POSITION(doc.tracks,@q_0) LET __arr = REMOVE_VALUE(doc.tracks,@q_0) UPDATE doc WITH { tracks: __arr, numberOfTracks: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        $this->assertSame( [ 'q_0' => 'A' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    public function testPurgeRefCountReturnsAffectedCount() :void
    {
        $stub = $this->stub() ;
        $stub->resultRows = [ 1 , 1 , 1 ] ; // three lightweight `1` rows

        $result = $stub->arrayPurgeRef( [ Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::COUNT => true ] ) ;

        $this->assertSame( 3 , $result ) ;
        $this->assertStringEndsWith( 'RETURN 1' , $stub->lastQuery ) ;
    }

    // ---------------------------------------------------------------- config + signals

    public function testInitializeArraysNormalisesShorthandAndFullForms() :void
    {
        $stub = $this->stub() ;

        $this->assertSame
        (
            [
                'tracks' => [ Arango::MODE => ArrayMode::LIST       , Arango::COUNTER => 'numberOfTracks' ] ,
                'tags'   => [ Arango::MODE => ArrayMode::SET        , Arango::COUNTER => null ] ,
                'genres' => [ Arango::MODE => ArrayMode::SORTED_SET , Arango::COUNTER => null ] ,
            ] ,
            $stub->arrays ,
        ) ;
    }

    public function testDebugFlagIsHonouredOnEachExecutionPath() :void
    {
        $stub = $this->stub() ;

        // runArrayUpdate (single-doc write), arrayContains and arrayPurgeRef each
        // guard a debugQuery() call behind the debug flag — exercise all three.
        $stub->arrayInsert  ( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::DEBUG => true ] ) ;
        $this->assertStringContainsString( 'UPDATE doc WITH' , $stub->lastQuery ) ;

        $stub->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'jazz' , Arango::DEBUG => true ] ) ;
        $this->assertStringStartsWith( 'RETURN LENGTH(' , $stub->lastQuery ) ;

        $stub->arrayPurgeRef( [ Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::DEBUG => true ] ) ;
        $this->assertStringContainsString( 'FILTER POSITION(doc.tracks' , $stub->lastQuery ) ;
    }

    public function testWriteEmitsUpdateSignals() :void
    {
        $stub = $this->stub() ;
        $stub->initializeUpdateSignals() ;

        $before = 0 ;
        $after  = 0 ;
        $stub->beforeUpdate->connect( function() use ( &$before ) { $before++ ; } ) ;
        $stub->afterUpdate->connect( function() use ( &$after ) { $after++ ; } ) ;

        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' ] ) ;

        $this->assertSame( 1 , $before ) ;
        $this->assertSame( 1 , $after ) ;
    }
}
