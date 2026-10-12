<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that a filter the model cannot read is refused, through the real reads.
 *
 * Ten people, aged 20 to 29 : a filter that is read answers a part of them, a filter that
 * is dropped answers all ten — the seed tells the two apart. Before the fix, every refused
 * shape below answered the whole seed (or, under a `not`, AQL the server refused).
 */
#[Group( 'integration' )]
final class UnreadableFilterIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_unreadable_filter_it' ;

    private const string COLLECTION = 'people' ;

    private const array AGE  = [ 'key' => 'age'  , 'op' => 'ge' , 'val' => 25 ] ;
    private const array NAME = [ 'key' => 'name' , 'val' => 'p7' ] ;

    protected static function seed( Database $db ) :void
    {
        $people = $db->collection( self::COLLECTION ) ;
        $people->create() ;
        for ( $i = 0 ; $i < 10 ; $i++ )
        {
            $people->insert( [ '_key' => "p$i" , 'name' => "p$i" , 'age' => 20 + $i ] ) ;
        }
    }

    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
            AQL::FILTERS     => [ 'age' => FilterType::NUMBER , 'name' => FilterType::STRING , 'virtual' => FilterType::VIRTUAL ] ,
        ]);
    }

    private function countFiltered( mixed $filter ) :int
    {
        return $this->model()->count( [ Arango::FILTER => $filter ] ) ;
    }

    /**
     * The counter-proofs : the shapes that are read, and the read without a filter.
     */
    public function testTheSeedTellsAReadFilterFromADroppedOne() :void
    {
        $this->assertSame( 10 , $this->countFiltered( null ) ) ;
        $this->assertSame( 5  , $this->countFiltered( self::AGE ) ) ;
        $this->assertSame( 1  , $this->countFiltered( [ 'and' , self::AGE , self::NAME ] ) ) ;
        $this->assertCount( 5 , $this->model()->list( [ Arango::FILTER => self::AGE ] ) ) ;
    }

    public function testAMongoStyleFilterIsRefusedInsteadOfAnsweringEveryone() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter {"age"} speaks no filter grammar' ) ;

        $this->model()->list( [ Arango::FILTER => [ 'age' => [ '$gte' => 25 ] ] ] ) ;
    }

    public function testAFilterLeftAsAJsonStringIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'string given' ) ;

        $this->countFiltered( json_encode( self::AGE ) ) ;
    }

    /**
     * 🚨 The worse case : the mistyped child vanished and the `and` answered 5 people
     * instead of 1 — a plausible page for another question.
     */
    public function testAMistypedChildNoLongerLoosensItsGroup() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter {"nmae"} speaks no filter grammar' ) ;

        $this->countFiltered( [ 'and' , self::AGE , [ 'nmae' => 'p7' ] ] ) ;
    }

    public function testTheLogicConditionsFormIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter {"logic","conditions"} speaks no filter grammar' ) ;

        $this->countFiltered( [ 'logic' => 'or' , 'conditions' => [ self::AGE , self::NAME ] ] ) ;
    }

    /**
     * A `not` of two conditions used to reach the server as `(a ! b)` — a syntax error it
     * answered in `400`, with a piece of AQL in its message. It is refused before.
     */
    public function testANotOfTwoConditionsIsRefusedBeforeTheServer() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter group "not" takes a single condition' ) ;

        $this->countFiltered( [ 'not' , self::AGE , self::NAME ] ) ;
    }

    /**
     * A `not` of a filter that constrains nothing used to reach the server as `!()`.
     */
    public function testANotOfAClauselessFilterConstrainsNothing() :void
    {
        $this->assertSame( 10 , $this->countFiltered( [ 'not' , [ 'key' => 'virtual' , 'val' => 1 ] ] ) ) ;
        $this->assertSame( 5  , $this->countFiltered( [ 'and' , self::AGE , [ 'not' , [ 'key' => 'virtual' , 'val' => 1 ] ] ] ) ) ;
    }

    public function testAnEmptyGroupIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter group "or" holds no condition.' ) ;

        $this->countFiltered( [ 'or' ] ) ;
    }
}
