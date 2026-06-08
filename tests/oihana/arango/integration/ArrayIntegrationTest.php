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
     * A Documents model wired to the disposable database, with `tracks`/`tags`/`genres`/`members` declared.
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
                'tracks'  => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
                'tags'    => ArrayMode::SET ,
                'genres'  => ArrayMode::SORTED_SET ,
                'members' => ArrayMode::LIST , // arrays of objects
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
}
