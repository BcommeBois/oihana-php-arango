<?php

namespace tests\oihana\arango\models\traits;

use Closure;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;
use oihana\arango\models\enums\Side;
use oihana\arango\models\traits\DocumentsArrayTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use org\schema\helpers\SchemaResolver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use stdClass;
use Throwable;

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
            AQL::ARRAYS =>
            [
                'tracks'   => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
                'tags'     => ArrayMode::SET ,
                'genres'   => ArrayMode::SORTED_SET ,
                'chapters' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfChapters' , Arango::ITEM_KEY => 'id' ] ,
                'lines'    => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfLines'    , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => 'position' ] ,
            ] ,
        ]) ;
    }

    /**
     * Exposes the protected item-key resolver, which nothing else calls yet.
     */
    public function itemKey( ?string $field , array $init = [] ) : ?string
    {
        return $this->arrayItemKey( $field , $init ) ;
    }

    /**
     * Exposes the protected position-key resolver, which no operation honours yet.
     */
    public function positionKey( ?string $field , array $init = [] ) : ?string
    {
        return $this->arrayPositionKey( $field , $init ) ;
    }

    public function getObject( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null , array $context = [] ) :?object
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->objectResult ;
    }

    public function getResult( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null , array $context = [] ) :?array
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->resultRows ;
    }

    public function getFirstResult( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null , array $context = [] ) :mixed
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertSortedSetWrapsInSortedUnique() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'genres' , Arango::VALUE => 'rock' ] ) ;

        $this->assertStringContainsString( 'LET __arr = SORTED_UNIQUE(APPEND(doc.genres,@' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertLeftSwapsOperands() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'C' , Arango::SIDE => Side::LEFT ] ) ;

        $this->assertStringContainsString( 'LET __arr = APPEND(@' , $stub->lastQuery ) ;
        $this->assertStringContainsString( ',doc.tracks)' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertTouchFalseOmitsModified() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'X' , Arango::TOUCH => false ] ) ;

        $this->assertStringNotContainsString( 'modified' , $stub->lastQuery ) ;
        $this->assertStringContainsString( 'numberOfTracks: LENGTH(__arr)' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertUndeclaredFieldDefaultsToListWithoutCounter() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'unknown' , Arango::VALUE => 'A' ] ) ;

        // LIST → APPEND without the `true` unique flag, and no counter field
        $this->assertStringContainsString( 'LET __arr = APPEND(doc.unknown,@' , $stub->lastQuery ) ;
        $this->assertStringNotContainsString( ',true)' , $stub->lastQuery ) ;
        $this->assertStringContainsString( 'UPDATE doc WITH { unknown: __arr, modified:' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertModeOverrideForcesUnique() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'unknown' , Arango::VALUE => 'A' , Arango::MODE => ArrayMode::SET ] ) ;

        $this->assertStringContainsString( 'APPEND(doc.unknown,@' , $stub->lastQuery ) ;
        $this->assertStringContainsString( ',true)' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertHonoursCustomKeyAttribute() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'abc' , Arango::FIELD => 'tags' , Arango::VALUE => 'x' , Arango::KEY => 'id' ] ) ;

        $this->assertStringContainsString( 'FILTER doc.id == @' , $stub->lastQuery ) ;
    }

    /**
     * An item key identifies an *existing* element: an insert carries the whole element,
     * so a by-key field appends exactly like a by-value one.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testInsertIgnoresTheItemKey() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => [ 'id' => 'c1' ] ] ) ;
        [ $query ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = APPEND(doc.chapters,@q_1) UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
    }

    // ---------------------------------------------------------------- arrayRemove

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testRemoveArrayUsesRemoveValues() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => [ 'A' , 'B' ] ] ) ;

        $this->assertStringContainsString( 'LET __arr = REMOVE_VALUES(doc.tracks,@' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testRemoveByItemKeyFiltersOnTheKeyAttribute() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = doc.chapters[* FILTER CURRENT.id != @q_1] UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        // the bound value is the *key* of the element, not the element itself
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => 'c1' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testRemoveByItemKeyAcceptsAListOfKeys() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => [ 'c1' , 'c2' ] ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertStringContainsString( 'LET __arr = doc.chapters[* FILTER CURRENT.id NOT IN @q_1]' , $query ) ;
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => [ 'c1' , 'c2' ] , '@collection' => 'Playlist' ] , $binds ) ;
    }

    /**
     * A field declared by value can still be targeted by key for a single call.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testRemoveHonoursThePerCallItemKeyOverride() :void
    {
        $stub = $this->stub() ;
        $stub->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::ITEM_KEY => 'uid' ] ) ;

        $this->assertStringContainsString( 'LET __arr = doc.tracks[* FILTER CURRENT.uid != @' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testRemoveRejectsAnUnsafeItemKey() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->arrayRemove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' , Arango::ITEM_KEY => 'id"' ] ) ;
    }

    // ---------------------------------------------------------------- arrayMove

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testMoveOnSortedSetThrows() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->stub()->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'genres' , Arango::VALUE => 'rock' , Arango::POSITION => 0 ] ) ;
    }

    /**
     * The by-key move resolves the element first, then reorders — and guards the whole
     * rebuild on that lookup so an unknown key never inserts a null.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testMoveByItemKeyResolvesTheElementAndGuardsOnIt() :void
    {
        $stub = $this->stub() ;
        $stub->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' , Arango::POSITION => 2 ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0'
          . ' LET __el = FIRST(doc.chapters[* FILTER CURRENT.id == @q_1])'
          . ' LET __rm = doc.chapters[* FILTER CURRENT.id != @q_1]'
          . ' LET __arr = __el == null ? doc.chapters : APPEND(PUSH(SLICE(__rm,0,2),__el,true),SLICE(__rm,2))'
          . ' UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => 'c1' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    /**
     * The by-value move keeps a single LET pair and no element lookup.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testMoveByValueDeclaresNoElementVariable() :void
    {
        $stub = $this->stub() ;
        $stub->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::POSITION => 0 ] ) ;

        $this->assertStringNotContainsString( '__el' , $stub->lastQuery ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testMoveRejectsAnUnsafeItemKey() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->arrayMove( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::ITEM_KEY => 'id[*]' ] ) ;
    }

    // ---------------------------------------------------------------- arrayUpdate

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateMergesThePatchIntoTheKeyedElement() :void
    {
        $stub = $this->stub() ;
        $stub->arrayUpdate
        ([
            Arango::OWNER => 'p42' ,
            Arango::FIELD => 'chapters' ,
            Arango::VALUE => 'c1' ,
            Arango::PATCH => [ 'rating' => 5 ] ,
        ]) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0'
          . ' LET __arr = doc.chapters[* RETURN CURRENT.id == @q_1 ? MERGE(CURRENT,@q_2) : CURRENT]'
          . ' UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
        $this->assertSame( 'p42' , $binds[ 'q_0' ] ) ;
        $this->assertSame( 'c1'  , $binds[ 'q_1' ] ) ;
        // the patch is bound as an object, so MERGE() always receives an object
        $this->assertEquals( (object) [ 'rating' => 5 ] , $binds[ 'q_2' ] ) ;
    }

    /**
     * An absent patch must still reach AQL as `{}` — `[]` would not be a valid MERGE operand.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateBindsAnEmptyPatchAsAnObject() :void
    {
        $stub = $this->stub() ;
        $stub->arrayUpdate( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ) ;
        [ , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertEquals( new stdClass() , $binds[ 'q_2' ] ) ;
        $this->assertSame( '{}' , json_encode( $binds[ 'q_2' ] ) ) ;
    }

    /**
     * A field targeted by value cannot be edited in place — designating its element
     * would need a byte-for-byte copy that the patch itself invalidates.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateWithoutAnItemKeyThrows() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->stub()->arrayUpdate( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::PATCH => [ 'rating' => 5 ] ] ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateHonoursThePerCallItemKeyOverride() :void
    {
        $stub = $this->stub() ;
        $stub->arrayUpdate( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::ITEM_KEY => 'uid' , Arango::PATCH => [ 'rating' => 5 ] ] ) ;

        $this->assertStringContainsString( 'LET __arr = doc.tracks[* RETURN CURRENT.uid == @' , $stub->lastQuery ) ;
    }

    /**
     * A patch can make two elements equal, so the field invariant is re-applied.
     *
     * @param string $field
     * @param string $expected
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    #[DataProvider( 'updateInvariantProvider' )]
    public function testUpdateReappliesTheFieldInvariant( string $field , string $expected ) :void
    {
        $stub = $this->stub() ;
        $stub->arrayUpdate
        ([
            Arango::OWNER    => 'p42' ,
            Arango::FIELD    => $field ,
            Arango::VALUE    => 'x' ,
            Arango::ITEM_KEY => 'id' ,
            Arango::PATCH    => [ 'rating' => 5 ] ,
        ]) ;

        $this->assertStringContainsString( $expected , $stub->lastQuery ) ;
    }

    public static function updateInvariantProvider() :array
    {
        return
        [
            'list wraps nothing' => [ 'tracks' , 'LET __arr = doc.tracks[* RETURN'          ] ,
            'set is unique'      => [ 'tags'   , 'LET __arr = UNIQUE(doc.tags[* RETURN'     ] ,
            'sortedSet is both'  => [ 'genres' , 'LET __arr = SORTED_UNIQUE(doc.genres[* RETURN' ] ,
        ] ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateRejectsAnUnsafeItemKey() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->arrayUpdate( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' , Arango::ITEM_KEY => 'id != null REMOVE doc IN Playlist //' ] ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testUpdateEmitsUpdateSignalsAndForwardsOptions() :void
    {
        $stub = $this->stub() ;
        $stub->initializeUpdateSignals() ;

        $before = 0 ;
        $after  = 0 ;
        $stub->beforeUpdate->connect( function() use ( &$before ) { $before++ ; } ) ;
        $stub->afterUpdate ->connect( function() use ( &$after  ) { $after++  ; } ) ;

        $stub->arrayUpdate
        ([
            Arango::OWNER   => 'p42' ,
            Arango::FIELD   => 'chapters' ,
            Arango::VALUE   => 'c1' ,
            Arango::PATCH   => [ 'rating' => 5 ] ,
            Arango::TOUCH   => false ,
            Arango::OPTIONS => [ 'keepNull' => false ] ,
            Arango::DEBUG   => true ,
        ]) ;

        $this->assertSame( 1 , $before ) ;
        $this->assertSame( 1 , $after  ) ;
        $this->assertStringNotContainsString( 'modified' , $stub->lastQuery ) ;
        $this->assertStringContainsString( 'OPTIONS {"keepNull":false}' , $stub->lastQuery ) ;
    }

    // ---------------------------------------------------------------- arrayContains

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testContainsReturnsFalseWhenAbsent() :void
    {
        $stub = $this->stub() ;
        $stub->firstResult = 0 ;
        $this->assertFalse( $stub->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'nope' ] ) ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testContainsByItemKeyUsesTheQuestionMarkOperator() :void
    {
        $stub = $this->stub() ;
        $stub->firstResult = 1 ;
        $result = $stub->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ) ;
        [ $query , $binds ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertTrue( $result ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._key == @q_0 && doc.chapters[? FILTER CURRENT.id == @q_1] RETURN 1) > 0' ,
            $query ,
        ) ;
        $this->assertSame( [ 'q_0' => 'p42' , 'q_1' => 'c1' , '@collection' => 'Playlist' ] , $binds ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testContainsRejectsAnUnsafeItemKey() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->arrayContains( [ Arango::OWNER => 'p42' , Arango::FIELD => 'tags' , Arango::VALUE => 'x' , Arango::ITEM_KEY => 'my id' ] ) ;
    }

    // ---------------------------------------------------------------- arrayPurgeRef

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    /**
     * A collection-wide purge stays structural: it matches the reference itself and
     * ignores any declared item key.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testPurgeRefIgnoresTheItemKey() :void
    {
        $stub = $this->stub() ;
        $stub->arrayPurgeRef( [ Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ) ;
        [ $query ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER POSITION(doc.chapters,@q_0) LET __arr = REMOVE_VALUE(doc.chapters,@q_0) UPDATE doc WITH { chapters: __arr, numberOfChapters: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testPurgeRefCountReturnsAffectedCount() :void
    {
        $stub = $this->stub() ;
        $stub->resultRows = [ 1 , 1 , 1 ] ; // three lightweight `1` rows

        $result = $stub->arrayPurgeRef( [ Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::COUNT => true ] ) ;

        $this->assertSame( 3 , $result ) ;
        $this->assertStringEndsWith( 'RETURN 1' , $stub->lastQuery ) ;
    }

    // ---------------------------------------------------------------- config + signals

    public function testArrayDefaultsSeedsFieldsAndCounters() :void
    {
        $this->assertSame
        (
            [ 'tracks' => [] , 'numberOfTracks' => 0 , 'tags' => [] , 'genres' => [] , 'chapters' => [] , 'numberOfChapters' => 0 , 'lines' => [] , 'numberOfLines' => 0 ] ,
            $this->stub()->arrayDefaults() ,
        ) ;
    }

    public function testInitializeArraysNormalisesShorthandAndFullForms() :void
    {
        $stub = $this->stub() ;

        $this->assertSame
        (
            [
                'tracks'   => [ Arango::MODE => ArrayMode::LIST       , Arango::COUNTER => 'numberOfTracks'   , Arango::ITEM_KEY => null , Arango::POSITION_KEY => null       ] ,
                'tags'     => [ Arango::MODE => ArrayMode::SET        , Arango::COUNTER => null               , Arango::ITEM_KEY => null , Arango::POSITION_KEY => null       ] ,
                'genres'   => [ Arango::MODE => ArrayMode::SORTED_SET , Arango::COUNTER => null               , Arango::ITEM_KEY => null , Arango::POSITION_KEY => null       ] ,
                'chapters' => [ Arango::MODE => ArrayMode::LIST       , Arango::COUNTER => 'numberOfChapters' , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => null       ] ,
                'lines'    => [ Arango::MODE => ArrayMode::LIST       , Arango::COUNTER => 'numberOfLines'    , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => 'position' ] ,
            ] ,
            $stub->arrays ,
        ) ;
    }

    // ---------------------------------------------------------------- arrayItemKey

    public function testItemKeyIsNullWhenTheFieldDeclaresNone() :void
    {
        $stub = $this->stub() ;

        $this->assertNull( $stub->itemKey( 'tracks'    ) ) ; // declared, by value
        $this->assertNull( $stub->itemKey( 'undefined' ) ) ; // not declared at all
        $this->assertNull( $stub->itemKey( null        ) ) ;
    }

    public function testItemKeyIsResolvedFromTheFieldConfiguration() :void
    {
        $this->assertSame( 'id' , $this->stub()->itemKey( 'chapters' ) ) ;
    }

    public function testItemKeyHonoursThePerCallOverride() :void
    {
        $stub = $this->stub() ;

        $this->assertSame( 'uid' , $stub->itemKey( 'chapters' , [ Arango::ITEM_KEY => 'uid' ] ) ) ;
        $this->assertSame( 'uid' , $stub->itemKey( 'tracks'   , [ Arango::ITEM_KEY => 'uid' ] ) ) ;
    }

    public function testItemKeyAcceptsADottedAttributeName() :void
    {
        $this->assertSame( 'meta.id' , $this->stub()->itemKey( 'chapters' , [ Arango::ITEM_KEY => 'meta.id' ] ) ) ;
    }

    /**
     * The item key is interpolated verbatim into the generated AQL, so an unsafe
     * attribute name must be rejected before it ever reaches a query.
     *
     * @param mixed $itemKey
     *
     * @return void
     */
    #[DataProvider( 'unsafeItemKeyProvider' )]
    public function testItemKeyRejectsAnUnsafeAttributeName( mixed $itemKey ) :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->itemKey( 'chapters' , [ Arango::ITEM_KEY => $itemKey ] ) ;
    }

    public static function unsafeItemKeyProvider() :array
    {
        return
        [
            'injection'    => [ 'id != null REMOVE doc IN Playlist //' ] ,
            'quote'        => [ 'id"' ] ,
            'bracket'      => [ 'id[*]' ] ,
            'space'        => [ 'my id' ] ,
            'empty'        => [ '' ] ,
            'leading digit'=> [ '1id' ] ,
            'trailing dot' => [ 'meta.' ] ,
        ] ;
    }

    // ---------------------------------------------------------------- arrayPositionKey

    public function testPositionKeyIsNullWhenTheFieldDeclaresNone() :void
    {
        $stub = $this->stub() ;

        $this->assertNull( $stub->positionKey( 'chapters'  ) ) ; // keyed, but never renumbered
        $this->assertNull( $stub->positionKey( 'tracks'    ) ) ;
        $this->assertNull( $stub->positionKey( 'undefined' ) ) ; // not declared at all
        $this->assertNull( $stub->positionKey( null        ) ) ;
    }

    public function testPositionKeyIsResolvedFromTheFieldConfiguration() :void
    {
        $this->assertSame( 'position' , $this->stub()->positionKey( 'lines' ) ) ;
    }

    public function testPositionKeyHonoursThePerCallOverride() :void
    {
        $stub = $this->stub() ;

        $this->assertSame( 'rank' , $stub->positionKey( 'lines'  , [ Arango::POSITION_KEY => 'rank' ] ) ) ;
        $this->assertSame( 'rank' , $stub->positionKey( 'tracks' , [ Arango::POSITION_KEY => 'rank' ] ) ) ;
    }

    /**
     * The position key is **written back** into every element, where an item key is only
     * ever read: a dotted path would create one attribute literally named `meta.position`
     * instead of a nested one, so it is refused rather than silently honoured.
     *
     * @return void
     */
    public function testPositionKeyRejectsADottedAttributeName() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->positionKey( 'lines' , [ Arango::POSITION_KEY => 'meta.position' ] ) ;
    }

    /**
     * The position key is interpolated verbatim into the generated AQL, so an unsafe
     * attribute name must be rejected before it ever reaches a query.
     *
     * @param mixed $positionKey
     *
     * @return void
     */
    #[DataProvider( 'unsafeItemKeyProvider' )]
    public function testPositionKeyRejectsAnUnsafeAttributeName( mixed $positionKey ) :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->stub()->positionKey( 'lines' , [ Arango::POSITION_KEY => $positionKey ] ) ;
    }

    /**
     * Nothing honours the marker yet: a field declaring a position key generates exactly
     * the AQL it would generate without one.
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testPositionKeyDoesNotChangeTheGeneratedAqlYet() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert( [ Arango::OWNER => 'p42' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'id' => 'l1' ] ] ) ;
        [ $query , ] = $this->normalize( $stub->lastQuery , $stub->lastBinds ) ;

        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @q_0 LET __arr = APPEND(doc.lines,@q_1) UPDATE doc WITH { lines: __arr, numberOfLines: LENGTH(__arr), modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW' ,
            $query ,
        ) ;
    }

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
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

    /**
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    public function testWriteForwardsUpdateOptions() :void
    {
        $stub = $this->stub() ;
        $stub->arrayInsert
        ([
            Arango::OWNER   => 'p42' ,
            Arango::FIELD   => 'tracks' ,
            Arango::VALUE   => 'A' ,
            Arango::OPTIONS => [ 'keepNull' => false ] ,
        ]) ;

        $this->assertStringContainsString( 'OPTIONS {"keepNull":false}' , $stub->lastQuery ) ;
    }

    public function testPurgeRefForwardsUpdateOptions() :void
    {
        $stub = $this->stub() ;
        $stub->arrayPurgeRef
        ([
            Arango::FIELD   => 'tracks' ,
            Arango::VALUE   => 'A' ,
            Arango::OPTIONS => [ 'keepNull' => false ] ,
        ]) ;

        $this->assertStringContainsString( 'OPTIONS {"keepNull":false}' , $stub->lastQuery ) ;
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
