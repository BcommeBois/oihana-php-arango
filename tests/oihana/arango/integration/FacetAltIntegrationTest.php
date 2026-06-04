<?php

namespace tests\oihana\arango\integration ;

use oihana\arango\clients\Database ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Facet ;
use oihana\arango\models\enums\filters\FilterComparator ;

use tests\oihana\arango\models\traits\aql\FacetTraitStub ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the value-side `alt` transformation on facets (FIELD, EDGE,
 * JOIN). Each facet FILTER fragment — built with `alt` coming either from the
 * model definition (`Facet::ALT`) or from the URL request object
 * (`{op,val,alt}`) — is embedded in a real query and executed against a seeded,
 * disposable ArangoDB database, proving the symmetric comparison filters live.
 *
 * @see FacetTraitStub The builder host shared with the unit suite.
 */
#[Group( 'integration' )]
class FacetAltIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_facet_alt_it' ;

    protected static function seed( Database $db ) :void
    {
        // --- FIELD : scalar property, mixed-case email --------------------
        $fdocs = $db->collection( 'fdocs' ) ;
        $fdocs->create() ;
        $fdocs->insert( [ '_key' => 'f1' , 'email' => 'Jean@X.COM' , 'price' => 50  ] ) ;
        $fdocs->insert( [ '_key' => 'f2' , 'email' => 'jean@x.com' , 'price' => 150 ] ) ;
        $fdocs->insert( [ '_key' => 'f3' , 'email' => 'bob@x.com'  , 'price' => 250 ] ) ;

        // --- EDGE : (place)-[orgs_places]->(org), mixed-case place name ----
        $orgs = $db->collection( 'orgs' ) ;
        $orgs->create() ;
        foreach ( [ 'oA' , 'oB' , 'oC' ] as $k ) { $orgs->insert( [ '_key' => $k ] ) ; }

        $places = $db->collection( 'places' ) ;
        $places->create() ;
        $places->insert( [ '_key' => 'pA' , 'name' => 'Paris' ] ) ;
        $places->insert( [ '_key' => 'pB' , 'name' => 'PARIS' ] ) ;
        $places->insert( [ '_key' => 'pC' , 'name' => 'Lyon'  ] ) ;

        $edges = $db->collection( 'orgs_places' ) ;
        $edges->create( [ 'type' => 3 ] ) ;
        $edges->insert( [ '_from' => 'places/pA' , '_to' => 'orgs/oA' ] ) ;
        $edges->insert( [ '_from' => 'places/pB' , '_to' => 'orgs/oB' ] ) ;
        $edges->insert( [ '_from' => 'places/pC' , '_to' => 'orgs/oC' ] ) ;

        // --- JOIN : posts joined to authors on authorId == author._key -----
        $authors = $db->collection( 'authors' ) ;
        $authors->create() ;
        $authors->insert( [ '_key' => 'a1' , 'name' => 'Alice' ] ) ;
        $authors->insert( [ '_key' => 'a2' , 'name' => 'ALICE' ] ) ;
        $authors->insert( [ '_key' => 'a3' , 'name' => 'Bob'   ] ) ;

        $posts = $db->collection( 'posts' ) ;
        $posts->create() ;
        $posts->insert( [ '_key' => 'po1' , 'authorId' => 'a1' ] ) ;
        $posts->insert( [ '_key' => 'po2' , 'authorId' => 'a2' ] ) ;
        $posts->insert( [ '_key' => 'po3' , 'authorId' => 'a3' ] ) ;

        // --- JOIN_COMPLEX : comments referencing posts (comment.postId==post._key)
        $comments = $db->collection( 'comments' ) ;
        $comments->create() ;
        $comments->insert( [ '_key' => 'c1' , 'postId' => 'po1' , 'status' => 'Approved' ] ) ;
        $comments->insert( [ '_key' => 'c2' , 'postId' => 'po2' , 'status' => 'approved' ] ) ;
        $comments->insert( [ '_key' => 'c3' , 'postId' => 'po3' , 'status' => 'Spam'     ] ) ;

        // --- EDGE_COMPLEX : (number)-[has_numbers]->(livestock), mixed case -----
        $live = $db->collection( 'livestocks' ) ;
        $live->create() ;
        foreach ( [ 'L1' , 'L2' , 'L3' ] as $k ) { $live->insert( [ '_key' => $k ] ) ; }

        $numbers = $db->collection( 'numbers' ) ;
        $numbers->create() ;
        $numbers->insert( [ '_key' => 'n1' , 'value' => 'EAR459' , 'kind' => 'EAR' ] ) ;
        $numbers->insert( [ '_key' => 'n2' , 'value' => 'ear459' , 'kind' => 'ear' ] ) ;
        $numbers->insert( [ '_key' => 'n3' , 'value' => 'TAG999' , 'kind' => 'tag' ] ) ;

        $hasNumbers = $db->collection( 'has_numbers' ) ;
        $hasNumbers->create( [ 'type' => 3 ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n1' , '_to' => 'livestocks/L1' ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n2' , '_to' => 'livestocks/L2' ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n3' , '_to' => 'livestocks/L3' ] ) ;

        // --- IN : array-membership facet with mixed-case elements --------------
        $arts = $db->collection( 'arts' ) ;
        $arts->create() ;
        $arts->insert( [ '_key' => 'ar1' , 'tags' => [ 'Tech' , 'NEWS' ] ] ) ;
        $arts->insert( [ '_key' => 'ar2' , 'tags' => [ 'tech' ] ] ) ;
        $arts->insert( [ '_key' => 'ar3' , 'tags' => [ 'sport' ] ] ) ;

        // --- ARRAY_COMPLEX : embedded array with mixed-case sub-field ----------
        $things = $db->collection( 'things' ) ;
        $things->create() ;
        $things->insert( [ '_key' => 't1' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'PIG'    ] ] ] ] ) ;
        $things->insert( [ '_key' => 't2' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'pig'    ] ] ] ] ) ;
        $things->insert( [ '_key' => 't3' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'Cattle' ] ] ] ] ) ;
    }

    /**
     * Runs a facet FILTER fragment inside FOR doc IN <collection> and returns the
     * matched `_key`s (sorted).
     *
     * @param array<string,mixed> $binds
     *
     * @return list<string>
     */
    private function keys( string $collection , string $filter , array $binds ) :array
    {
        $aql    = 'FOR doc IN ' . $collection . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $cursor = self::$db->query( $aql , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    private function stub() :FacetTraitStub
    {
        return new FacetTraitStub() ;
    }

    // ---------------------------------------------------------------- FIELD

    public function testFieldAltFromDefinitionMatchesCaseInsensitively() :void
    {
        // alt frozen in the model definition ; the URL sends a plain value.
        $binds  = [] ;
        $facet  = [ Facet::OP => FilterComparator::EQ , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callField( 'email' , 'JEAN@X.COM' , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'f1' , 'f2' ] , $this->keys( 'fdocs' , $filter , $binds ) ) ;
    }

    public function testFieldBetweenMatchesInclusiveRange() :void
    {
        // 100 <= price <= 200 → only f2 (150). f1=50, f3=250 excluded.
        $binds  = [] ;
        $value  = [ 'op' => 'between' , 'min' => 100 , 'max' => 200 ] ;
        $filter = $this->stub()->callField( 'price' , $value , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f2' ] , $this->keys( 'fdocs' , $filter , $binds ) ) ;
    }

    public function testFieldAltFromUrlRequestMatchesCaseInsensitively() :void
    {
        // alt provided per request ; no alt in the definition.
        $binds  = [] ;
        $value  = [ 'op' => 'eq' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callField( 'email' , $value , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f1' , 'f2' ] , $this->keys( 'fdocs' , $filter , $binds ) ) ;
    }

    public function testFieldUrlAltOverridesDefinitionAlt() :void
    {
        // definition lowers ; request upper wins → UPPER(email) == UPPER(@v).
        $binds  = [] ;
        $facet  = [ Facet::OP => FilterComparator::EQ , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $value  = [ 'val' => 'jean@x.com' , 'alt' => [ 'key' => 'upper' , 'val' => true ] ] ;
        $filter = $this->stub()->callField( 'email' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'f1' , 'f2' ] , $this->keys( 'fdocs' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- EDGE

    public function testEdgeAltFromDefinitionMatchesCaseInsensitively() :void
    {
        $binds  = [] ;
        $facet  = [ AQL::EDGE => 'orgs_places' , AQL::FIELDS => 'name' , Facet::OP => 'eq' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callEdge( 'location' , 'paris' , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'oA' , 'oB' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- JOIN

    public function testJoinAltFromUrlRequestMatchesCaseInsensitively() :void
    {
        $binds  = [] ;
        $facet  = [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::KEY => '_key' , AQL::FIELDS => 'name' , Facet::OP => 'eq' ] ;
        $value  = [ 'val' => 'alice' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callJoin( 'author' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'po1' , 'po2' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- complex (Facet::ALT global)

    public function testEdgeComplexAltAppliesToEverySubField() :void
    {
        // both value AND kind compared case-insensitively on the same vertex.
        $binds  = [] ;
        $facet  = [ AQL::EDGE => 'has_numbers' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => 'ear459' , 'kind' => 'ear' ] , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'L1' , 'L2' ] , $this->keys( 'livestocks' , $filter , $binds ) ) ;
    }

    public function testJoinComplexAltAppliesToEverySubField() :void
    {
        $binds  = [] ;
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' , Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callJoinComplex( 'comments' , [ 'status' => 'approved' ] , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'po1' , 'po2' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testArrayComplexAltAppliesToSubField() :void
    {
        $binds  = [] ;
        $facet  = [ Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'pig' ] , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( 'things' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- IN (membership)

    public function testInAltFromDefinitionMatchesCaseInsensitively() :void
    {
        // mirror lower on both the requested values and doc.tags → ar1 (Tech/NEWS) + ar2 (tech).
        $binds  = [] ;
        $facet  = [ Facet::ALT => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callIn( 'tags' , [ 'TECH' , 'news' ] , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'ar1' , 'ar2' ] , $this->keys( 'arts' , $filter , $binds ) ) ;
    }

    public function testInAltFromUrlRequestAllInRequiresEveryValue() :void
    {
        $binds  = [] ;
        $value  = [ 'op' => 'all.in' , 'val' => [ 'TECH' , 'news' ] , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;
        $filter = $this->stub()->callIn( 'tags' , $value , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'ar1' ] , $this->keys( 'arts' , $filter , $binds ) ) ;
    }
}
