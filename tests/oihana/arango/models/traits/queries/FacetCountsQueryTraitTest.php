<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use oihana\exceptions\ValidationException;

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
            'category'      => [ Facet::TYPE => Facet::FIELD ] ,
            'status'        => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => 'state' ] ,
            'keywords'      => [ Facet::TYPE => Facet::IN ] ,
            'author'        => [ Facet::TYPE => Facet::JOIN ] , // unsupported in v1
            'currency'      => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].priceCurrency' ] , // object-array sub-field
            'currencyField' => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => 'offers[*].priceCurrency' ] , // [*] overrides FIELD
            'tags'          => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'tags[*]' ] , // expansion marker, no sub-field
            'deep'          => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b[*].c' ] , // multi-level → one FOR per hop
            'deepMid'       => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b.c[*].d' ] , // intermediate path between hops
            'danger'        => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].x);y' ] , // dangerous sub-field → guarded

            // Facet::DISTINCT => true : count distinct ROOT documents per bucket
            // (COUNT_DISTINCT(doc._key)) instead of the unwound elements.
            'currencyDistinct' => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].priceCurrency' , Facet::DISTINCT => true ] , // [*] sub-field
            'keywordsDistinct' => [ Facet::TYPE => Facet::IN    , Facet::DISTINCT => true ] , // IN family unwind
            'deepDistinct'     => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b[*].c' , Facet::DISTINCT => true ] , // multi-hop → distinct still on root _key
            'categoryDistinct' => [ Facet::TYPE => Facet::FIELD , Facet::DISTINCT => true ] , // scalar FIELD → flag is a no-op
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

    public function testArraySubFieldUnwindsAndProjects() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET currency = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currency}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testArrayExpansionMarkerOverridesFieldType() :void
    {
        $binds = [] ;
        // Declared FIELD, but the `[*]` marker forces the unwind (D1).
        $this->assertSame
        (
            'LET currencyField = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currencyField}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyField' ] , $binds ) ,
        ) ;
    }

    public function testArrayExpansionWithoutSubFieldProjectsItem() :void
    {
        $binds = [] ;
        // `tags[*]` (no sub-field) projects the element itself (D4).
        $this->assertSame
        (
            'LET tags = (FOR doc IN @@collection FOR item IN doc.tags COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {tags}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'tags' ] , $binds ) ,
        ) ;
    }

    public function testArraySubFieldInheritsListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'LET currency = (FOR doc IN @@collection FILTER doc.active==1 FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currency}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ,
        ) ;
    }

    public function testMultiLevelExpansionUnwindsNestedArrays() :void
    {
        $binds = [] ;
        // `a[*].b[*].c` is a two-hop expansion → one FOR per hop, counted per leaf.
        $this->assertSame
        (
            'LET deep = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b COLLECT value = item2.c WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {deep}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deep' ] , $binds ) ,
        ) ;
    }

    public function testIntermediatePathBetweenExpansions() :void
    {
        $binds = [] ;
        // `a[*].b.c[*].d` → the path between two hops descends within the element.
        $this->assertSame
        (
            'LET deepMid = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b.c COLLECT value = item2.d WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {deepMid}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deepMid' ] , $binds ) ,
        ) ;
    }

    public function testDangerousSubFieldIsGuarded() :void
    {
        // The sub-field is config-trusted but still guarded by assertAttributeName.
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'danger' ] , $binds ) ;
    }

    public function testDistinctArraySubFieldCountsRootDocuments() :void
    {
        $binds = [] ;
        // Facet::DISTINCT => true on a `[*]` sub-field: the tail aggregates
        // COUNT_DISTINCT(doc._key) instead of WITH COUNT — a document repeating
        // the same priceCurrency across several offers is counted once.
        $this->assertSame
        (
            'LET currencyDistinct = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {currencyDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyDistinct' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testDistinctInFamilyCountsRootDocuments() :void
    {
        $binds = [] ;
        // The IN/LIST family unwinds too, so it over-counts the same way — the
        // flag applies there as well.
        $this->assertSame
        (
            'LET keywordsDistinct = (FOR doc IN @@collection FOR item IN doc.keywordsDistinct COLLECT value = item AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {keywordsDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'keywordsDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctMultiHopAlwaysTargetsRootKey() :void
    {
        $binds = [] ;
        // Whatever the `[*]` hop depth, the distinct always targets the ROOT
        // document key (doc._key), never the intermediate item references.
        $this->assertSame
        (
            'LET deepDistinct = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b COLLECT value = item2.c AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {deepDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deepDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctIsNoOpOnScalarFieldFacet() :void
    {
        $binds = [] ;
        // A scalar FIELD already emits one row per document, so DISTINCT cannot
        // over-count: the flag is ignored and the default WITH COUNT tail stays.
        $this->assertSame
        (
            'LET categoryDistinct = (FOR doc IN @@collection COLLECT value = doc.categoryDistinct WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {categoryDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'categoryDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctInheritsListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        // The distinct sub-query shares the same conjunctive filter as the list.
        $this->assertSame
        (
            'LET currencyDistinct = (FOR doc IN @@collection FILTER doc.active==1 FOR item IN doc.offers COLLECT value = item.priceCurrency AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {currencyDistinct}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyDistinct' ] , $binds ) ,
        ) ;
    }
}
