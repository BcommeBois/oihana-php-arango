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
use oihana\arango\models\enums\filters\FilterType;

/**
 * Which filters on an element of a list are written as the lookup an index serves,
 * `@v IN doc.orders[*].id`, and which keep the existential spelling,
 * `LENGTH( doc.orders[* FILTER CURRENT.id == @v] ) > 0`.
 *
 * Only a plain equality on one scalar, on a list of the first level, with neither
 * `alt` nor `quant`, asks the question an `IN` answers. The live proof that the index
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
            'a list of ids'  => [ [ 'key' => 'orders[*].id'     , 'op' => 'in'   , 'val' => [ 'B2' , 'A1' ] ] ] ,
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
     * A quantifier counts the elements : it keeps its question-mark operator.
     */
    public function testAQuantifiedEqualityKeepsItsOperator() :void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'orders[*].id' , 'val' => 'B2' , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertStringStartsWith( 'doc.orders[? ALL FILTER CURRENT.id == @' , $result ) ;
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
