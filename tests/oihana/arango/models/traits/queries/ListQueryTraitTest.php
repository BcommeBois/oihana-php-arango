<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;
use oihana\arango\models\traits\queries\ListQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see ListQueryTrait}. It composes FieldsTrait → ArangoTrait
 * (which declares `$collection`), so the property is set in the constructor.
 */
class ListQueryTraitStub
{
    use ListQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }
}

/**
 * Characterization coverage for {@see ListQueryTrait::buildListQuery()} — the full
 * `FOR ... [FILTER ...] [SORT ...] [LIMIT ...] RETURN { fields }` listing query,
 * orchestrating active/facets/filter/search/sort/limit/fields.
 */
class ListQueryTraitTest extends TestCase
{
    private function stub() :ListQueryTraitStub
    {
        return new ListQueryTraitStub() ;
    }

    public function testEmptyInitListsEverything() :void
    {
        $binds = [] ;
        $this->assertSame( 'FOR doc IN @@collection RETURN doc' , $this->stub()->buildListQuery( [] , $binds ) ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testLimitOffsetAndSort() :void
    {
        $stub = $this->stub() ;
        $stub->sortable = [ 'name' => 'name' , 'age' => 'age' ] ; // fail-closed: sort keys must be whitelisted

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection SORT doc.name ASC, doc.age DESC LIMIT 5, 10 RETURN doc' ,
            $stub->buildListQuery( [ Arango::LIMIT => 10 , Arango::OFFSET => 5 , 'sort' => 'name,-age' ] , $binds ) ,
        ) ;
    }

    public function testActiveAndExtraConditions() :void
    {
        $stub = $this->stub() ;
        $stub->activable = true ;

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.active == @active && doc.y==2 RETURN doc' ,
            $stub->buildListQuery( [ Arango::ACTIVE => false , Arango::CONDITIONS => [ 'doc.y==2' ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'users' , 'active' => 0 ] , $binds ) ;
    }

    public function testDebugFlagDoesNotAlterTheQuery() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection RETURN doc' ,
            $this->stub()->buildListQuery( [ Arango::DEBUG => true ] , $binds ) ,
        ) ;
    }

    public function testInstanceConditionsAreAppliedAsFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.k==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.k==1 RETURN doc' ,
            $stub->buildListQuery( [] , $binds ) ,
        ) ;
    }

    public function testCollectGroupingDerivesReturn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection COLLECT status = doc.status RETURN {status}' ,
            $this->stub()->buildListQuery
            (
                [ Arango::COLLECT => [ AQL::ASSIGN => [ 'status' => 'doc.status' ] ] ] ,
                $binds
            ) ,
        ) ;
    }

    public function testCollectGroupingWithCount() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection COLLECT category = doc.category WITH COUNT INTO count RETURN {category, count}' ,
            $this->stub()->buildListQuery
            (
                [ Arango::COLLECT => [ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::WITH_COUNT => 'count' ] ] ,
                $binds
            ) ,
        ) ;
    }

    public function testCollectDropsDocumentSort() :void
    {
        // A document-based ?sort= must be ignored once COLLECT is active (doc is gone).
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection COLLECT status = doc.status RETURN {status}' ,
            $this->stub()->buildListQuery
            (
                [
                    Arango::COLLECT => [ AQL::ASSIGN => [ 'status' => 'doc.status' ] ] ,
                    Arango::SORT    => 'name,-age' ,
                ] ,
                $binds
            ) ,
        ) ;
    }

    public function testGroupByHighLevelSpecWithCountAndSort() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection COLLECT category = doc.category WITH COUNT INTO count SORT count DESC RETURN {category, count}' ,
            $this->stub()->buildListQuery
            (
                [ Arango::GROUP => [ Group::BY => 'category' , Group::COUNT => true , Group::SORT => '-count' ] ] ,
                $binds
            ) ,
        ) ;
    }

    public function testGroupByHighLevelAggregateWithFilterAndLimit() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.y==1 COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount) LIMIT 10 RETURN {year, total}' ,
            $this->stub()->buildListQuery
            (
                [
                    Arango::CONDITIONS => [ 'doc.y==1' ] ,
                    Arango::GROUP => [
                        Group::BY  => [ 'year' => 'created' ] ,
                        Group::ALT => [ 'year' => 'dateYear' ] ,
                        Group::AGG => [ 'total' => 'sum:amount' ] ,
                    ] ,
                    Arango::LIMIT => 10 ,
                ] ,
                $binds
            ) ,
        ) ;
    }

    public function testCollectKeepsLimitAndExplicitReturn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection COLLECT y = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount) LIMIT 5 RETURN { year: y, revenue: total }' ,
            $this->stub()->buildListQuery
            (
                [
                    Arango::COLLECT => [
                        AQL::ASSIGN    => [ 'y' => 'DATE_YEAR(doc.created)' ] ,
                        AQL::AGGREGATE => [ 'total' => 'SUM(doc.amount)' ] ,
                    ] ,
                    Arango::RETURN => '{ year: y, revenue: total }' ,
                    Arango::LIMIT  => 5 ,
                ] ,
                $binds
            ) ,
        ) ;
    }
}
