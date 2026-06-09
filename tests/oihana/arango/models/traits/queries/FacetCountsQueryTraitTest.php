<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Host composing {@see ListQueryTrait} (filter/bind machinery) and
 * {@see FacetCountsQueryTrait} (the builder under test).
 */
class FacetCountsQueryTraitStub
{
    use ListQueryTrait , FacetCountsQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'articles' ;
        $this->facets =
        [
            'category' => [ Facet::TYPE => Facet::FIELD ] ,
            'status'   => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => 'state' ] ,
            'keywords' => [ Facet::TYPE => Facet::IN ] ,
            'author'   => [ Facet::TYPE => Facet::JOIN ] , // unsupported in v1
        ] ;
    }
}

/**
 * Unit coverage for {@see FacetCountsQueryTrait::buildFacetCountsQuery()} — the
 * multi-`LET` facet-counts query, one counting sub-query per whitelisted facet.
 */
class FacetCountsQueryTraitTest extends TestCase
{
    private function stub() :FacetCountsQueryTraitStub
    {
        return new FacetCountsQueryTraitStub() ;
    }

    public function testEmptyWhenNoDimensions() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [] , $binds ) ) ;
    }

    public function testUnknownDimensionIsIgnored() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'nope' ] , $binds ) ) ;
    }

    public function testUnsupportedFacetTypeIsSkipped() :void
    {
        $binds = [] ;
        // 'author' is a JOIN facet → not countable in v1 → nothing emitted.
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'author' ] , $binds ) ) ;
    }

    public function testFieldFacet() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {category}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testFieldFacetWithPropertyOverride() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET status = (FOR doc IN @@collection COLLECT value = doc.state WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {status}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'status' ] , $binds ) ,
        ) ;
    }

    public function testInFacetUnwindsArray() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET keywords = (FOR doc IN @@collection FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {keywords}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'keywords' ] , $binds ) ,
        ) ;
    }

    public function testMultipleDimensionsSkipUnsupported() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'LET keywords = (FOR doc IN @@collection FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'RETURN {category, keywords}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category,author,keywords' ] , $binds ) ,
        ) ;
    }

    public function testCountsShareTheListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection FILTER doc.active==1 COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {category}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category' ] , $binds ) ,
        ) ;
    }
}
