<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\enums\Order;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\sortRelationVariable;

/**
 * Coverage for {@see sortRelationVariable()} — the shared `SORT` builder behind
 * {@see oihana\arango\models\helpers\edges\sortEdgeVariable()} and
 * {@see oihana\arango\models\helpers\joins\sortJoinVariable()}. The explicit sort
 * property targets `$sortRef`; the fallback targets `$defaultRef` in DESC order.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class SortRelationVariableTest extends TestCase
{
    public function testNullFallsBackToDefaultPropertyOnDefaultRefInDesc() :void
    {
        // Edge-like: fallback targets the edge ref, NOT the sort (vertex) ref.
        $this->assertSame( 'SORT e.created DESC' , sortRelationVariable( null , 'v' , 'e' ) ) ;
    }

    public function testArrayWithoutSortKeyFallsBackToDefault() :void
    {
        // The ORDER is ignored when no sort property is provided — fallback is always DESC.
        $this->assertSame( 'SORT e.created DESC' , sortRelationVariable( [ AQL::ORDER => Order::ASC ] , 'v' , 'e' ) ) ;
    }

    public function testStringSortDefaultsToAscOnSortRef() :void
    {
        $this->assertSame( 'SORT v.name ASC' , sortRelationVariable( 'name' , 'v' , 'e' ) ) ;
    }

    public function testArraySortWithDescOrderOnSortRef() :void
    {
        $this->assertSame
        (
            'SORT v.age DESC' ,
            sortRelationVariable( [ AQL::SORT => 'age' , AQL::ORDER => Order::DESC ] , 'v' , 'e' )
        ) ;
    }

    public function testArraySortWithoutOrderDefaultsToAsc() :void
    {
        $this->assertSame
        (
            'SORT v.lastName ASC' ,
            sortRelationVariable( [ AQL::SORT => 'lastName' ] , 'v' , 'e' )
        ) ;
    }

    public function testJoinLikeUsesTheSameRefForSortAndFallback() :void
    {
        // Join-like: sortRef === defaultRef.
        $this->assertSame( 'SORT doc_join.name ASC'   , sortRelationVariable( 'name' , 'doc_join' , 'doc_join' ) ) ;
        $this->assertSame( 'SORT doc_join._key DESC' , sortRelationVariable( null , 'doc_join' , 'doc_join' , '_key' ) ) ;
    }

    public function testCustomDefaultProperty() :void
    {
        $this->assertSame( 'SORT e.updated DESC' , sortRelationVariable( null , 'v' , 'e' , 'updated' ) ) ;
    }
}
