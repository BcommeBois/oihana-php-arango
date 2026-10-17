<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\CountQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see CountQueryTrait}. The trait does not compose
 * ArangoTrait, so the stub declares `$collection` itself. buildCountQuery is
 * protected, hence the public proxy.
 */
class CountQueryTraitStub
{
    use CountQueryTrait ;

    public ?string $collection = 'users' ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }

    public function callBuildCountQuery( array $init = [] , array &$binds = [] ) :string
    {
        return $this->buildCountQuery( $init , $binds ) ;
    }
}

/**
 * Characterization coverage for {@see CountQueryTrait::buildCountQuery()} — the
 * optimized fast count `RETURN LENGTH(@@collection)` and the full filtered count
 * `RETURN LENGTH( FOR ... FILTER ... RETURN 1 )`.
 */
class CountQueryTraitTest extends TestCase
{
    private function stub() :CountQueryTraitStub
    {
        return new CountQueryTraitStub() ;
    }

    public function testOptimizedCountIsAPlainLength() :void
    {
        $binds = [] ;
        $this->assertSame( 'RETURN LENGTH(@@collection)' , $this->stub()->callBuildCountQuery( [ Arango::OPTIMIZED => true ] , $binds ) ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testFullCountWithoutFiltersHasNoFilterClause() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection RETURN 1)' ,
            $this->stub()->callBuildCountQuery( [] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testFullCountAppliesActiveAndExtraConditions() :void
    {
        $stub = $this->stub() ;
        $stub->activable = true ;

        $binds = [] ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc.active == @active && doc.z==1 RETURN 1)' ,
            $stub->callBuildCountQuery( [ Arango::ACTIVE => true , Arango::CONDITIONS => [ 'doc.z==1' ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'users' , 'active' => 1 ] , $binds ) ;
    }

    /**
     * A filtered count walks the same loop as its list, and carries the same index
     * hint — the count of a page is served by the index of the page.
     *
     * 🚨 As a preference, never forced : an index that serves the list for its sort
     * alone is one a count cannot use, and the server refuses the query (measured).
     */
    public function testAFilteredCountCarriesTheIndexHint() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;

        $stub->indexHint      = [ 'byOwner' , 'byDue' ] ;
        $stub->forceIndexHint = true ;

        $this->assertStringContainsString
        (
            'FOR doc IN @@collection OPTIONS {"indexHint":["byOwner","byDue"]} FILTER' ,
            $stub->callBuildCountQuery( [ Arango::CONDITIONS => [ 'doc.owner == "o1"' ] ] , $binds )
        ) ;
    }
}
