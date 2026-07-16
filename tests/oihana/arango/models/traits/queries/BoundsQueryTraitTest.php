<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Bound;
use oihana\arango\models\traits\queries\BoundsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Host composing {@see ListQueryTrait} (filter/bind machinery) and
 * {@see BoundsQueryTrait} (the builder under test).
 */
class BoundsQueryTraitStub
{
    use ListQueryTrait , BoundsQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'products' ;
        $this->bounds =
        [
            'width'  => true ,                                          // flat scalar
            'height' => true ,                                          // flat scalar
            'weight' => [ Bound::PROPERTY => 'grossWeight' ] ,          // flat, renamed property
            'price'  => [ Bound::PROPERTY => 'offers[*].price' ] ,      // nested, one hop
            'deep'   => [ Bound::PROPERTY => 'offers[*].tiers[*].amount' ] , // nested, two hops
        ] ;
    }
}

/**
 * Unit coverage for {@see BoundsQueryTrait::buildBoundsQuery()} — the numeric
 * `{ min, max, count }` extent query, flat fields sharing one COLLECT and nested
 * ([*]) fields getting their own FIRST(( … )) LET, plus the exclusion options.
 */
class BoundsQueryTraitTest extends TestCase
{
    private function stub() :BoundsQueryTraitStub
    {
        return new BoundsQueryTraitStub() ;
    }

    public function testFlatFieldsShareASingleCollect() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width,height' ] , $binds ) ;

        $this->assertStringContainsString( 'COLLECT AGGREGATE width_min = MIN(doc.width), width_max = MAX(doc.width)' , $query ) ;
        $this->assertStringContainsString( 'width_count = SUM(doc.width != null ? 1 : 0)' , $query ) ;
        $this->assertStringContainsString( 'height_min = MIN(doc.height), height_max = MAX(doc.height)' , $query ) ;
        $this->assertStringContainsString( '{width: {min: width_min, max: width_max, count: width_count}, height: {min: height_min, max: height_max, count: height_count}}' , $query ) ;

