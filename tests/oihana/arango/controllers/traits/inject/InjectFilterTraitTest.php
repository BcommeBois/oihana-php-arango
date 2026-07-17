<?php

namespace tests\oihana\arango\controllers\traits\inject;

use oihana\arango\controllers\traits\inject\InjectFilterTrait;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use tests\oihana\arango\controllers\mocks\InjectFilterHost;

/**
 * Coverage for {@see InjectFilterTrait}: programmatic filter injection and the
 * `prepareFilter()` override that transparently merges URL filters with the
 * injected ones.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversTrait( InjectFilterTrait::class )]
class InjectFilterTraitTest extends TestCase
{
    // ---- injectFilter / injectFilters -----------------------------------

    public function testInjectFilterAppendsAFilterWithDefaultOperator() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilter( $init , 'userId' , 'u1' ) ;

        $this->assertSame
        (
            [ [ FilterParam::KEY => 'userId' , FilterParam::OP => FilterComparator::EQ , FilterParam::VAL => 'u1' ] ] ,
            $init[ $host->injectedKey() ]
        ) ;
    }

    public function testInjectFilterIncludesAltWhenProvidedAndStacks() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilter( $init , 'name' , 'bob' , FilterComparator::LIKE , 'lower' ) ;
        $host->callInjectFilter( $init , 'age' , 18 , FilterComparator::GE ) ;

        $filters = $init[ $host->injectedKey() ] ;

        $this->assertCount( 2 , $filters ) ;
        $this->assertSame( 'lower' , $filters[ 0 ][ FilterParam::ALT ] ) ;
        $this->assertArrayNotHasKey( FilterParam::ALT , $filters[ 1 ] ) ;
    }

    public function testInjectFiltersAddsEachDefinition() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilters( $init ,
        [
            [ FilterParam::KEY => 'agent'  , FilterParam::VAL => 'u1' ] ,
            [ FilterParam::KEY => 'method' , FilterParam::VAL => 'DELETE' , FilterParam::OP => FilterComparator::NE ] ,
        ] ) ;

        $filters = $init[ $host->injectedKey() ] ;

        $this->assertCount( 2 , $filters ) ;
        $this->assertSame( FilterComparator::EQ , $filters[ 0 ][ FilterParam::OP ] ) ;
        $this->assertSame( FilterComparator::NE , $filters[ 1 ][ FilterParam::OP ] ) ;
    }

    // ---- injectFilterGroup ----------------------------------------------

    public function testInjectFilterGroupStoresTheGroupAsOneEntry() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilterGroup( $init , FilterLogic::OR ,
        [
            [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => 'u1'  ] ,
            [ FilterParam::KEY => 'public'  , FilterParam::VAL => true  ] ,
        ]) ;

        // ONE entry — a sibling of the other injected filters, not two of them.
        $this->assertSame
        (
            [ [
                FilterLogic::OR ,
                [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => 'u1'  ] ,
                [ FilterParam::KEY => 'public'  , FilterParam::VAL => true  ] ,
            ] ] ,
            $init[ $host->injectedKey() ]
        ) ;
    }

    public function testInjectFilterGroupInjectsNothingWhenEmpty() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilterGroup( $init , FilterLogic::OR , [] ) ;

        // An operand-less group would widen the set to everything — never a scope.
        $this->assertArrayNotHasKey( $host->injectedKey() , $init ) ;
    }

    public function testInjectFilterGroupStacksBesideAPlainInjectedFilter() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilter( $init , 'active' , true ) ;
        $host->callInjectFilterGroup( $init , FilterLogic::OR ,
        [
            [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => 'u1' ] ,
            [ FilterParam::KEY => 'public'  , FilterParam::VAL => true ] ,
        ]) ;

        $filters = $init[ $host->injectedKey() ] ;

        $this->assertCount( 2 , $filters ) ;
        $this->assertSame( 'active' , $filters[ 0 ][ FilterParam::KEY ] ) ;
        $this->assertSame( FilterLogic::OR , $filters[ 1 ][ 0 ] ) ;
    }

    public function testInjectFilterGroupAcceptsANestedGroup() :void
    {
        $host = new InjectFilterHost() ;
        $init = [] ;

        $host->callInjectFilterGroup( $init , FilterLogic::AND ,
        [
            [ FilterParam::KEY => 'active' , FilterParam::VAL => true ] ,
            [ FilterLogic::OR , [ FilterParam::KEY => 'a' , FilterParam::VAL => 1 ] , [ FilterParam::KEY => 'b' , FilterParam::VAL => 2 ] ] ,
        ]) ;

        $group = $init[ $host->injectedKey() ][ 0 ] ;

        $this->assertSame( FilterLogic::AND , $group[ 0 ] ) ;
        $this->assertSame( FilterLogic::OR  , $group[ 2 ][ 0 ] ) ;
    }

    public function testPrepareFilterMergesAnInjectedGroupAsOneOperand() :void
    {
        $host = new InjectFilterHost() ;
        $host->urlFilterStub = [ FilterParam::KEY => 'status' , FilterParam::VAL => 'active' ] ;

        $init = [] ;
        $host->callInjectFilterGroup( $init , FilterLogic::OR ,
        [
            [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => 'u1' ] ,
            [ FilterParam::KEY => 'public'  , FilterParam::VAL => true ] ,
        ]) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , {status} , [ 'or' , {ownerId} , {public} ] ]
        //   → (status && (ownerId || public)) — the scope restricts, never widens.
        $this->assertCount( 3 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertSame( 'status' , $result[ 1 ][ FilterParam::KEY ] ) ;
        $this->assertSame( FilterLogic::OR , $result[ 2 ][ 0 ] ) ;
    }

    public function testPrepareFilterReturnsALoneInjectedGroupUnwrapped() :void
    {
        $host = new InjectFilterHost() ; // urlFilterStub null

        $init = [] ;
        $host->callInjectFilterGroup( $init , FilterLogic::OR ,
        [
            [ FilterParam::KEY => 'ownerId' , FilterParam::VAL => 'u1' ] ,
            [ FilterParam::KEY => 'public'  , FilterParam::VAL => true ] ,
        ]) ;

        $result = $host->callPrepareFilter( $init ) ;

        // No URL filter — the group is the whole filter, as a top-level or-group.
        $this->assertSame( FilterLogic::OR , $result[ 0 ] ) ;
        $this->assertSame( 'ownerId' , $result[ 1 ][ FilterParam::KEY ] ) ;
    }

    // ---- prepareFilter override -----------------------------------------

    public function testPrepareFilterReturnsUrlFilterWhenNothingInjected() :void
    {
        $host = new InjectFilterHost() ;
        $host->urlFilterStub = [ FilterParam::KEY => 'status' , FilterParam::VAL => 'active' ] ;

        $this->assertSame( $host->urlFilterStub , $host->callPrepareFilter() ) ;
    }

    public function testPrepareFilterReturnsSingleInjectedWhenNoUrlFilter() :void
    {
        $host = new InjectFilterHost() ; // urlFilterStub null

        $init = [] ;
        $host->callInjectFilter( $init , 'userId' , 'u1' ) ;

        $result = $host->callPrepareFilter( $init ) ;

        $this->assertSame( 'userId' , $result[ FilterParam::KEY ] ) ; // unwrapped single filter
    }

    public function testPrepareFilterReturnsInjectedArrayWhenMultipleAndNoUrlFilter() :void
    {
        $host = new InjectFilterHost() ;

        $init = [] ;
        $host->callInjectFilter( $init , 'a' , 1 ) ;
        $host->callInjectFilter( $init , 'b' , 2 ) ;

        $result = $host->callPrepareFilter( $init ) ;

        $this->assertCount( 2 , $result ) ;
    }

    public function testPrepareFilterMergesSingleUrlFilterWithInjected() :void
    {
        $host = new InjectFilterHost() ;
        $host->urlFilterStub = [ FilterParam::KEY => 'status' , FilterParam::VAL => 'active' ] ; // single (has KEY)

        $init = [] ;
        $host->callInjectFilter( $init , 'userId' , 'u1' ) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , {status} , {userId} ] — an explicit and-group, the URL filter untouched.
        $this->assertCount( 3 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertSame( 'status' , $result[ 1 ][ FilterParam::KEY ] ) ;
        $this->assertSame( 'userId' , $result[ 2 ][ FilterParam::KEY ] ) ;
    }

    public function testPrepareFilterMergesUrlFilterArrayWithInjected() :void
    {
        $host = new InjectFilterHost() ;
        // an already-arrayed URL filter (no top-level KEY)
        $host->urlFilterStub =
        [
            [ FilterParam::KEY => 'a' , FilterParam::VAL => 1 ] ,
            [ FilterParam::KEY => 'b' , FilterParam::VAL => 2 ] ,
        ] ;

        $init = [] ;
        $host->callInjectFilter( $init , 'userId' , 'u1' ) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , [ {a} , {b} ] , {userId} ] — the URL list enters whole, as one operand.
        $this->assertCount( 3 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertCount( 2 , $result[ 1 ] ) ;
        $this->assertSame( 'userId' , $result[ 2 ][ FilterParam::KEY ] ) ;
    }

    // ---- the injected filters must never fall inside the caller's own logic ----

    public function testPrepareFilterKeepsInjectedOutOfAUrlFilterGroup() :void
    {
        $host = new InjectFilterHost() ;

        // A logic group : its operator must never reach the injected filter, or the
        // server-side scope would become an alternative instead of a restriction.
        $host->urlFilterStub =
        [
            FilterLogic::OR ,
            [ FilterParam::KEY => 'name' , FilterParam::VAL => 'a' ] ,
            [ FilterParam::KEY => 'name' , FilterParam::VAL => 'b' ] ,
        ] ;

        $init = [] ;
        $host->callInjectFilter( $init , 'seller._key' , 's1' ) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , [ 'or' , {a} , {b} ] , {seller._key} ]
        // NOT   [ 'or' , {a} , {b} , {seller._key} ] — which would OR the scope away.
        $this->assertCount( 3 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertSame( FilterLogic::OR  , $result[ 1 ][ 0 ] ) ;
        $this->assertSame( 'seller._key' , $result[ 2 ][ FilterParam::KEY ] ) ;
    }

    public function testPrepareFilterKeepsInjectedOutOfAUrlFilterNegation() :void
    {
        $host = new InjectFilterHost() ;

        // A negation group : splicing would push the injected filter under the `not`,
        // breaking both the negation and the scope.
        $host->urlFilterStub =
        [
            FilterLogic::NOT ,
            [ FilterParam::KEY => 'status' , FilterParam::VAL => 'archived' ] ,
        ] ;

        $init = [] ;
        $host->callInjectFilter( $init , 'seller._key' , 's1' ) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , [ 'not' , {status} ] , {seller._key} ]
        $this->assertCount( 3 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertSame( FilterLogic::NOT , $result[ 1 ][ 0 ] ) ;
        $this->assertCount( 2 , $result[ 1 ] ) ; // the negation keeps its single operand
        $this->assertSame( 'seller._key' , $result[ 2 ][ FilterParam::KEY ] ) ;
    }

    public function testPrepareFilterKeepsInjectedOutOfAUrlFilterGroupWithMultipleInjected() :void
    {
        $host = new InjectFilterHost() ;
        $host->urlFilterStub =
        [
            FilterLogic::OR ,
            [ FilterParam::KEY => 'name' , FilterParam::VAL => 'a' ] ,
            [ FilterParam::KEY => 'name' , FilterParam::VAL => 'b' ] ,
        ] ;

        $init = [] ;
        $host->callInjectFilter( $init , 'seller._key' , 's1' ) ;
        $host->callInjectFilter( $init , 'active' , true ) ;

        $result = $host->callPrepareFilter( $init ) ;

        // [ 'and' , [ 'or' , … ] , {seller._key} , {active} ] — every injected filter
        // stays a sibling of the and-group, never an operand of the caller's `or`.
        $this->assertCount( 4 , $result ) ;
        $this->assertSame( FilterLogic::AND , $result[ 0 ] ) ;
        $this->assertSame( FilterLogic::OR  , $result[ 1 ][ 0 ] ) ;
        $this->assertSame( 'seller._key' , $result[ 2 ][ FilterParam::KEY ] ) ;
        $this->assertSame( 'active' , $result[ 3 ][ FilterParam::KEY ] ) ;
    }
}
