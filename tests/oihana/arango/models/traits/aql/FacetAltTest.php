<?php

namespace tests\oihana\arango\models\traits\aql;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterComparator;

/**
 * Unit coverage for the value-side `alt` transformation on the FIELD, EDGE and
 * JOIN facets: the object form `alt:{ key, val }` (and `val:true` mirror), read
 * either from the model definition (`Facet::ALT`) or from the URL request object
 * (`{op,val,alt}`), with the request taking precedence.
 */
class FacetAltTest extends TestCase
{
    private FacetTraitStub $stub;
    private array $binds;

    protected function setUp(): void
    {
        $this->stub  = new FacetTraitStub() ;
        $this->binds = [] ;
    }

    // ========================================
    // FIELD
    // ========================================

    public function testFieldAltFromDefinitionMirrorsBothSides(): void
    {
        $facet  = [ Facet::OP => FilterComparator::EQ , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callField( 'email' , 'JEAN@X.COM' , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/^\(LOWER\(doc\.email\) == LOWER\(@\S+\)\)$/' , $result ) ;
    }

    public function testFieldAltFromUrlRequestMirrorsBothSides(): void
    {
        $value  = [ 'op' => 'eq' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callField( 'email' , $value , $this->binds , [] , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/^\(LOWER\(doc\.email\) == LOWER\(@\S+\)\)$/' , $result ) ;
    }

    public function testFieldUrlAltOverridesDefinitionAlt(): void
    {
        $facet  = [ Facet::OP => FilterComparator::EQ , Facet::ALT => [ 'key' => 'upper' , 'val' => true ] ] ;
        $value  = [ 'val' => 'jean@x.com' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callField( 'email' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/^\(LOWER\(doc\.email\) == LOWER\(@\S+\)\)$/' , $result ) ;
        $this->assertStringNotContainsString( 'UPPER' , $result ) ;
    }

    public function testFieldAltKeyOnlyLeavesValueRaw(): void
    {
        $facet  = [ Facet::OP => FilterComparator::EQ , Facet::ALT => [ 'key' => 'lower' ] ] ;
        $result = $this->stub->callField( 'email' , 'JEAN@X.COM' , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/^\(LOWER\(doc\.email\) == @\S+\)$/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(@' , $result ) ;
    }

    public function testFieldNoAltIsUnchanged(): void
    {
        $result = $this->stub->callField( 'withStatus' , 'draft' , $this->binds , [] , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.withStatus =~ @\S+\)$/' , $result ) ;
    }

    // ========================================
    // EDGE
    // ========================================

    public function testEdgeAltFromDefinitionWrapsVertexFieldAndValue(): void
    {
        $facet  = [ AQL::EDGE => 'orgs_places' , AQL::FIELDS => 'name' , Facet::OP => 'eq' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callEdge( 'location' , 'paris' , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/FILTER LOWER\(doc_location\.name\) == LOWER\(@\S+\)/' , $result ) ;
    }

    public function testEdgeAltFromUrlRequest(): void
    {
        $facet  = [ AQL::EDGE => 'orgs_places' , AQL::FIELDS => 'name' , Facet::OP => 'eq' ] ;
        $value  = [ 'val' => 'paris' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callEdge( 'location' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/FILTER LOWER\(doc_location\.name\) == LOWER\(@\S+\)/' , $result ) ;
    }

    // ========================================
    // JOIN
    // ========================================

    public function testJoinAltFromDefinitionWrapsJoinedFieldAndValue(): void
    {
        $facet  = [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::KEY => '_key' , AQL::FIELDS => 'name' , Facet::OP => 'eq' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callJoin( 'author' , 'alice' , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/FILTER doc_author\._key == doc\.authorId && LOWER\(doc_author\.name\) == LOWER\(@\S+\)/' , $result ) ;
    }

    // ========================================
    // COMPLEX (facet-wide Facet::ALT on every sub-field)
    // ========================================

    public function testEdgeComplexAltWrapsEverySubField(): void
    {
        $facet  = [ AQL::EDGE => 'has_numbers' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callEdgeComplex( 'numbers' , [ 'value' => '459' , 'kind' => 'EAR' ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/LOWER\(doc_numbers\.value\) == LOWER\(@\S+\) && LOWER\(doc_numbers\.kind\) == LOWER\(@\S+\)/' , $result ) ;
    }

    public function testJoinComplexAltWrapsConditionsButNotTheJoinKey(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callJoinComplex( 'comments' , [ 'status' => 'APPROVED' ] , $this->binds , $facet , AQL::DOC ) ;

        // the structural join key stays raw, only the field condition is wrapped.
        $this->assertMatchesRegularExpression( '/FILTER doc_comments\.postId == doc\._key && LOWER\(doc_comments\.status\) == LOWER\(@\S+\)/' , $result ) ;
    }

    public function testArrayComplexAltWrapsSubFieldAndValueAcrossAList(): void
    {
        $facet  = [ Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $result = $this->stub->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => [ 'PIG' , 'Cattle' ] ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertMatchesRegularExpression( '/LOWER\(doc_workshops\.breeding\.alternateName\) == LOWER\(@\S+\) \|\| LOWER\(doc_workshops\.breeding\.alternateName\) == LOWER\(@\S+\)/' , $result ) ;
    }

    public function testArrayComplexNoAltIsUnchanged(): void
    {
        $result = $this->stub->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'pig' ] , $this->binds , [] , AQL::DOC ) ;

        $this->assertStringContainsString( 'doc_workshops.breeding.alternateName == @' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER' , $result ) ;
    }
}
