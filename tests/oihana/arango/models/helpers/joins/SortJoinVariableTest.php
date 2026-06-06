<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\enums\Order;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\joins\sortJoinVariable;

/**
 * Characterization coverage for {@see sortJoinVariable()} — builds the `SORT`
 * clause of an array-join sub-query.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class SortJoinVariableTest extends TestCase
{
    public function testNullFallsBackToKeyDescOnTheJoinRef() :void
    {
        $this->assertSame( 'SORT doc_join._key DESC' , sortJoinVariable( null ) ) ;
    }

    public function testArrayWithoutSortKeyFallsBackToDefault() :void
    {
        $this->assertSame( 'SORT doc_join._key DESC' , sortJoinVariable( [ AQL::ORDER => Order::DESC ] ) ) ;
    }

    public function testStringSortDefaultsToAsc() :void
    {
        $this->assertSame( 'SORT doc_join.name ASC' , sortJoinVariable( 'name' ) ) ;
    }

    public function testArraySortWithDescOrder() :void
    {
        $this->assertSame
        (
            'SORT doc_join.name DESC' ,
            sortJoinVariable( [ AQL::SORT => 'name' , AQL::ORDER => Order::DESC ] )
        ) ;
    }

    public function testHonorsCustomDocRefAndDefaultProperty() :void
    {
        $this->assertSame( 'SORT j.name ASC'    , sortJoinVariable( 'name' , 'j' ) ) ;
        $this->assertSame( 'SORT j.created DESC' , sortJoinVariable( null , 'j' , 'created' ) ) ;
    }
}
