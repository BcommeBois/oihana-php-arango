<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use oihana\enums\Boolean;

/**
 * The AQL a filter on a list held inside another list compiles to : the inner elements
 * flattened across the outer list, then counted. The live proof of what each spelling
 * answers is `NestedListIntegrationTest`.
 */
final class FilterNestedListTest extends TestCase
{
    /**
     * Each spelling on the inner list, beside the condition it compiles to.
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function spellings() :array
    {
        return
        [
            'a sub-field'          => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S1' ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\* FILTER CURRENT\.code == @\w+\],1\)\) > 0$/' ] ,
            'a match'              => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\* FILTER CURRENT\.code == @\w+\],1\)\) > 0$/' ] ,
            'a match under any'    => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] , 'quant' => 'any' ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\* FILTER CURRENT\.code == @\w+\],1\)\) > 0$/' ] ,
            'at least one'         => [ [ 'key' => 'stops[*].scans[*]' ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\*\],1\)\) > 0$/' ] ,
            'not one'              => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 'none' ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\*\],1\)\) == 0$/' ] ,
            'at least three'       => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 3 ] , '/^LENGTH\(FLATTEN\(doc\.stops\[\*\]\.scans\[\*\],1\)\) >= 3$/' ] ,
            'three levels deep'    => [ [ 'key' => 'routes[*].stops[*].scans[*].code' , 'val' => 'S1' ] , '/^LENGTH\(FLATTEN\(doc\.routes\[\*\]\.stops\[\*\]\.scans\[\* FILTER CURRENT\.code == @\w+\],2\)\) > 0$/' ] ,
            'a match, three deep'  => [ [ 'key' => 'routes[*].stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] ] , '/^LENGTH\(FLATTEN\(doc\.routes\[\*\]\.stops\[\*\]\.scans\[\* FILTER CURRENT\.code == @\w+\],2\)\) > 0$/' ] ,
        ] ;
    }

    /**
     * What the inner list refuses, as a list of the first level does.
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function refusals() :array
    {
        return
        [
            'a match on an undeclared sub-field' => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'weight' => 3 ] ] , 'is not filterable' ] ,
            'a match on a non-scalar value'      => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => [ 'op' => 'ne' ] ] ] , 'Invalid `match` value' ] ,
            'all, with nothing to satisfy'       => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 'all' ] , "The 'all' quantifier requires a condition" ] ,
        ] ;
    }

    #[DataProvider( 'spellings' )]
    public function testTheInnerElementsAreFlattenedThenCounted( array $filter , string $pattern ) :void
    {
        $binds = [] ;

        $this->assertMatchesRegularExpression( $pattern , $this->model()->prepareFilter( $filter , $binds ) ) ;
    }

    #[DataProvider( 'refusals' )]
    public function testTheInnerListRefusesWhatAListRefuses( array $filter , string $message ) :void
    {
        $binds = [] ;

        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( $message ) ;

        $this->model()->prepareFilter( $filter , $binds ) ;
    }

    /**
     * A match binds its value once : the condition is built once, on the inner list.
     */
    public function testAMatchOnTheInnerListBindsItsValueOnce() :void
    {
        $binds = [] ;

        $this->model()->prepareFilter( [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] ] , $binds ) ;

        $this->assertSame( [ 'S1' ] , array_values( $binds ) ) ;
    }

    /**
     * The sub-fields of the inner list are held to the permissions of the projection, in
     * both spellings : refused, the predicate is neutralised to `false` and nothing is
     * bound ; granted, it compiles.
     */
    public function testTheInnerSubFieldsAreHeldToTheProjection() :void
    {
        $fields = [ 'stops' => [ Field::FIELDS => [ 'scans' => [ Field::FIELDS => [ 'code' => [ Field::REQUIRES => 'scan:read' ] ] ] ] ] ] ;
        $match  = [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] ] ;
        $direct = [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S1' ] ;

        foreach ( [ $match , $direct ] as $filter )
        {
            $binds = [] ;
            $this->assertSame( Boolean::FALSE , $this->model( $fields )->prepareFilter( [ Arango::FILTER => $filter , Arango::AUTHORIZER => fn() => false ] , $binds ) ) ;
            $this->assertSame( [] , $binds ) ;

            $binds = [] ;
            $this->assertStringStartsWith( 'LENGTH(FLATTEN(' , (string) $this->model( $fields )->prepareFilter( [ Arango::FILTER => $filter , Arango::AUTHORIZER => fn( string $s ) => $s === 'scan:read' ] , $binds ) ) ;
        }
    }

    /**
     * @param array<array-key,mixed>|null $fields The projection, when a test needs one.
     */
    private function model( ?array $fields = null ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $scans = [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => [ 'code' => FilterType::STRING ] ] ;

        return new Documents( $container ,
        [
            AQL::COLLECTION => 'parcels' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'stops'  => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => [ 'scans' => $scans ] ] ,
                'routes' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => [ 'stops' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => [ 'scans' => $scans ] ] ] ] ,
            ] ,
            ...( $fields === null ? [] : [ AQL::FIELDS => $fields ] ) ,
        ]) ;
    }
}
