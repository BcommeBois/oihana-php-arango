<?php

namespace tests\oihana\arango\models\traits\aql;

use ReflectionMethod;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Search;
use oihana\arango\models\traits\aql\SearchTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see SearchTrait} (and the BindTrait it relies on) for
 * isolated testing. Bind names are passed explicitly, so the query id is unused
 * and the emitted AQL / `$binds` are deterministic.
 */
class SearchTraitStub
{
    use SearchTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
    }
}

/**
 * Characterization coverage for {@see SearchTrait}: initializeSearchable and
 * prepareSearch (the `?search=Marc,Marco` grammar turned into a parenthesised
 * OR of case-insensitive LIKE() predicates over every searchable field).
 */
class SearchTraitTest extends TestCase
{
    private function stub( ?array $searchable = [ 'name' , 'firstName' ] ) :SearchTraitStub
    {
        $stub = new SearchTraitStub() ;
        $stub->searchable = $searchable ;
        return $stub ;
    }

    // ---------------------------------------------------------------- initializeSearchable

    public function testInitializeSearchableSetsAndReturnsSelf() :void
    {
        $stub = new SearchTraitStub() ;
        $result = $stub->initializeSearchable( [ 'searchable' => [ 'a' ] ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertSame( [ 'a' ] , $stub->searchable ) ;
    }

    public function testInitializeSearchableWithEmptyArrayKeepsExisting() :void
    {
        $stub = new SearchTraitStub() ;
        $stub->initializeSearchable( [ 'searchable' => [ 'a' ] ] ) ;
        $stub->initializeSearchable( [] ) ;

        $this->assertSame( [ 'a' ] , $stub->searchable ) ;
    }

    // ---------------------------------------------------------------- prepareSearch : happy paths

    public function testSingleWordOrsEverySearchableField() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))' ,
            $this->stub()->prepareSearch( 'Marc' , $binds ) ,
        ) ;
        $this->assertSame( [ 'search_0' => '%Marc%' ] , $binds ) ;
    }

    public function testCommaSeparatedWordsEachBindAndOrAcrossFields() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true)'
            . ' || LIKE(doc.name,@search_1,true) || LIKE(doc.firstName,@search_1,true))' ,
            $this->stub()->prepareSearch( 'Marc,Marco' , $binds ) ,
        ) ;
        $this->assertSame( [ 'search_0' => '%Marc%' , 'search_1' => '%Marco%' ] , $binds ) ;
    }

    public function testSearchPassedAsArrayUsesTheSearchKey() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))' ,
            $this->stub()->prepareSearch( [ Arango::SEARCH => 'Marc' ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'search_0' => '%Marc%' ] , $binds ) ;
    }

    public function testExplicitSearchableParameterOverridesInstanceFields() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LIKE(doc.email,@search_0,true))' ,
            $this->stub()->prepareSearch( 'Marc' , $binds , [ 'email' ] ) ,
        ) ;
    }

    public function testCustomDocumentReferenceIsUsedInKeys() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LIKE(x.name,@search_0,true))' ,
            $this->stub()->prepareSearch( 'Marc' , $binds , [ 'name' ] , 'x' ) ,
        ) ;
    }

    // ---------------------------------------------------------------- prepareSearch : null results

    public function testEmptyStringReturnsNull() :void
    {
        $binds = [] ;
        $this->assertNull( $this->stub()->prepareSearch( '' , $binds ) ) ;
    }

    public function testNullSearchReturnsNull() :void
    {
        $binds = [] ;
        $this->assertNull( $this->stub()->prepareSearch( null , $binds ) ) ;
    }

    public function testArrayWithoutSearchKeyReturnsNull() :void
    {
        $binds = [] ;
        $this->assertNull( $this->stub()->prepareSearch( [] , $binds ) ) ;
    }

    public function testEmptySearchableReturnsNull() :void
    {
        $binds = [] ;
        $this->assertNull( $this->stub()->prepareSearch( 'Marc' , $binds , [] ) ) ;
    }

    public function testNullInstanceSearchableReturnsNull() :void
    {
        $binds = [] ;
        $this->assertNull( $this->stub( null )->prepareSearch( 'Marc' , $binds ) ) ;
    }

    // ---------------------------------------------------------------- prepareSearch : permission gating (VF5)

    public function testGatedSearchableFieldKeptWhenAuthorized() :void
    {
        $stub  = $this->stub( [ 'name' , [ Search::KEY => 'firstName' , Search::REQUIRES => 'u:fn' ] ] ) ;
        $binds = [] ;

        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))' ,
            $stub->prepareSearch( [ Arango::SEARCH => 'Marc' , Arango::AUTHORIZER => fn() => true ] , $binds ) ,
        ) ;
    }

    public function testGatedSearchableFieldDroppedWhenDenied() :void
    {
        $stub  = $this->stub( [ 'name' , [ Search::KEY => 'firstName' , Search::REQUIRES => 'u:fn' ] ] ) ;
        $binds = [] ;

        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true))' ,
            $stub->prepareSearch( [ Arango::SEARCH => 'Marc' , Arango::AUTHORIZER => fn() => false ] , $binds ) ,
        ) ;
    }

    public function testMapFormGatedEntryIsTolerated() :void
    {
        // The 'field' => [ … ] map form is accepted too (field falls back to the key).
        $stub  = $this->stub( [ 'name' , 'firstName' => [ Search::REQUIRES => 'u:fn' ] ] ) ;
        $binds = [] ;

        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true))' ,
            $stub->prepareSearch( [ Arango::SEARCH => 'Marc' , Arango::AUTHORIZER => fn() => false ] , $binds ) ,
        ) ;
    }

    public function testEverySearchableFieldDeniedMatchesNothing() :void
    {
        $stub  = $this->stub( [ [ Search::KEY => 'secret' , Search::REQUIRES => 's:x' ] ] ) ;
        $binds = [] ;

        $this->assertSame( 'false' , $stub->prepareSearch( [ Arango::SEARCH => 'Marc' , Arango::AUTHORIZER => fn() => false ] , $binds ) ) ;
        $this->assertSame( [] , $binds , 'No term is bound when the search matches nothing.' ) ;
    }

    public function testGateFailsOpenWithoutAuthorizer() :void
    {
        // No Arango::AUTHORIZER → the gated field stays searchable (layer disabled).
        $stub  = $this->stub( [ 'name' , [ Search::KEY => 'firstName' , Search::REQUIRES => 'u:fn' ] ] ) ;
        $binds = [] ;

        $this->assertSame
        (
            '(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))' ,
            $stub->prepareSearch( 'Marc' , $binds ) ,
        ) ;
    }

    // ---------------------------------------------------------------- getSearchableSpecs

    public function testGetSearchableSpecsNormalizesEveryEntryShape() :void
    {
        $stub = $this->stub(
        [
            'name' ,                                                    // plain string
            [ Search::KEY => 'salary' , Search::REQUIRES => 'hr:s' ] ,  // list entry (Search::KEY)
            'email' => [ Search::REQUIRES => 'e:r' ] ,                  // map form (tolerated)
        ]) ;

        $method = new ReflectionMethod( $stub , 'getSearchableSpecs' ) ;

        $this->assertSame
        (
            [
                'name'   => [] ,
                'salary' => [ Search::REQUIRES => 'hr:s' ] ,
                'email'  => [ Search::REQUIRES => 'e:r' ] ,
            ] ,
            $method->invoke( $stub ) ,
        ) ;
    }
}
