<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterLookup;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Which filters on an element of a list are written as the lookup an index serves,
 * `@v IN doc.orders[*].id`, and which keep the existential spelling,
 * `LENGTH( doc.orders[* FILTER CURRENT.id == @v] ) > 0`.
 *
 * Three spellings ask the question an `IN` answers — an equality on one scalar, a
 * list of scalars (`in`) up to its bound, a `match` on one sub-field — on a list of
 * the first level, without `alt`, with no `quant` or with `any`. The live proof that the index
 * is then used, and that both spellings answer the same documents, is
 * `ArrayExpansionIndexIntegrationTest`.
 */
class FilterArrayElementEqualityTest extends TestCase
{
    private Documents $model ;

    private array $binds ;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp() :void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'parcels' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'orders' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'id' => FilterType::STRING , 'weight' => FilterType::NUMBER , 'urgent' => FilterType::BOOL ] ,
                ] ,
                'stops' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'scans' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'code' => FilterType::STRING ] ,
                        ] ,
                    ] ,
                ] ,
                'notes' => [ AQL::TYPE => Filter::ARRAY_EXPANSION ] ,
            ] ,
        ]) ;

        $this->binds = [] ;
    }

    /**
     * The equalities an index serves, with the value each binds.
     *
     * @return array<string,array{0:array<string,mixed>,1:string,2:mixed}>
     */
    public static function lookups() :array
    {
        return
        [
            'no operator'           => [ [ 'key' => 'orders[*].id'     , 'val' => 'B2'           ] , 'orders[*].id'     , 'B2'  ] ,
            'the operator eq'       => [ [ 'key' => 'orders[*].id'     , 'op'  => 'eq' , 'val' => 'B2' ] , 'orders[*].id' , 'B2' ] ,
            'an integer'            => [ [ 'key' => 'orders[*].weight' , 'val' => 12             ] , 'orders[*].weight' , 12    ] ,
            'a decimal'             => [ [ 'key' => 'orders[*].weight' , 'val' => 1.5            ] , 'orders[*].weight' , 1.5   ] ,
            'a boolean'             => [ [ 'key' => 'orders[*].urgent' , 'val' => true           ] , 'orders[*].urgent' , true  ] ,
            'a list with no filter' => [ [ 'key' => 'notes[*].author'  , 'val' => 'ann'          ] , 'notes[*].author'  , 'ann' ] ,
        ] ;
    }

    /**
     * The questions an `IN` does not ask, each keeping the existential spelling.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function existentials() :array
    {
        return
        [
            'an inequality'  => [ [ 'key' => 'orders[*].id'     , 'op' => 'ne'   , 'val' => 'B2'         ] ] ,
            'a pattern'      => [ [ 'key' => 'orders[*].id'     , 'op' => 'like' , 'val' => 'B%'         ] ] ,
            'a comparison'   => [ [ 'key' => 'orders[*].weight' , 'op' => 'ge'   , 'val' => 12           ] ] ,
            'a list holding a null'   => [ [ 'key' => 'orders[*].id' , 'op' => 'in'  , 'val' => [ 'B2' , null ] ] ] ,
            'a list holding a list'   => [ [ 'key' => 'orders[*].id' , 'op' => 'in'  , 'val' => [ 'B2' , [ 'A1' ] ] ] ] ,
            'a list beyond its bound' => [ [ 'key' => 'orders[*].id' , 'op' => 'in'  , 'val' => self::orders( FilterLookup::MAX_VALUES + 1 ) ] ] ,
            'none of a list'          => [ [ 'key' => 'orders[*].id' , 'op' => 'nin' , 'val' => [ 'B2' , 'A1' ] ] ] ,
            'a list with an alt'      => [ [ 'key' => 'orders[*].id' , 'op' => 'in'  , 'val' => [ 'b2' ] , 'alt' => [ 'key' => 'lower' ] ] ] ,
            'a match on two fields'   => [ [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'B2' , 'urgent' => true ] ] ] ,
            'a match with an alt'     => [ [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'b2' ] , 'alt' => [ 'key' => 'lower' ] ] ] ,
            'a null'         => [ [ 'key' => 'orders[*].id'     , 'val' => null                          ] ] ,
            'a list value'   => [ [ 'key' => 'orders[*].id'     , 'val' => [ 'B2' ]                      ] ] ,
            'an alt'         => [ [ 'key' => 'orders[*].id'     , 'val' => 'b2' , 'alt' => [ 'key' => 'lower' ] ] ] ,
            'a nested list'  => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S1'                    ] ] ,
        ] ;
    }

    #[DataProvider( 'lookups' )]
    public function testAnEqualityIsALookupOfTheElement( array $filter , string $path , mixed $bound ) :void
    {
        $result = $this->model->prepareFilter( $filter , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^@\w+ IN doc\.' . preg_quote( $path , '/' ) . '$/' , $result ) ;
        $this->assertSame( [ $bound ] , array_values( $this->binds ) ) ;
    }

    #[DataProvider( 'existentials' )]
    public function testAnyOtherQuestionKeepsTheExistentialSpelling( array $filter ) :void
    {
        $result = $this->model->prepareFilter( $filter , $this->binds ) ;

        $this->assertStringStartsWith( 'LENGTH(doc.' , $result ) ;
        $this->assertStringEndsWith( ') > 0' , $result ) ;
        $this->assertStringNotContainsString( ' IN doc.' , $result ) ;
    }

    /**
     * A quantifier other than `any` counts the elements : it keeps its question-mark
     * operator, on an equality, a list and a `match` alike.
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function countingQuestions() :array
    {
        return
        [
            'all on an equality' => [ [ 'key' => 'orders[*].id' , 'val' => 'B2' , 'quant' => 'all' ] , 'doc.orders[? ALL FILTER CURRENT.id == @' ] ,
            'none on a list'     => [ [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => [ 'B2' ] , 'quant' => 'none' ] , 'doc.orders[? NONE FILTER CURRENT.id IN @' ] ,
            'at least on a match' => [ [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'B2' ] , 'quant' => 2 ] , 'doc.orders[? AT LEAST (2) FILTER CURRENT.id == @' ] ,
        ] ;
    }

    #[DataProvider( 'countingQuestions' )]
    public function testACountingQuantifierKeepsItsOperator( array $filter , string $start ) :void
    {
        $this->assertStringStartsWith( $start , $this->model->prepareFilter( $filter , $this->binds ) ) ;
    }

    /**
     * `quant: any` asks what no quantifier asks : the same lookup.
     */
    public function testTheQuantifierAnyIsALookup() :void
    {
        $this->assertMatchesRegularExpression( '/^@\w+ IN doc\.orders\[\*\]\.id$/' , $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'val' => 'B2' , 'quant' => 'any' ] , $this->binds ) ) ;
        $this->assertMatchesRegularExpression( '/^@\w+ IN doc\.orders\[\*\]\.id$/' , $this->model->prepareFilter( [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'B2' ] , 'quant' => 'any' ] , $this->binds ) ) ;
    }

    /**
     * A list of values is one lookup per value, joined by `||`, each value bound.
     */
    public function testAListOfValuesIsOneLookupPerValue() :void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => [ 'B2' , 'A1' ] ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(@\w+ IN doc\.orders\[\*\]\.id \|\| @\w+ IN doc\.orders\[\*\]\.id\)$/' , $result ) ;
        $this->assertSame( [ 'B2' , 'A1' ] , array_values( $this->binds ) ) ;
    }

    /**
     * 🚨 The duplicates go strictly : a string and an integer, or `true` and a string,
     * are two values to AQL, and both are looked up.
     */
    public function testTheDuplicatesOfAListGoStrictly() :void
    {
        $this->model->prepareFilter( [ 'key' => 'notes[*].author' , 'op' => 'in' , 'val' => [ '22' , 22 , '22' , true , 'ann' ] ] , $this->binds ) ;

        $this->assertSame( [ '22' , 22 , true , 'ann' ] , array_values( $this->binds ) ) ;
    }

    /**
     * A list at its bound is still looked up ; one more value and it keeps the
     * existential spelling (see the existentials).
     */
    public function testAListAtItsBoundIsStillALookup() :void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => self::orders( FilterLookup::MAX_VALUES ) ] , $this->binds ) ;

        $this->assertSame( FilterLookup::MAX_VALUES , substr_count( $result , ' IN doc.orders[*].id' ) ) ;
    }

    /**
     * An empty list names no document, as the existential spelling did.
     */
    public function testAnEmptyListIsFalse() :void
    {
        $this->assertSame( 'false' , $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'op' => 'in' , 'val' => [] ] , $this->binds ) ) ;
        $this->assertSame( [] , $this->binds ) ;
    }

    /**
     * A `match` on one sub-field is the lookup, and binds its value once : the guards
     * of the `match` run on a copy of the binds, and a variable bound and never used is
     * refused by the server.
     */
    public function testAMatchOnOneSubFieldIsALookupBindingOnce() :void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'orders[*]' , 'match' => [ 'id' => 'B2' ] ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^@\w+ IN doc\.orders\[\*\]\.id$/' , $result ) ;
        $this->assertSame( [ 'B2' ] , array_values( $this->binds ) ) ;
    }

    /**
     * The guards of the `match` still play before the lookup : an undeclared sub-field is
     * refused, as it is refused in the existential spelling.
     */
    public function testAMatchOnAnUndeclaredSubFieldIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'ref' ) ;

        $this->model->prepareFilter( [ 'key' => 'orders[*]' , 'match' => [ 'ref' => 'B2' ] ] , $this->binds ) ;
    }

    /**
     * `count` orders : B2, then orders numbered from 1.
     *
     * @return array<int,string>
     */
    private static function orders( int $count ) :array
    {
        return [ 'B2' , ...array_map( static fn( int $i ) :string => "X$i" , range( 1 , $count - 1 ) ) ] ;
    }

    /**
     * The value is bound, never written into the query.
     */
    public function testTheValueIsBound() :void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'val' => '" || true || "' ] , $this->binds ) ;

        $this->assertStringNotContainsString( 'true' , $result ) ;
        $this->assertSame( [ '" || true || "' ] , array_values( $this->binds ) ) ;
    }

    /**
     * The sub-field is written into the path : a name that is not one is refused, as the
     * inline spelling refuses it.
     */
    public function testASubFieldThatIsNotANameIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'Invalid AQL attribute name: ".author"' ) ;

        $this->model->prepareFilter( [ 'key' => 'notes[*]..author' , 'val' => 'ann' ] , $this->binds ) ;
    }
}
