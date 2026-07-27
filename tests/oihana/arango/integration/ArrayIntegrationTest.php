<?php

namespace tests\oihana\arango\integration;


use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use Throwable;

use Devium\Toml\TomlError;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\ArrayMode;

use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use PHPUnit\Framework\Attributes\Group;


use function oihana\init\initConfig;

/**
 * Live integration coverage for {@see DocumentsArrayTrait}
 * against a real ArangoDB — it proves the behaviours unit tests cannot (they only
 * assert the generated AQL): set dedup, sorted-set ordering, empty `[]` (not null),
 * positional move, membership, object (deep-equality) values, the `[]` seeding on
 * insert, and the collection-wide `arrayPurgeRef`.
 *
 * It also covers the `Arango::ITEM_KEY` branches, where the whole point is that the
 * server does the matching: an element is designated by an attribute it carries rather
 * than by a copy of itself. What only a real engine can answer here — the `[? FILTER]`
 * membership operator, `MERGE()` receiving a bound object (including an empty one), the
 * `__el == null` guard leaving the array untouched instead of pushing a null, and
 * `UNIQUE()` / `SORTED_UNIQUE()` re-applied to *objects* after a patch.
 *
 * The ranked field (`lines`, declaring an `Arango::POSITION_KEY`) closes the loop a drag
 * and drop needs: every write renumbers the whole array, and only the engine can tell
 * that `0 .. LENGTH([]) - 1` is a **descending** range — the empty-array guard is what
 * keeps two phantom `null` out of an emptied document.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ArrayIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_array_it' ;

    private const string COLLECTION = 'playlist' ;

    /**
     * @param Database $db
     * @return void
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::COLLECTION )->create() ;
    }

    /**
     * A Documents model wired to the disposable database, with the by-value fields
     * (`tracks`/`tags`/`genres`/`members`) and the by-key ones (`chapters`/`badges`/`ranks`)
     * declared side by side.
     *
     * @return Documents
     * @throws TomlError
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
            AQL::ARRAYS      =>
            [
                'tracks'   => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
                'tags'     => ArrayMode::SET ,
                'genres'   => ArrayMode::SORTED_SET ,
                'members'  => ArrayMode::LIST , // arrays of objects

                // targeted by key: the element is designated by its `id` attribute
                'chapters' => [ ArrayMode::LIST       , Arango::COUNTER => 'numberOfChapters' , Arango::ITEM_KEY => 'id' ] ,
                'badges'   => [ ArrayMode::SET        , Arango::ITEM_KEY => 'id' ] ,
                'ranks'    => [ ArrayMode::SORTED_SET , Arango::ITEM_KEY => 'id' ] ,

                // ranked: every write renumbers `position` from the element indices
                'lines'    => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfLines' , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => 'position' ] ,
            ],
        ]);
    }

    /**
     * Inserts a raw seed document with a fixed key and returns the model.
     * @param array $doc
     * @return Documents
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws TomlError
     * @throws ReflectionException
     * @throws Throwable
     * @throws ArangoException
     */
    private function seedDoc( array $doc ) :Documents
    {
        self::$db->collection( self::COLLECTION )->insert( $doc ) ;
        return $this->model() ;
    }

    /**
     * Re-reads a document as an associative array.
     * @throws ArangoException
     */
    private function doc( string $key ) :array
    {
        $cursor = self::$db->query( 'FOR d IN ' . self::COLLECTION . ' FILTER d._key == @k RETURN d' , [ 'k' => $key ] ) ;
        return (array) iterator_to_array( $cursor , false )[ 0 ] ;
    }

    // ---------------------------------------------------------------- insert

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testListInsertKeepsOrderAndCounter() :void
    {
        $model = $this->seedDoc( [ '_key' => 'list1' , 'tracks' => [ 'A' , 'B' ] , 'numberOfTracks' => 2 ] ) ;

        $new = $model->arrayInsert( [ Arango::OWNER => 'list1' , Arango::FIELD => 'tracks' , Arango::VALUE => 'C' ] ) ;

        $this->assertSame( [ 'A' , 'B' , 'C' ] , $new->tracks ) ;
        $this->assertSame( 3 , $new->numberOfTracks ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testSetInsertDeduplicates() :void
    {
        $model = $this->seedDoc( [ '_key' => 'set1' , 'tags' => [ 'jazz' ] ] ) ;

        $model->arrayInsert( [ Arango::OWNER => 'set1' , Arango::FIELD => 'tags' , Arango::VALUE => 'jazz' ] ) ; // duplicate
        $new = $model->arrayInsert( [ Arango::OWNER => 'set1' , Arango::FIELD => 'tags' , Arango::VALUE => 'rock' ] ) ;

        $this->assertSame( [ 'jazz' , 'rock' ] , $new->tags ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testSortedSetInsertKeepsValuesSortedAndUnique() :void
    {
        $model = $this->seedDoc( [ '_key' => 'sorted1' , 'genres' => [] ] ) ;

        $model->arrayInsert( [ Arango::OWNER => 'sorted1' , Arango::FIELD => 'genres' , Arango::VALUE => 'rock'  ] ) ;
        $model->arrayInsert( [ Arango::OWNER => 'sorted1' , Arango::FIELD => 'genres' , Arango::VALUE => 'blues' ] ) ;
        $new = $model->arrayInsert( [ Arango::OWNER => 'sorted1' , Arango::FIELD => 'genres' , Arango::VALUE => 'jazz' ] ) ;

        $this->assertSame( [ 'blues' , 'jazz' , 'rock' ] , $new->genres ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws UnsupportedOperationException
     * @throws Error409
     */
    public function testInsertSeedsDeclaredArrayFieldsOnCreation() :void
    {
        $new = $this->model()->insert( [ Arango::DOC => [ '_key' => 'seeded1' , 'name' => 'Marc' ] ] ) ;

        $this->assertSame( [] , $new->tracks ) ;
        $this->assertSame( 0 , $new->numberOfTracks ) ;
        $this->assertSame( [] , $new->tags ) ;
    }

    // ---------------------------------------------------------------- remove / move

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testRemoveLastElementLeavesAnEmptyArrayNotNull() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rm1' , 'tracks' => [ 'A' ] , 'numberOfTracks' => 1 ] ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'rm1' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' ] ) ;

        $this->assertSame( [] , $new->tracks ) ;
        $this->assertSame( 0 , $new->numberOfTracks ) ;
        $this->assertArrayHasKey( 'tracks' , $this->doc( 'rm1' ) ) ; // field still present, not dropped
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testMoveRepositionsTheElement() :void
    {
        $model = $this->seedDoc( [ '_key' => 'mv1' , 'tracks' => [ 'A' , 'B' , 'C' , 'D' ] , 'numberOfTracks' => 4 ] ) ;

        $new = $model->arrayMove( [ Arango::OWNER => 'mv1' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::POSITION => 2 ] ) ;

        $this->assertSame( [ 'B' , 'C' , 'A' , 'D' ] , $new->tracks ) ;
    }

    // ---------------------------------------------------------------- contains

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testContainsReflectsRealMembership() :void
    {
        $model = $this->seedDoc( [ '_key' => 'has1' , 'tags' => [ 'jazz' , 'rock' ] ] ) ;

        $this->assertTrue ( $model->arrayContains( [ Arango::OWNER => 'has1' , Arango::FIELD => 'tags' , Arango::VALUE => 'jazz' ] ) ) ;
        $this->assertFalse( $model->arrayContains( [ Arango::OWNER => 'has1' , Arango::FIELD => 'tags' , Arango::VALUE => 'metal' ] ) ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testObjectValuesUseDeepEquality() :void
    {
        $member = [ 'id' => 7 , 'role' => 'dj' ] ;
        $model  = $this->seedDoc( [ '_key' => 'obj1' , 'members' => [ $member ] ] ) ;

        // POSITION / REMOVE_VALUE compare objects by value, not by reference.
        $this->assertTrue( $model->arrayContains( [ Arango::OWNER => 'obj1' , Arango::FIELD => 'members' , Arango::VALUE => $member ] ) ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'obj1' , Arango::FIELD => 'members' , Arango::VALUE => $member ] ) ;
        $this->assertSame( [] , $new->members ) ;
    }

    // ---------------------------------------------------------------- by item key

    /**
     * Three chapters, each an object carrying an `id`.
     *
     * @return array
     */
    private static function chapters() :array
    {
        return
        [
            [ 'id' => 'c1' , 'title' => 'Intro'  , 'rating' => 3 ] ,
            [ 'id' => 'c2' , 'title' => 'Chorus' , 'rating' => 4 ] ,
            [ 'id' => 'c3' , 'title' => 'Outro'  , 'rating' => 5 ] ,
        ] ;
    }

    /**
     * The whole point of an item key: the element is found by an attribute it carries,
     * without the caller holding a copy of it.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testContainsByItemKeyFindsAnObjectFromItsKeyAlone() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-has' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $this->assertTrue ( $model->arrayContains( [ Arango::OWNER => 'k-has' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c2' ] ) ) ;
        $this->assertFalse( $model->arrayContains( [ Arango::OWNER => 'k-has' , Arango::FIELD => 'chapters' , Arango::VALUE => 'nope' ] ) ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testRemoveByItemKeyDropsTheElementAndUpdatesTheCounter() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-rm' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'k-rm' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c2' ] ) ;

        $this->assertSame( [ 'c1' , 'c3' ] , array_column( $new->chapters , 'id' ) ) ;
        $this->assertSame( 2 , $new->numberOfChapters ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testRemoveByItemKeyAcceptsAListOfKeys() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-rml' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'k-rml' , Arango::FIELD => 'chapters' , Arango::VALUE => [ 'c1' , 'c3' ] ] ) ;

        $this->assertSame( [ 'c2' ] , array_column( $new->chapters , 'id' ) ) ;
        $this->assertSame( 1 , $new->numberOfChapters ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testMoveByItemKeyRepositionsTheWholeObject() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-mv' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayMove( [ Arango::OWNER => 'k-mv' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' , Arango::POSITION => 2 ] ) ;

        $this->assertSame( [ 'c2' , 'c3' , 'c1' ] , array_column( $new->chapters , 'id' ) ) ;
        // the object travelled whole, not just its key
        $this->assertSame( 'Intro' , $new->chapters[ 2 ][ 'title' ] ) ;
        $this->assertSame( 3 , $new->numberOfChapters ) ;
    }

    /**
     * The `__el == null` guard: an unknown key rewrites the array **unchanged** instead
     * of pushing a null at the requested position.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testMoveByAnUnknownItemKeyIsANoOp() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-mv0' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayMove( [ Arango::OWNER => 'k-mv0' , Arango::FIELD => 'chapters' , Arango::VALUE => 'nope' , Arango::POSITION => 0 ] ) ;

        $this->assertSame( [ 'c1' , 'c2' , 'c3' ] , array_column( $new->chapters , 'id' ) ) ;
        $this->assertNotContains( null , $new->chapters ) ; // no phantom element
        $this->assertSame( 3 , $new->numberOfChapters ) ;
    }

    /**
     * The same guard on an **empty** array — the degenerate case where `FIRST()` of an
     * empty expansion is null and `SLICE()` has nothing to rebuild from.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testMoveByItemKeyOnAnEmptyArrayIsANoOp() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-mv-empty' , 'chapters' => [] , 'numberOfChapters' => 0 ] ) ;

        $new = $model->arrayMove( [ Arango::OWNER => 'k-mv-empty' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' , Arango::POSITION => 0 ] ) ;

        $this->assertSame( [] , $new->chapters ) ;
        $this->assertSame( 0 , $new->numberOfChapters ) ;
    }

    // ---------------------------------------------------------------- update

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateMergesPartiallyAndLeavesTheSiblingsAlone() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-up' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayUpdate
        ([
            Arango::OWNER => 'k-up' ,
            Arango::FIELD => 'chapters' ,
            Arango::VALUE => 'c2' ,
            Arango::PATCH => [ 'rating' => 9 , 'note' => 'live' ] ,
        ]) ;

        $edited = $new->chapters[ 1 ] ;

        $this->assertSame( 9 , $edited[ 'rating' ] ) ;       // overwritten
        $this->assertSame( 'live' , $edited[ 'note' ] ) ;    // added
        $this->assertSame( 'Chorus' , $edited[ 'title' ] ) ; // untouched — the merge is partial
        $this->assertSame( 'c2' , $edited[ 'id' ] ) ;

        // order and siblings are preserved
        $this->assertSame( [ 'c1' , 'c2' , 'c3' ] , array_column( $new->chapters , 'id' ) ) ;
        $this->assertSame( 3 , $new->chapters[ 0 ][ 'rating' ] ) ;
        $this->assertSame( 3 , $new->numberOfChapters ) ;
    }

    /**
     * The very trap the item key closes: re-sending the same patch keeps working,
     * where a by-value match would stop matching after the first call.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateIsRepeatable() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-up2' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;
        $init  = [ Arango::OWNER => 'k-up2' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ;

        $model->arrayUpdate( [ ...$init , Arango::PATCH => [ 'rating' => 7 ] ] ) ;
        $new = $model->arrayUpdate( [ ...$init , Arango::PATCH => [ 'rating' => 8 ] ] ) ;

        $this->assertSame( 8 , $new->chapters[ 0 ][ 'rating' ] ) ;
    }

    /**
     * An empty patch reaches AQL as `{}` thanks to the (object) cast — `[]` would not be
     * a valid MERGE operand and the query would fail outright.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateWithAnEmptyPatchIsAHarmlessNoOp() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-up-empty' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayUpdate( [ Arango::OWNER => 'k-up-empty' , Arango::FIELD => 'chapters' , Arango::VALUE => 'c1' ] ) ;

        $this->assertSame( [ 'c1' , 'c2' , 'c3' ] , array_column( $new->chapters , 'id' ) ) ;
        $this->assertSame( 3 , $new->chapters[ 0 ][ 'rating' ] ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateByAnUnknownItemKeyLeavesTheArrayUntouched() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-up0' , 'chapters' => self::chapters() , 'numberOfChapters' => 3 ] ) ;

        $new = $model->arrayUpdate
        ([
            Arango::OWNER => 'k-up0' ,
            Arango::FIELD => 'chapters' ,
            Arango::VALUE => 'nope' ,
            Arango::PATCH => [ 'rating' => 9 ] ,
        ]) ;

        $this->assertSame( [ 3 , 4 , 5 ] , array_column( $new->chapters , 'rating' ) ) ;
        $this->assertSame( 3 , $new->numberOfChapters ) ;
    }

    /**
     * A patch can make two elements identical: the SET invariant is re-applied on top,
     * so the duplicate collapses instead of surviving.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateReappliesTheSetInvariantOnObjects() :void
    {
        $model = $this->seedDoc
        ([
            '_key'   => 'k-set' ,
            'badges' => [ [ 'id' => 'b1' , 'level' => 1 ] , [ 'id' => 'b1' , 'level' => 2 ] ] ,
        ]) ;

        // levelling b1/2 down to 1 makes it identical to its sibling
        $new = $model->arrayUpdate
        ([
            Arango::OWNER => 'k-set' ,
            Arango::FIELD => 'badges' ,
            Arango::VALUE => 'b1' ,
            Arango::PATCH => [ 'level' => 1 ] ,
        ]) ;

        $this->assertCount( 1 , $new->badges ) ;
        $this->assertSame( 1 , $new->badges[ 0 ][ 'level' ] ) ;
    }

    /**
     * Same for a sortedSet: `SORTED_UNIQUE()` accepts objects, and orders them.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testUpdateReappliesTheSortedSetInvariantOnObjects() :void
    {
        $model = $this->seedDoc
        ([
            '_key'  => 'k-sorted' ,
            'ranks' => [ [ 'id' => 'r2' ] , [ 'id' => 'r1' ] ] ,
        ]) ;

        // the patch is empty of consequence: what is proved here is that the invariant runs
        $new = $model->arrayUpdate
        ([
            Arango::OWNER => 'k-sorted' ,
            Arango::FIELD => 'ranks' ,
            Arango::VALUE => 'r2' ,
            Arango::PATCH => [ 'id' => 'r1' ] , // r2 becomes r1 → duplicate
        ]) ;

        $this->assertCount( 1 , $new->ranks ) ;
        $this->assertSame( 'r1' , $new->ranks[ 0 ][ 'id' ] ) ;
    }

    /**
     * A field targeted by value refuses the in-place edit rather than emitting an
     * operation that would only work once.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testUpdateOnAByValueFieldThrows() :void
    {
        $model = $this->seedDoc( [ '_key' => 'k-up-novalue' , 'tracks' => [ 'A' ] , 'numberOfTracks' => 1 ] ) ;

        $this->expectException( UnsupportedOperationException::class ) ;
        $model->arrayUpdate( [ Arango::OWNER => 'k-up-novalue' , Arango::FIELD => 'tracks' , Arango::VALUE => 'A' , Arango::PATCH => [ 'x' => 1 ] ] ) ;
    }

    // ---------------------------------------------------------------- purgeRef

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testPurgeRefRemovesValueFromEveryDocument() :void
    {
        self::$db->collection( self::COLLECTION )->insert( [ '_key' => 'pg1' , 'tracks' => [ 'X' , 'Y' ] , 'numberOfTracks' => 2 ] ) ;
        self::$db->collection( self::COLLECTION )->insert( [ '_key' => 'pg2' , 'tracks' => [ 'X' ]       , 'numberOfTracks' => 1 ] ) ;
        self::$db->collection( self::COLLECTION )->insert( [ '_key' => 'pg3' , 'tracks' => [ 'Z' ]       , 'numberOfTracks' => 1 ] ) ;

        $count = $this->model()->arrayPurgeRef( [ Arango::FIELD => 'tracks' , Arango::VALUE => 'X' , Arango::COUNT => true ] ) ;

        $this->assertSame( 2 , $count ) ; // pg1 + pg2 touched, pg3 untouched
        $this->assertSame( [ 'Y' ] , $this->doc( 'pg1' )[ 'tracks' ] ) ;
        $this->assertSame( [] , $this->doc( 'pg2' )[ 'tracks' ] ) ;
        $this->assertSame( [ 'Z' ] , $this->doc( 'pg3' )[ 'tracks' ] ) ;
    }

    // ---------------------------------------------------------------- position key

    /**
     * Three lines carrying **stale** positions on purpose: every assertion below proves
     * the engine rewrote them, rather than that the seed happened to be right.
     *
     * @return array
     */
    private static function lines() :array
    {
        return
        [
            [ 'id' => 'l1' , 'label' => 'Alpha' , 'position' => 7 ] ,
            [ 'id' => 'l2' , 'label' => 'Beta'  , 'position' => 7 ] ,
            [ 'id' => 'l3' , 'label' => 'Gamma' , 'position' => 7 ] ,
        ] ;
    }

    /**
     * Reads the `id:position` pairs of a ranked array, in array order.
     *
     * @param array $lines
     * @return array
     */
    private static function ranks( array $lines ) :array
    {
        return array_map( static fn( array $line ) => $line[ 'id' ] . ':' . $line[ 'position' ] , $lines ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testInsertRenumbersTheWholeArray() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-add' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayInsert( [ Arango::OWNER => 'rk-add' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'id' => 'l4' , 'label' => 'Delta' ] ] ) ;

        $this->assertSame( [ 'l1:0' , 'l2:1' , 'l3:2' , 'l4:3' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 4 , $new->numberOfLines ) ;
    }

    /**
     * The empty-array guard, live: AQL reads `0 .. LENGTH([]) - 1` as a **descending**
     * range and expands it to `[0, -1]`, so an unguarded renumbering would leave two
     * phantom elements behind instead of an empty array.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testRemovingTheLastLineLeavesATrulyEmptyArray() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-empty' , 'lines' => [ [ 'id' => 'l1' , 'position' => 3 ] ] , 'numberOfLines' => 1 ] ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'rk-empty' , Arango::FIELD => 'lines' , Arango::VALUE => 'l1' ] ) ;

        $this->assertSame( [] , $new->lines ) ;
        $this->assertSame( 0 , $new->numberOfLines ) ;
        $this->assertSame( [] , $this->doc( 'rk-empty' )[ 'lines' ] ) ; // and it is what got persisted
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testRemoveClosesTheGapInTheNumbering() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-rm' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayRemove( [ Arango::OWNER => 'rk-rm' , Arango::FIELD => 'lines' , Arango::VALUE => 'l1' ] ) ;

        $this->assertSame( [ 'l2:0' , 'l3:1' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 2 , $new->numberOfLines ) ;
    }

    /**
     * The drag and drop itself, end to end.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testMoveRenumbersTheNewOrder() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-mv' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayMove( [ Arango::OWNER => 'rk-mv' , Arango::FIELD => 'lines' , Arango::VALUE => 'l3' , Arango::POSITION => 0 ] ) ;

        $this->assertSame( [ 'l3:0' , 'l1:1' , 'l2:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 'Gamma' , $new->lines[ 0 ][ 'label' ] ) ; // the object travelled whole
    }

    /**
     * A patch cannot set the position itself: the merge happens first, the renumbering
     * overwrites it.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testAPatchCannotChooseItsOwnPosition() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-up' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayUpdate
        ([
            Arango::OWNER => 'rk-up' ,
            Arango::FIELD => 'lines' ,
            Arango::VALUE => 'l2' ,
            Arango::PATCH => [ 'label' => 'Beta+' , 'position' => 99 ] ,
        ]) ;

        $this->assertSame( [ 'l1:0' , 'l2:1' , 'l3:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 'Beta+' , $new->lines[ 1 ][ 'label' ] ) ; // the rest of the patch did apply
    }

    // ---------------------------------------------------------------- reorder

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testReorderAppliesTheRequestedOrder() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-ro' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayReorder( [ Arango::OWNER => 'rk-ro' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'l3' , 'l1' , 'l2' ] ] ) ;

        $this->assertSame( [ 'l3:0' , 'l1:1' , 'l2:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 3 , $new->numberOfLines ) ;
    }

    /**
     * A partial list reorders what it names and keeps the rest — an incomplete list must
     * never delete.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testReorderKeepsTheLinesItDoesNotMention() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-ro-part' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayReorder( [ Arango::OWNER => 'rk-ro-part' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'l3' ] ] ) ;

        $this->assertSame( [ 'l3:0' , 'l1:1' , 'l2:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 3 , $new->numberOfLines ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testReorderSkipsUnknownKeys() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-ro-zz' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayReorder( [ Arango::OWNER => 'rk-ro-zz' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'zz' , 'l2' ] ] ) ;

        $this->assertSame( [ 'l2:0' , 'l1:1' , 'l3:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 3 , $new->numberOfLines ) ; // nothing was lost to the unknown key
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testReorderWithAnEmptyListKeepsEverything() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-ro-none' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayReorder( [ Arango::OWNER => 'rk-ro-none' , Arango::FIELD => 'lines' , Arango::VALUE => [] ] ) ;

        $this->assertSame( [ 'l1:0' , 'l2:1' , 'l3:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 3 , $new->numberOfLines ) ;
    }

    /**
     * A duplicated key would resolve the same element twice while `NOT IN` removes it
     * from the leftovers only once — the array would grow a clone.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     */
    public function testReorderWithADuplicatedKeyDoesNotCloneTheLine() :void
    {
        $model = $this->seedDoc( [ '_key' => 'rk-ro-dup' , 'lines' => self::lines() , 'numberOfLines' => 3 ] ) ;

        $new = $model->arrayReorder( [ Arango::OWNER => 'rk-ro-dup' , Arango::FIELD => 'lines' , Arango::VALUE => [ 'l2' , 'l1' , 'l2' ] ] ) ;

        $this->assertSame( [ 'l2:0' , 'l1:1' , 'l3:2' ] , self::ranks( $new->lines ) ) ;
        $this->assertSame( 3 , $new->numberOfLines ) ;
    }

    /**
     * The collection-wide purge compiles its own query: it renumbers too, so dropping a
     * reference does not leave a hole behind.
     *
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testPurgeRefRenumbersEveryTouchedDocument() :void
    {
        $shared = [ 'id' => 'shared' , 'label' => 'Shared' , 'position' => 7 ] ;

        self::$db->collection( self::COLLECTION )->insert
        ([
            '_key'  => 'rk-pg1' ,
            'lines' => [ [ 'id' => 'l1' , 'label' => 'Alpha' , 'position' => 7 ] , $shared , [ 'id' => 'l3' , 'label' => 'Gamma' , 'position' => 7 ] ] ,
            'numberOfLines' => 3 ,
        ]) ;

        $this->model()->arrayPurgeRef( [ Arango::FIELD => 'lines' , Arango::VALUE => $shared ] ) ;

        $doc = $this->doc( 'rk-pg1' ) ;

        $this->assertSame( [ 'l1:0' , 'l3:1' ] , self::ranks( $doc[ 'lines' ] ) ) ;
        $this->assertSame( 2 , $doc[ 'numberOfLines' ] ) ;
    }
}