        // One pass: a single COLLECT, no LET, no MERGE for flat-only.
        $this->assertSame( 1 , substr_count( $query , 'COLLECT' ) ) ;
        $this->assertStringNotContainsString( 'LET' , $query ) ;
        $this->assertStringNotContainsString( 'MERGE' , $query ) ;
    }

    public function testFlatFieldHonoursItsRenamedProperty() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'weight' ] , $binds ) ;

        $this->assertStringContainsString( 'weight_min = MIN(doc.grossWeight), weight_max = MAX(doc.grossWeight)' , $query ) ;
        $this->assertStringContainsString( '{weight: {min: weight_min, max: weight_max, count: weight_count}}' , $query ) ;
    }

    public function testNestedFieldUnwindsIntoItsOwnLet() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'price' ] , $binds ) ;

        $this->assertStringContainsString( 'LET price = FIRST((FOR doc IN' , $query ) ;
        $this->assertStringContainsString( 'FOR item IN doc.offers' , $query ) ;
        $this->assertStringContainsString( 'COLLECT AGGREGATE lo = MIN(item.price), hi = MAX(item.price)' , $query ) ;
        $this->assertStringContainsString( 'cnt = SUM(item.price != null ? 1 : 0)' , $query ) ;
        $this->assertStringContainsString( 'RETURN {min: lo, max: hi, count: cnt}' , $query ) ;
        $this->assertStringContainsString( 'RETURN {price: price}' , $query ) ;
    }

    public function testNestedFieldUnwindsEveryHop() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'deep' ] , $binds ) ;

        $this->assertStringContainsString( 'FOR item IN doc.offers' , $query ) ;
        $this->assertStringContainsString( 'FOR item2 IN item.tiers' , $query ) ;
        $this->assertStringContainsString( 'MIN(item2.amount)' , $query ) ;
    }

    public function testMixBindsFlatAndMergesNested() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width,price' ] , $binds ) ;

        $this->assertStringContainsString( 'LET __bounds = FIRST((FOR doc IN' , $query ) ;
        $this->assertStringContainsString( 'LET price = FIRST((FOR doc IN' , $query ) ;
        $this->assertStringContainsString( 'RETURN MERGE(__bounds,{price: price})' , $query ) ;
    }

    public function testUnknownKeyIsIgnored() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;

        // 'depth' is not declared in the whitelist → nothing boundable.
        $this->assertSame( '' , $stub->buildBoundsQuery( [ Arango::BOUNDS => 'depth' ] , $binds ) ) ;
    }

    public function testFailClosedWhenBoundsIsNull() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = null ;
        $binds = [] ;

        $this->assertSame( '' , $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ) ;
    }

    public function testEmptyWhenNoFieldRequested() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;

        $this->assertSame( '' , $stub->buildBoundsQuery( [] , $binds ) ) ;
    }

    public function testFailClosedWhenWhitelistNormalizesToEmpty() :void
    {
        // $bounds is a non-empty array, but no entry is normalizable (a numeric
        // key with a non-string value) → the whitelist is empty → empty query.
        $stub  = $this->stub() ;
        $stub->bounds = [ 42 ] ;
        $binds = [] ;

        $this->assertSame( '' , $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ) ;
    }

    public function testAcceptsAnArrayOfFields() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => [ 'width' , 'height' ] ] , $binds ) ;

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $query ) ;
        $this->assertStringContainsString( 'height_min = MIN(doc.height)' , $query ) ;
    }

    public function testSharesTheListConditions() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery(
        [
            Arango::BOUNDS   => 'width' ,
            AQL::CONDITIONS  => [ 'doc.active == 1' ] ,
        ] , $binds ) ;

        $this->assertStringContainsString( 'FILTER doc.active == 1' , $query ) ;
    }

    public function testAcceptsABareListWhitelist() :void
    {
        // The declaration may list bare field names (numeric keys) rather than a
        // keyed map — e.g. `AQL::BOUNDS => [ 'width', 'height' ]`.
        $stub = $this->stub() ;
        $stub->bounds = [ 'density' , 'height' , 'width' ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width,height' ] , $binds ) ;

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $query ) ;
        $this->assertStringContainsString( 'height_min = MIN(doc.height)' , $query ) ;
    }

    public function testMixesBareNamesAndKeyedDefinitions() :void
    {
        // A bare name and a keyed (nested) definition in the same whitelist.
        $stub = $this->stub() ;
        $stub->bounds = [ 'width' , 'price' => [ Bound::PROPERTY => 'offers[*].price' ] ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width,price' ] , $binds ) ;

        $this->assertStringContainsString( 'width_min = MIN(doc.width)' , $query ) ;
        $this->assertStringContainsString( 'MIN(item.price)' , $query ) ;
        $this->assertStringContainsString( 'RETURN MERGE(__bounds,{price: price})' , $query ) ;
    }

    public function testPositiveExcludesNonPositiveValues() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'width' => [ Bound::POSITIVE => true ] ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ;

        $this->assertStringContainsString( 'MIN(doc.width > 0 ? doc.width : null)' , $query ) ;
        $this->assertStringContainsString( 'MAX(doc.width > 0 ? doc.width : null)' , $query ) ;
        $this->assertStringContainsString( 'SUM(doc.width > 0 ? 1 : 0)' , $query ) ;
    }

    public function testMinMaxDefineTheAcceptedDomain() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'temp' => [ Bound::MIN => -50 , Bound::MAX => 200 ] ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'temp' ] , $binds ) ;

        $this->assertStringContainsString( 'MIN(doc.temp >= -50 && doc.temp <= 200 ? doc.temp : null)' , $query ) ;
    }

    public function testIgnoreAcceptsAScalarOrAList() :void
    {
        $stub  = $this->stub() ;
        $binds = [] ;

        $stub->bounds = [ 'width' => [ Bound::IGNORE => 0 ] ] ;
        $this->assertStringContainsString( 'doc.width NOT IN [0] ? doc.width : null' , $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ) ;

        $stub->bounds = [ 'width' => [ Bound::IGNORE => [ 0 , 5 , 15 ] ] ] ;
        $this->assertStringContainsString( 'doc.width NOT IN [0,5,15] ? doc.width : null' , $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ) ;
    }

    public function testExclusionOptionsCombineWithAnd() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'width' => [ Bound::POSITIVE => true , Bound::MAX => 500 , Bound::IGNORE => 99 ] ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ;

        $this->assertStringContainsString( 'doc.width > 0 && doc.width <= 500 && doc.width NOT IN [99] ? doc.width : null' , $query ) ;
    }

    public function testBareFieldCountsNonNullValues() :void
    {
        $stub = $this->stub() ;
        $stub->bounds = [ 'width' ] ;
        $binds = [] ;
        $query = $stub->buildBoundsQuery( [ Arango::BOUNDS => 'width' ] , $binds ) ;

        // No exclusion: raw value, count of the non-null.
        $this->assertStringContainsString( 'width_min = MIN(doc.width),' , $query ) ;
        $this->assertStringContainsString( 'width_count = SUM(doc.width != null ? 1 : 0)' , $query ) ;
    }
}
