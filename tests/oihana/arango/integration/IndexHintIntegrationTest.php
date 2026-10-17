<?php

namespace tests\oihana\arango\integration;

use Throwable;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\PersistentIndex;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\results\ExplainResult;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that the index a model hands the optimizer is the one its list and its
 * count are read through — and that it changes the path, never the answer.
 *
 * Two indexes can serve « the tasks of one owner, by due date » : `[ owner , due ]`
 * and `[ due ]`. Each hint is asked for in turn and read back at the `EXPLAIN` ;
 * the documents, the order and the count are the same through both. A forced hint
 * naming an index that does not exist fails the query rather than answer through
 * another.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class IndexHintIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_index_hint_it' ;

    private const string COLLECTION = 'tasks' ;

    /**
     * The owner every probe narrows to : three tasks among a hundred and twenty.
     */
    private const array OWNER = [ 'key' => 'owner' , 'val' => 'rare' ] ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tasks = $db->collection( self::COLLECTION ) ;
        $tasks->create() ;

        for ( $i = 0 ; $i < 120 ; $i++ )
        {
            $tasks->insert( [ '_key' => "t$i" , 'owner' => $i % 40 === 0 ? 'rare' : 'common' , 'due' => sprintf( '2026-%02d-%02d' , 1 + $i % 12 , 1 + $i % 28 ) ] ) ;
        }

        $tasks->createIndex( new PersistentIndex( fields : [ 'owner' , 'due' ] , name : 'byOwnerDue' ) ) ;
        $tasks->createIndex( new PersistentIndex( fields : [ 'due' ] , name : 'byDue' ) ) ;
    }

    /**
     * @param array<string,mixed> $init
     */
    private function model( array $init = [] ) :Documents
    {
        $config = initConfig( basePath: dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ) ;
        $arango = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
            AQL::FILTERS     => [ 'owner' => FilterType::STRING ] ,
            AQL::SORTABLE    => [ 'due' , '_key' ] ,
            ...$init ,
        ]) ;
    }

    /**
     * The query of a page : one owner, by due date.
     *
     * @return array<string,mixed>
     */
    private function page() :array
    {
        return [ Arango::FILTER => self::OWNER , Arango::SORT => 'due,_key' , Arango::LIMIT => 10 ] ;
    }

    /**
     * The names of the indexes a plan reads through.
     *
     * @return array<int,string>
     */
    private function indexesOf( ExplainResult $plan ) :array
    {
        return array_map( fn( $index ) => $index->name , $plan->indexesUsed() ) ;
    }

    /**
     * The keys of a list, in their order.
     *
     * @param iterable<mixed> $rows
     *
     * @return array<int,string>
     */
    private function keys( iterable $rows ) :array
    {
        $keys = [] ;

        foreach ( $rows as $row )
        {
            $keys[] = (string) ( is_object( $row ) ? $row->_key : $row[ '_key' ] ) ;
        }

        return $keys ;
    }

    /**
     * 🔑 Each hint is the index the list is read through.
     */
    public function testTheListIsReadThroughTheIndexItIsHanded() :void
    {
        $this->assertSame( [ 'byOwnerDue' ] , $this->indexesOf( $this->model( [ AQL::INDEX_HINT => 'byOwnerDue' , AQL::FORCE_INDEX_HINT => true ] )->explainList( $this->page() ) ) ) ;
        $this->assertSame( [ 'byDue'      ] , $this->indexesOf( $this->model( [ AQL::INDEX_HINT => 'byDue'      , AQL::FORCE_INDEX_HINT => true ] )->explainList( $this->page() ) ) ) ;
    }

    /**
     * A list of names is read in its order of preference : the first one that can
     * serve the loop is taken.
     */
    public function testTheFirstUsableNameOfAListIsTaken() :void
    {
        $plan = $this->model( [ AQL::INDEX_HINT => [ 'byDue' , 'byOwnerDue' ] ] )->explainList( $this->page() ) ;

        $this->assertSame( [ 'byDue' ] , $this->indexesOf( $plan ) ) ;
    }

    /**
     * 🔑 A hint changes the path, never the answer : the same documents, in the same
     * order, and the same count, through either index and through none.
     */
    public function testAHintChangesThePathNeverTheAnswer() :void
    {
        $expected = $this->keys( $this->model()->list( $this->page() ) ) ;

        $this->assertCount( 3 , $expected ) ;

        foreach ( [ 'byOwnerDue' , 'byDue' ] as $hint )
        {
            $model = $this->model( [ AQL::INDEX_HINT => $hint , AQL::FORCE_INDEX_HINT => true ] ) ;

            $this->assertSame( $expected , $this->keys( $model->list( $this->page() ) ) , $hint ) ;
            $this->assertSame( 3 , $model->count( [ Arango::FILTER => self::OWNER ] ) , $hint ) ;
        }
    }

    /**
     * ⚠️ A forced hint naming no index fails the query — it never answers through
     * another index as if nothing were wrong.
     */
    public function testAForcedHintOnAMissingIndexFailsTheQuery() :void
    {
        $failed = null ;

        try
        {
            $this->model( [ AQL::INDEX_HINT => 'noSuchIndex' , AQL::FORCE_INDEX_HINT => true ] )->list( $this->page() ) ;
        }
        catch ( Throwable $exception )
        {
            $failed = $exception->getMessage() ;
        }

        $this->assertNotNull( $failed , 'the query answered through another index' ) ;
        $this->assertStringContainsString( 'noSuchIndex' , (string) $failed ) ;
    }

    /**
     * Without `forceIndexHint`, an index that does not exist is a preference the
     * optimizer sets aside : the list answers as it would without it.
     */
    public function testAnUnforcedHintOnAMissingIndexIsSetAside() :void
    {
        $this->assertSame
        (
            $this->keys( $this->model()->list( $this->page() ) ) ,
            $this->keys( $this->model( [ AQL::INDEX_HINT => 'noSuchIndex' ] )->list( $this->page() ) )
        ) ;
    }
}
