<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\enums\Order;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\edges\sortEdgeVariable;

/**
 * Characterization coverage for {@see sortEdgeVariable()} — builds the `SORT`
 * clause of an edge sub-traversal.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class SortEdgeVariableTest extends TestCase
{
    public function testNullFallsBackToCreatedDescOnTheEdgeRef() :void
    {
        $this->assertSame( 'SORT edge.created DESC' , sortEdgeVariable( null ) ) ;
    }

    public function testArrayWithoutSortKeyFallsBackToDefault() :void
    {
        $this->assertSame( 'SORT edge.created DESC' , sortEdgeVariable( [ AQL::ORDER => Order::DESC ] ) ) ;
    }

    public function testStringSortDefaultsToAscOnTheVertexRef() :void
    {
        $this->assertSame( 'SORT vertex.name ASC' , sortEdgeVariable( 'name' ) ) ;
    }

    public function testArraySortWithDescOrder() :void
    {
        $this->assertSame
        (
            'SORT vertex.name DESC' ,
            sortEdgeVariable( [ AQL::SORT => 'name' , AQL::ORDER => Order::DESC ] )
        ) ;
    }

    public function testArraySortWithoutOrderDefaultsToAsc() :void
    {
        $this->assertSame
        (
            'SORT vertex.name ASC' ,
            sortEdgeVariable( [ AQL::SORT => 'name' ] )
        ) ;
    }

    public function testHonorsCustomVertexAndEdgeRefs() :void
    {
        $this->assertSame
        (
            'SORT v.name ASC' ,
            sortEdgeVariable( 'name' , 'v' , 'e' )
        ) ;

        $this->assertSame
        (
            'SORT e.created DESC' ,
            sortEdgeVariable( null , 'v' , 'e' )
        ) ;
    }

    public function testHonorsCustomDefaultProperty() :void
    {
        $this->assertSame
        (
            'SORT edge.modified DESC' ,
            sortEdgeVariable( null , AQL::VERTEX , AQL::EDGE , 'modified' )
        ) ;
    }
}
