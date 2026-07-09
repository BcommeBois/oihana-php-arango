<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\queries\FilteredScopeTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Host composing {@see ListQueryTrait} (the filtering fragments) and
 * {@see FilteredScopeTrait} (the assembler under test).
 */
class FilteredScopeStub
{
    use ListQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'articles' ;
    }

    public function scope( array $init , array &$binds ) :array
    {
        return $this->buildFilteredScope( $init , $binds ) ;
    }
}

/**
 * Unit coverage for {@see FilteredScopeTrait::buildFilteredScope()} — the shared
 * `FOR` + conjunctive `FILTER` construction reused by list / count / facet
 * counts / bounds. It must stay identical across all four so their numbers agree.
 */
class FilteredScopeTraitTest extends TestCase
{
    private function stub() :FilteredScopeStub
    {
        return new FilteredScopeStub() ;
    }

    public function testReturnsTheForAndFilterPair() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $scope = $stub->scope( [] , $binds ) ;

        $this->assertCount( 2 , $scope ) ;
        [ $for , $filter ] = $scope ;

        $this->assertStringContainsString( 'FOR doc IN @@collection' , $for ) ;
        $this->assertNull( $filter ) ; // no active / facet / filter / search / condition → empty FILTER
    }

    public function testFilterFoldsTheInjectedConditions() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        [ , $filter ] = $stub->scope( [ AQL::CONDITIONS => [ 'doc.active == 1' ] ] , $binds ) ;

        $this->assertStringContainsString( 'FILTER' , $filter ) ;
        $this->assertStringContainsString( 'doc.active == 1' , $filter ) ;
    }

    public function testBindsTheCollectionByReference() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $stub->scope( [] , $binds ) ;

        // The collection bind parameter is registered by reference.
        $this->assertContains( 'articles' , $binds ) ;
    }
}
