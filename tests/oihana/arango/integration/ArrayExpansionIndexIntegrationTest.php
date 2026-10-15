<?php

namespace tests\oihana\arango\integration;

use ReflectionMethod;

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
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterLookup;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that an equality on an element of a list (`orders[*].id == "B2"`) is
 * answered through an index on that element.
 *
 * ArangoDB looks an element of a list up in an index (`[ "kind" , "orders[*].id" ]`)
 * for one spelling only : `@v IN doc.orders[*].id`. The existential spelling,
 * `LENGTH( doc.orders[* FILTER CURRENT.id == @v] ) > 0`, says the same thing and
 * leaves the index to its leading columns : every document of the kind is read, and
 * the list is tested in each. A limit does not bound that read — the total of the
 * page counts every match.
 *
 * The seed holds every shape the two spellings must agree on : a list carrying the
 * value twice, an empty list, no list, a `null`, a string where a list is expected,
 * an element without the sub-field, and the value stored as an integer.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ArrayExpansionIndexIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_array_expansion_index_it' ;

    private const string COLLECTION = 'parcels' ;

    /**
     * The kind every probe is narrowed to — the leading column of the index.
     */
    private const array KIND = [ 'key' => 'kind' , 'val' => 'parcel' ] ;

    /**
     * The existential spelling, written by hand : the one the index cannot serve.
     */
    private const string EXISTENTIAL = 'doc.kind == @kind && LENGTH(doc.orders[* FILTER CURRENT.id == @order]) > 0' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $parcels = $db->collection( self::COLLECTION ) ;
        $parcels->create() ;

        $parcels->insert( [ '_key' => 'p1'  , 'kind' => 'parcel' , 'orders' => [ [ 'id' => 'A1' ] , [ 'id' => 'B2' ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p2'  , 'kind' => 'parcel' , 'orders' => [ [ 'id' => 'B2' ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p3'  , 'kind' => 'parcel' , 'orders' => [] ] ) ;
        $parcels->insert( [ '_key' => 'p4'  , 'kind' => 'parcel' ] ) ;
        $parcels->insert( [ '_key' => 'p5'  , 'kind' => 'parcel' , 'orders' => [ [ 'ref' => 'B2' ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p6'  , 'kind' => 'parcel' , 'orders' => [ [ 'id' => 22 ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p7'  , 'kind' => 'crate'  , 'orders' => [ [ 'id' => 'B2' ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p8'  , 'kind' => 'parcel' , 'orders' => null ] ) ;
        $parcels->insert( [ '_key' => 'p9'  , 'kind' => 'parcel' , 'orders' => [ [ 'id' => 'B2' ] , [ 'id' => 'B2' ] ] ] ) ;
        $parcels->insert( [ '_key' => 'p10' , 'kind' => 'parcel' , 'orders' => 'B2' ] ) ;

        // Enough documents of another kind for a scan of the kind and a lookup of the
        // element to tell apart in a plan.
        for ( $i = 0 ; $i < 40 ; $i++ )
        {
            $parcels->insert( [ '_key' => "f$i" , 'kind' => 'filler' , 'orders' => [ [ 'id' => "F$i" ] ] ] ) ;
        }

        $parcels->createIndex( new PersistentIndex( fields : [ 'kind' , 'orders[*].id' ] ) ) ;
    }

    /**
     * The values the two spellings are compared on.
     *
     * @return array<string,array{0:string}>
     */
    public static function orders() :array
    {
        return
        [
            'carried by three parcels' => [ 'B2' ] ,
            'carried by one parcel'    => [ 'A1' ] ,
            'stored as an integer'     => [ '22' ] ,
            'carried by none'          => [ 'Z9' ] ,
            'an empty string'          => [ ''   ] ,
        ] ;
    }

    // ========================================
    // THE INDEX
    // ========================================

    /**
     * 🚨 The point of the batch : the element of the list is part of the index lookup,
     * beside the kind — not tested afterwards on every parcel of the kind.
     *
     * @throws ArangoException
     */
    public function testTheListQueryLooksTheElementUpInTheIndex() :void
    {
        $plan = $this->model()->explainList( [ Arango::FILTER => $this->byOrder( 'B2' ) , Arango::LIMIT => 20 ] ) ;

        $this->assertSame( [ 'kind' , 'orders[*].id' ] , $this->indexFields( $plan ) ) ;
        $this->assertTrue( $this->lookupCoversTheList( $plan ) , 'the element of the list should be looked up in the index' ) ;
    }

    /**
     * The count of a list runs its own query, and it is filtered the same way.
     *
     * @throws ArangoException
     */
    public function testTheCountQueryLooksTheElementUpInTheIndex() :void
    {
        $model = $this->model() ;
        $binds = [] ;
        $build = new ReflectionMethod( $model , 'buildCountQuery' ) ;
        $query = $build->invokeArgs( $model , [ [ Arango::FILTER => $this->byOrder( 'B2' ) ] , &$binds ] ) ;

        $plan = $model->explain( $query , $binds ) ;

        $this->assertSame( [ 'kind' , 'orders[*].id' ] , $this->indexFields( $plan ) ) ;
        $this->assertTrue( $this->lookupCoversTheList( $plan ) , 'the element of the list should be looked up in the index' ) ;
    }

    /**
     * The reason the batch exists, kept in sight : the existential spelling reaches the
     * same index through its leading column only. The day ArangoDB learns to look it up,
     * this turns red and says the other spelling is no longer needed.
     *
     * @throws ArangoException
     */
    public function testTheExistentialSpellingLeavesTheListOutOfTheLookup() :void
    {
        $plan = $this->model()->explain
        (
            'FOR doc IN ' . self::COLLECTION . ' FILTER ' . self::EXISTENTIAL . ' LIMIT 20 RETURN doc' ,
            [ 'kind' => 'parcel' , 'order' => 'B2' ] ,
        ) ;

        $this->assertSame( [ 'kind' , 'orders[*].id' ] , $this->indexFields( $plan ) ) ;
        $this->assertFalse( $this->lookupCoversTheList( $plan ) ) ;
    }

    /**
     * The three other spellings of the same question — a list of values, `quant: any`,
     * a `match` on one sub-field — reach the index as well.
     *
     * @return array<string,array{0:array<int|string,mixed>}>
     */
    public static function sameQuestions() :array
    {
        return
        [
            'a list of values'        => [ [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => [ 'B2' , 'A1' ] ] ] ,
            'a quantifier any'        => [ [ 'key' => 'orders[*].id' , 'val' => 'B2' , 'quant' => 'any' ] ] ,
            'a match on one sub-field' => [ [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'B2' ] ] ] ,
        ] ;
    }

    /**
     * @throws ArangoException
     */
    #[DataProvider( 'sameQuestions' )]
    public function testTheSameQuestionLooksTheElementUpInTheIndex( array $filter ) :void
    {
        $plan = $this->model()->explainList( [ Arango::FILTER => [ 'and' , self::KIND , $filter ] , Arango::LIMIT => 20 ] ) ;

        $this->assertSame( [ 'kind' , 'orders[*].id' ] , $this->indexFields( $plan ) ) ;
        $this->assertTrue( $this->lookupCoversTheList( $plan ) , 'the element of the list should be looked up in the index' ) ;
    }

    /**
     * 🚨 A list of values is looked up value by value up to its bound, and keeps the
     * existential spelling beyond it : the bound is where the lookup stops, measured.
     *
     * @throws ArangoException
     */
    public function testAListLongerThanItsBoundKeepsTheExistentialSpelling() :void
    {
        $atTheBound = $this->longList( FilterLookup::MAX_VALUES ) ;
        $beyond     = $this->longList( FilterLookup::MAX_VALUES + 1 ) ;

        $this->assertTrue ( $this->lookupCoversTheList( $this->model()->explainList( [ Arango::FILTER => $this->byOrders( $atTheBound ) ] ) ) ) ;
        $this->assertFalse( $this->lookupCoversTheList( $this->model()->explainList( [ Arango::FILTER => $this->byOrders( $beyond     ) ] ) ) ) ;

        $this->assertSame( [ 'p1' , 'p2' , 'p9' ] , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrders( $atTheBound ) ] ) ) ) ;
        $this->assertSame( [ 'p1' , 'p2' , 'p9' ] , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrders( $beyond     ) ] ) ) ) ;
    }

    // ========================================
    // THE ANSWER
    // ========================================

    /**
     * Whatever the index does, the parcels that come back are those the existential
     * spelling names — on every shape of the seed.
     *
     * @throws ArangoException
     */
    #[DataProvider( 'orders' )]
    public function testTheListAnswersWhatTheExistentialSpellingAnswers( string $order ) :void
    {
        $existential = $this->keys( self::$db->query
        (
            'FOR doc IN ' . self::COLLECTION . ' FILTER ' . self::EXISTENTIAL . ' RETURN doc._key' ,
            [ 'kind' => 'parcel' , 'order' => $order ] ,
        )) ;

        $this->assertSame( $existential , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrder( $order ) ] ) ) ) ;
    }

    /**
     * `quant: any` and a `match` on one sub-field answer what the existential spelling
     * answers, on the same values.
     *
     * @throws ArangoException
     */
    #[DataProvider( 'orders' )]
    public function testTheOtherSpellingsOfTheQuestionAnswerTheSame( string $order ) :void
    {
        $existential = $this->keys( self::$db->query
        (
            'FOR doc IN ' . self::COLLECTION . ' FILTER ' . self::EXISTENTIAL . ' RETURN doc._key' ,
            [ 'kind' => 'parcel' , 'order' => $order ] ,
        )) ;

        $any   = [ 'and' , self::KIND , [ 'key' => 'orders[*].id' , 'val' => $order , 'quant' => 'any' ] ] ;
        $match = [ 'and' , self::KIND , [ 'key' => 'orders[*]' , 'match' => [ 'id' => $order ] ] ] ;

        $this->assertSame( $existential , $this->keys( $this->model()->list( [ Arango::FILTER => $any   ] ) ) ) ;
        $this->assertSame( $existential , $this->keys( $this->model()->list( [ Arango::FILTER => $match ] ) ) ) ;
    }

    /**
     * The lists of values the two spellings are compared on.
     *
     * @return array<string,array{0:array<int,string>}>
     */
    public static function orderLists() :array
    {
        return
        [
            'two orders'                 => [ [ 'B2' , 'A1' ] ] ,
            'one order'                  => [ [ 'A1' ] ] ,
            'the same order twice'       => [ [ 'B2' , 'B2' ] ] ,
            'an integer and none'        => [ [ '22' , 'Z9' ] ] ,
            'an empty list'              => [ [] ] ,
        ] ;
    }

    /**
     * A list of values answers what `CURRENT.id IN @orders` answers.
     *
     * @param array<int,string> $orders
     *
     * @throws ArangoException
     */
    #[DataProvider( 'orderLists' )]
    public function testAListOfValuesAnswersWhatTheExistentialSpellingAnswers( array $orders ) :void
    {
        $existential = $this->keys( self::$db->query
        (
            'FOR doc IN ' . self::COLLECTION . ' FILTER doc.kind == @kind && LENGTH(doc.orders[* FILTER CURRENT.id IN @orders]) > 0 RETURN doc._key' ,
            [ 'kind' => 'parcel' , 'orders' => $orders ] ,
        )) ;

        $this->assertSame( $existential , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrders( $orders ) ] ) ) ) ;
    }

    /**
     * Named once, so the comparison above is not a comparison of two empty lists : a
     * list carrying the value twice comes back once ; the empty list, no list, a `null`,
     * a string, an element without the sub-field, an integer and another kind do not
     * come back at all.
     *
     * @throws ArangoException
     */
    public function testTheParcelsCarryingTheOrderComeBackOnce() :void
    {
        $this->assertSame( [ 'p1' , 'p2' , 'p9' ] , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrder( 'B2' ) ] ) ) ) ;
        $this->assertSame( [] , $this->keys( $this->model()->list( [ Arango::FILTER => $this->byOrder( '22' ) ] ) ) ) ;
    }

    /**
     * A page smaller than the matches still counts them all.
     *
     * @throws ArangoException
     */
    public function testAShortPageStillCountsEveryMatch() :void
    {
        $model = $this->model() ;
        $rows  = $model->list( [ Arango::FILTER => $this->byOrder( 'B2' ) , Arango::LIMIT => 2 ] ) ;

        $this->assertCount( 2 , $rows ) ;
        $this->assertSame( 3 , $model->foundRows() ) ;
        $this->assertSame( 3 , $model->count( [ Arango::FILTER => $this->byOrder( 'B2' ) ] ) ) ;
    }

    /**
     * The spellings the batch leaves alone still answer : an `alt` transforms the
     * element, an inequality asks for an element that differs.
     *
     * @throws ArangoException
     */
    public function testTheOtherSpellingsStillAnswer() :void
    {
        $lower = [ 'key' => 'orders[*].id' , 'val' => 'b2' , 'alt' => [ 'key' => 'lower' ] ] ;
        $other = [ 'key' => 'orders[*].id' , 'op'  => 'ne' , 'val' => 'B2' ] ;

        $this->assertSame( [ 'p1' , 'p2' , 'p9' ] , $this->keys( $this->model()->list( [ Arango::FILTER => [ 'and' , self::KIND , $lower ] ] ) ) ) ;

        // p5 has an element without `id`, which differs from B2 ; p6 an integer.
        $this->assertSame( [ 'p1' , 'p5' , 'p6' ] , $this->keys( $this->model()->list( [ Arango::FILTER => [ 'and' , self::KIND , $other ] ] ) ) ) ;
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * The filter of a list narrowed to the parcels carrying one order.
     *
     * @return array<int,mixed>
     */
    private function byOrder( string $order ) :array
    {
        return [ 'and' , self::KIND , [ 'key' => 'orders[*].id' , 'val' => $order ] ] ;
    }

    /**
     * The filter of a list narrowed to the parcels carrying one of several orders.
     *
     * @param array<int,string> $orders
     *
     * @return array<int,mixed>
     */
    private function byOrders( array $orders ) :array
    {
        return [ 'and' , self::KIND , [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => $orders ] ] ;
    }

    /**
     * A list of `$count` orders : B2, then orders no parcel carries.
     *
     * @return array<int,string>
     */
    private function longList( int $count ) :array
    {
        return [ 'B2' , ...array_map( static fn( int $i ) :string => "X$i" , range( 1 , $count - 1 ) ) ] ;
    }

    /**
     * The fields of the first index the plan reads, or an empty list when it reads none.
     *
     * @return array<int,string>
     */
    private function indexFields( ExplainResult $plan ) :array
    {
        $indexes = $plan->indexesUsed() ;

        return $indexes === [] ? [] : $indexes[ 0 ]->fields ;
    }

    /**
     * Whether the lookup of the index node holds an `IN` on the list — the element of the
     * list taken from the index, rather than tested once the document is read.
     */
    private function lookupCoversTheList( ExplainResult $plan ) :bool
    {
        foreach ( $plan->plan()[ 'nodes' ] ?? [] as $node )
        {
            if ( ( $node[ 'type' ] ?? null ) === 'IndexNode' && $this->holdsAnInOn( $node[ 'condition' ] ?? [] , 'orders' ) )
            {
                return true ;
            }
        }

        return false ;
    }

    /**
     * Walks a condition of the plan for a `compare in` naming the attribute.
     *
     * @param mixed  $condition The condition node.
     * @param string $attribute The attribute holding the list.
     * @param bool   $inside    Whether the walk is already below a `compare in`.
     */
    private function holdsAnInOn( mixed $condition , string $attribute , bool $inside = false ) :bool
    {
        if ( !is_array( $condition ) )
        {
            return false ;
        }

        $inside = $inside || ( $condition[ 'type' ] ?? null ) === 'compare in' ;

        if ( $inside && ( $condition[ 'type' ] ?? null ) === 'attribute access' && ( $condition[ 'name' ] ?? null ) === $attribute )
        {
            return true ;
        }

        foreach ( $condition[ 'subNodes' ] ?? [] as $child )
        {
            if ( $this->holdsAnInOn( $child , $attribute , $inside ) )
            {
                return true ;
            }
        }

        return false ;
    }

    /**
     * The sorted keys of what a read answered — documents or bare keys.
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
            $keys[] = (string) ( is_object( $row ) ? $row->_key : ( is_array( $row ) ? $row[ '_key' ] : $row ) ) ;
        }

        sort( $keys ) ;

        return $keys ;
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
            AQL::FILTERS     =>
            [
                'kind'   => FilterType::STRING ,
                'orders' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'id' => FilterType::STRING ] ,
                ] ,
            ] ,
        ]);
    }
}
