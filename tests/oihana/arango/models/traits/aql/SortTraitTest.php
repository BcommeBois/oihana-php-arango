<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\aql\SortTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see SortTrait} for isolated testing.
 */
class SortTraitStub
{
    use SortTrait ;
}

/**
 * Unit coverage for {@see SortTrait::prepareSort()} — the textual sort
 * grammar (`name,-identifier`) turned into an AQL `SORT` expression, with
 * optional alias mapping through the `$sortable` whitelist.
 */
class SortTraitTest extends TestCase
{
    private function stub( ?array $sortable = null , ?string $sortDefault = null ) :SortTraitStub
    {
        $stub = new SortTraitStub() ;
        $stub->sortable    = $sortable ;
        $stub->sortDefault = $sortDefault ;
        return $stub ;
    }

    public function testSingleAscending() :void
    {
        $this->assertSame( 'doc.name ASC' , $this->stub()->prepareSort( [ 'sort' => 'name' ] ) ) ;
    }

    public function testLeadingHyphenIsDescending() :void
    {
        $this->assertSame( 'doc.name DESC' , $this->stub()->prepareSort( [ 'sort' => '-name' ] ) ) ;
    }

    public function testMultipleCriteria() :void
    {
        $this->assertSame
        (
            'doc.name ASC, doc.age DESC' ,
            $this->stub()->prepareSort( [ 'sort' => 'name,-age' ] ) ,
        ) ;
    }

    public function testSortableMappingResolvesAlias() :void
    {
        $this->assertSame
        (
            'doc.name ASC' ,
            $this->stub( [ 'title' => 'name' ] )->prepareSort( [ 'sort' => 'title' ] ) ,
        ) ;
    }

    public function testUnknownKeyIsSkippedWhenSortableProvided() :void
    {
        $this->assertSame( '' , $this->stub( [ 'title' => 'name' ] )->prepareSort( [ 'sort' => 'nope' ] ) ) ;
    }

    public function testCustomDocumentReference() :void
    {
        $this->assertSame( 'x.name ASC' , $this->stub()->prepareSort( [ 'sort' => 'name' ] , null , 'x' ) ) ;
    }

    public function testArraySortIsJoinedAsIs() :void
    {
        $this->assertSame
        (
            'doc.foo ASC, doc.bar DESC' ,
            $this->stub()->prepareSort( [ 'sort' => [ 'doc.foo ASC' , 'doc.bar DESC' ] ] ) ,
        ) ;
    }

    public function testFallsBackOnSortDefaultWhenNoSortGiven() :void
    {
        $this->assertSame( 'doc.name ASC' , $this->stub( sortDefault: 'name' )->prepareSort( [] ) ) ;
    }

    public function testEmptyWhenNoSortAndNoDefault() :void
    {
        $this->assertSame( '' , $this->stub()->prepareSort( [] ) ) ;
    }

    public function testInitializeSortableSetsTheWhitelist() :void
    {
        $stub = new SortTraitStub() ;
        $stub->initializeSortable( [ AQL::SORTABLE => [ 'title' => 'name' ] ] ) ;

        $this->assertSame( [ 'title' => 'name' ] , $stub->sortable ) ;
        $this->assertSame( 'doc.name ASC' , $stub->prepareSort( [ 'sort' => 'title' ] ) ) ;
    }
}
