<?php

namespace tests\oihana\arango\integration ;

use oihana\arango\clients\Database ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Facet ;
use oihana\arango\models\enums\filters\FilterParam ;

use tests\oihana\arango\models\traits\aql\FacetTraitStub ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the facet builders: each facet FILTER fragment is
 * embedded in a real `FOR doc IN <collection> FILTER <fragment> RETURN doc._key`
 * query and executed against a seeded, disposable ArangoDB database. This
 * proves the generated AQL actually parses AND filters as intended — something
 * the unit suite (which only freezes the AQL string) cannot.
 *
 * @see FacetTraitStub The same builder host used by the unit suite.
 */
#[Group( 'integration' )]
class FacetIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_facets_it' ;

    private const string COLLECTION = 'things' ;

    private const int EDGE_TYPE = 3 ;

    protected static function seed( Database $db ) :void
    {
        // --- Facet::ARRAY_COMPLEX : embedded complex arrays -----------------
        // Each element of `workshops` carries a nested `breeding.alternateName`.
        $things = $db->collection( self::COLLECTION ) ;
        $things->create() ;
        $things->insert( [ '_key' => 't1' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'pig'    ] ] , [ 'breeding' => [ 'alternateName' => 'cattle' ] ] ] ] ) ;
        $things->insert( [ '_key' => 't2' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'sheep'  ] ] ] ] ) ;
        $things->insert( [ '_key' => 't3' , 'workshops' => [] ] ) ;

        // --- Facet::EDGE : INBOUND graph (place)-[orgs_places]->(org) --------
        $orgs = $db->collection( 'orgs' ) ;
        $orgs->create() ;
        foreach ( [ 'o1' , 'o2' , 'o3' , 'o4' ] as $k ) { $orgs->insert( [ '_key' => $k ] ) ; }

        $places = $db->collection( 'places' ) ;
        $places->create() ;
        $places->insert( [ '_key' => '1234' , 'name' => 'Paris'  ] ) ;
        $places->insert( [ '_key' => '5678' , 'name' => 'Lyon'   ] ) ;
        $places->insert( [ '_key' => 'pB'   , 'name' => 'Berlin' ] ) ;

        $edges = $db->collection( 'orgs_places' ) ;
        $edges->create( [ 'type' => self::EDGE_TYPE ] ) ;
        // o1→1234 ; o2→pB ; o3→1234 & 5678 ; o4→5678
        $edges->insert( [ '_from' => 'places/1234' , '_to' => 'orgs/o1' ] ) ;
        $edges->insert( [ '_from' => 'places/pB'   , '_to' => 'orgs/o2' ] ) ;
        $edges->insert( [ '_from' => 'places/1234' , '_to' => 'orgs/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/5678' , '_to' => 'orgs/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/5678' , '_to' => 'orgs/o4' ] ) ;

        // --- Facet::EDGE_COMPLEX : multi-field vertices ---------------------
        // (number)-[livestocks_has_numbers]->(livestock), each number carries
        // value + kind so several fields can be matched on the same vertex.
        $live = $db->collection( 'livestocks' ) ;
        $live->create() ;
        foreach ( [ 'L1' , 'L2' , 'L3' , 'L4' ] as $k ) { $live->insert( [ '_key' => $k ] ) ; }

        $numbers = $db->collection( 'numbers' ) ;
        $numbers->create() ;
        $numbers->insert( [ '_key' => 'n1' , 'value' => '459' , 'kind' => 'ear' ] ) ;
        $numbers->insert( [ '_key' => 'n2' , 'value' => '459' , 'kind' => 'tag' ] ) ;
        $numbers->insert( [ '_key' => 'n3' , 'value' => '999' , 'kind' => 'ear' ] ) ;
        $numbers->insert( [ '_key' => 'n4' , 'value' => '460' , 'kind' => 'ear' ] ) ;

        $hasNumbers = $db->collection( 'livestocks_has_numbers' ) ;
        $hasNumbers->create( [ 'type' => self::EDGE_TYPE ] ) ;
        // L1:459/ear  L2:459/tag  L3:999/ear  L4:460/ear
        $hasNumbers->insert( [ '_from' => 'numbers/n1' , '_to' => 'livestocks/L1' ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n2' , '_to' => 'livestocks/L2' ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n3' , '_to' => 'livestocks/L3' ] ) ;
        $hasNumbers->insert( [ '_from' => 'numbers/n4' , '_to' => 'livestocks/L4' ] ) ;

        // --- Facet::IN / LIST / LIST_FIELD : array membership ---------------
        $articles = $db->collection( 'articles' ) ;
        $articles->create() ;
        $articles->insert( [ '_key' => 'a1' , 'keywords' => [ 'cuisine' , 'jardin' ] ] ) ;
        $articles->insert( [ '_key' => 'a2' , 'keywords' => [ 'cuisine' ] ] ) ;
        $articles->insert( [ '_key' => 'a3' , 'keywords' => [ 'jardin' , 'sport' ] ] ) ;
        $articles->insert( [ '_key' => 'a4' , 'keywords' => [] ] ) ;

        // --- Facet::JOIN_COMPLEX : key-join (no edge) -----------------------
        // posts <- comments (comment.postId == post._key) ; posts.tagIds[] -> tags._key
        $posts = $db->collection( 'posts' ) ;
        $posts->create() ;
        $posts->insert( [ '_key' => 'p1' , 'tagIds' => [ 'php' ] ] ) ;
        $posts->insert( [ '_key' => 'p2' , 'tagIds' => [ 'db' ] ] ) ;
        $posts->insert( [ '_key' => 'p3' , 'tagIds' => [ 'php' , 'db' ] ] ) ;

        $comments = $db->collection( 'comments' ) ;
        $comments->create() ;
        $comments->insert( [ '_key' => 'c1' , 'postId' => 'p1' , 'status' => 'approved' , 'score' => 5 ] ) ;
        $comments->insert( [ '_key' => 'c2' , 'postId' => 'p1' , 'status' => 'spam'     , 'score' => 1 ] ) ;
        $comments->insert( [ '_key' => 'c3' , 'postId' => 'p2' , 'status' => 'approved' , 'score' => 2 ] ) ;
        $comments->insert( [ '_key' => 'c4' , 'postId' => 'p3' , 'status' => 'pending'  , 'score' => 3 ] ) ;

        $tags = $db->collection( 'tags' ) ;
        $tags->create() ;
        $tags->insert( [ '_key' => 'php' , 'label' => 'PHP'      ] ) ;
        $tags->insert( [ '_key' => 'db'  , 'label' => 'Database' ] ) ;

        // --- Facet::EDGE_AGGREGATE : (balanceSheet)-[balance_edges]->(org) --
        // Numeric revenue per linked balance sheet, aggregated per organisation.
        $balanceSheets = $db->collection( 'balanceSheets' ) ;
        $balanceSheets->create() ;
        $balanceSheets->insert( [ '_key' => 'bs1' , 'revenue' => 1200000 ] ) ;
        $balanceSheets->insert( [ '_key' => 'bs2' , 'revenue' => 900000  ] ) ;
        $balanceSheets->insert( [ '_key' => 'bs3' , 'revenue' => 200000  ] ) ;

        $balanceEdges = $db->collection( 'balance_edges' ) ;
        $balanceEdges->create( [ 'type' => self::EDGE_TYPE ] ) ;
        // o1: bs1+bs2 (avg 1.05M, sum 2.1M) ; o2: bs3 (200k) ; o3/o4: none
        $balanceEdges->insert( [ '_from' => 'balanceSheets/bs1' , '_to' => 'orgs/o1' ] ) ;
        $balanceEdges->insert( [ '_from' => 'balanceSheets/bs2' , '_to' => 'orgs/o1' ] ) ;
        $balanceEdges->insert( [ '_from' => 'balanceSheets/bs3' , '_to' => 'orgs/o2' ] ) ;

        // --- Facet::FIELD : scalar property comparison ----------------------
        $fieldDocs = $db->collection( 'fielddocs' ) ;
        $fieldDocs->create() ;
        $fieldDocs->insert( [ '_key' => 'f1' , 'withStatus' => 'draft'     , 'price' => 50  , 'name' => 'John'   ] ) ;
        $fieldDocs->insert( [ '_key' => 'f2' , 'withStatus' => 'review'    , 'price' => 150 , 'name' => 'Joanna' ] ) ;
        $fieldDocs->insert( [ '_key' => 'f3' , 'withStatus' => 'predraft'  , 'price' => 200 , 'name' => 'Bob'    ] ) ; // contains "draft"
        $fieldDocs->insert( [ '_key' => 'f4' , 'withStatus' => 'published' , 'price' => 100 , 'name' => 'Alice'  ] ) ;
    }

    /**
     * Builds the facet FILTER fragment with the real builder, runs it inside a
     * full FOR..FILTER..RETURN query over `$collection`, and returns the matched
     * `_key`s (sorted).
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
        $stub = new FacetTraitStub() ;
        $stub->collection = self::COLLECTION ;
        return $stub ;
    }

    // ---------------------------------------------------------------- ARRAY_COMPLEX

    public function testArrayComplexScalarMatchesContainingDocument() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'pig' ] , $binds ) ;
        $this->assertSame( [ 't1' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexMultipleValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => [ 'pig' , 'sheep' ] ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexNegativeIsExistentialNotEqual() :void
    {
        // `-pig` => keep docs having AT LEAST ONE element whose alternateName != pig.
        // t1 (pig+cattle) qualifies via cattle; t2 (sheep) qualifies; t3 (empty) does not.
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => '-pig' ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexEmptyArrayPropertyNeverMatches() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'anything' ] , $binds ) ;
        $this->assertNotContains( 't3' , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- EDGE

    public function testEdgeSingleValueMatchesLinkedDocuments() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , 1234 , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o1' , 'o3' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeMultipleValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '1234,5678' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o1' , 'o3' , 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeNegativeValueExcludesLinkedDocuments() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o2' , 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeMixedPositiveAndNegative() :void
    {
        // linked to 5678 AND not linked to 1234 => only o4
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '5678,-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeMultiFieldLikeIsTheThesaurusReplacement() :void
    {
        // org linked to a place whose _key OR name LIKE 'Paris' => place 1234 (Paris) -> o1, o3
        $binds = [] ;
        $facet  = [ AQL::EDGE => 'orgs_places' , AQL::FIELDS => '_key,name' , Facet::OP => 'like' ] ;
        $filter = $this->stub()->callEdge( 'location' , 'Paris' , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'o1' , 'o3' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- EDGE_COMPLEX

    public function testEdgeComplexSingleFieldMatches() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '459' ] , $binds , [ AQL::EDGE => 'livestocks_has_numbers' ] , AQL::DOC ) ;
        $this->assertSame( [ 'L1' , 'L2' ] , $this->keys( 'livestocks' , $filter , $binds ) ) ;
    }

    public function testEdgeComplexMultipleFieldsAndedOnSameVertex() :void
    {
        // value 459 AND kind ear, on the same number => only L1
        $binds = [] ;
        $filter = $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '459' , 'kind' => 'ear' ] , $binds , [ AQL::EDGE => 'livestocks_has_numbers' ] , AQL::DOC ) ;
        $this->assertSame( [ 'L1' ] , $this->keys( 'livestocks' , $filter , $binds ) ) ;
    }

    public function testEdgeComplexArrayValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => [ '459' , '460' ] ] , $binds , [ AQL::EDGE => 'livestocks_has_numbers' ] , AQL::DOC ) ;
        $this->assertSame( [ 'L1' , 'L2' , 'L4' ] , $this->keys( 'livestocks' , $filter , $binds ) ) ;
    }

    public function testEdgeComplexNegationIsInlineNotEqual() :void
    {
        // a linked number whose value != 459 AND kind == ear => L3 (999/ear), L4 (460/ear)
        $binds = [] ;
        $filter = $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '-459' , 'kind' => 'ear' ] , $binds , [ AQL::EDGE => 'livestocks_has_numbers' ] , AQL::DOC ) ;
        $this->assertSame( [ 'L3' , 'L4' ] , $this->keys( 'livestocks' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- IN (array membership)

    public function testInDefaultAnyMatchesAtLeastOne() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callIn( 'keywords' , 'cuisine,jardin' , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'a1' , 'a2' , 'a3' ] , $this->keys( 'articles' , $filter , $binds ) ) ;
    }

    public function testInAllRequiresEveryValue() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callIn( 'keywords' , [ FilterParam::OP => 'all.in' , FilterParam::VAL => 'cuisine,jardin' ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'a1' ] , $this->keys( 'articles' , $filter , $binds ) ) ;
    }

    public function testInNoneExcludesMatching() :void
    {
        // documents having NONE of {cuisine} => a3 (jardin,sport) and a4 (empty)
        $binds = [] ;
        $filter = $this->stub()->callIn( 'keywords' , [ FilterParam::OP => 'none.in' , FilterParam::VAL => [ 'cuisine' ] ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'a3' , 'a4' ] , $this->keys( 'articles' , $filter , $binds ) ) ;
    }

    public function testListFieldAliasMatchesAny() :void
    {
        // the historical LIST_FIELD type is preserved as an alias (op any.in)
        $binds = [] ;
        $filter = $this->stub()->callListField( 'keywords' , 'sport' , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'a3' ] , $this->keys( 'articles' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- FIELD (scalar comparison)

    public function testFieldDefaultMatchIsLooseRegex() :void
    {
        // default `=~` is a regex match, so "draft" also catches "predraft" (f3)
        $binds = [] ;
        $filter = $this->stub()->callField( 'withStatus' , 'draft' , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f1' , 'f3' ] , $this->keys( 'fielddocs' , $filter , $binds ) ) ;
    }

    public function testFieldOpEqIsExact() :void
    {
        // op=eq is a strict equality, so only the real "draft" (f1) matches
        $binds = [] ;
        $filter = $this->stub()->callField( 'withStatus' , [ FilterParam::OP => 'eq' , FilterParam::VAL => 'draft' ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f1' ] , $this->keys( 'fielddocs' , $filter , $binds ) ) ;
    }

    public function testFieldOpGeOnNumber() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callField( 'price' , [ FilterParam::OP => 'ge' , FilterParam::VAL => 150 ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f2' , 'f3' ] , $this->keys( 'fielddocs' , $filter , $binds ) ) ;
    }

    public function testFieldOpEqNegationExcludes() :void
    {
        // op=eq + "-draft" => doc.withStatus != draft => everything but f1
        $binds = [] ;
        $filter = $this->stub()->callField( 'withStatus' , [ FilterParam::OP => 'eq' , FilterParam::VAL => '-draft' ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f2' , 'f3' , 'f4' ] , $this->keys( 'fielddocs' , $filter , $binds ) ) ;
    }

    public function testFieldOpLikeMatchesPattern() :void
    {
        // op=like with a wildcard pattern => names starting with "Jo" (f1 John, f2 Joanna)
        $binds = [] ;
        $filter = $this->stub()->callField( 'name' , [ FilterParam::OP => 'like' , FilterParam::VAL => 'Jo%' ] , $binds , [] , AQL::DOC ) ;
        $this->assertSame( [ 'f1' , 'f2' ] , $this->keys( 'fielddocs' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- JOIN_COMPLEX (key-join)

    private const array JOIN_COMMENTS = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ;

    public function testJoinComplexReverseMatch() :void
    {
        // posts having an approved comment (comment.postId == post._key)
        $binds = [] ;
        $filter = $this->stub()->callJoinComplex( 'comments' , [ 'status' => 'approved' ] , $binds , self::JOIN_COMMENTS , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinComplexMultiFieldAnd() :void
    {
        // an approved comment with score 5 => only p1 (c1)
        $binds = [] ;
        $filter = $this->stub()->callJoinComplex( 'comments' , [ 'status' => 'approved' , 'score' => 5 ] , $binds , self::JOIN_COMMENTS , AQL::DOC ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinComplexNegationIsExistential() :void
    {
        // a comment whose status != spam => p1 (c1 approved), p2 (c3), p3 (c4)
        $binds = [] ;
        $filter = $this->stub()->callJoinComplex( 'comments' , [ 'status' => '-spam' ] , $binds , self::JOIN_COMMENTS , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p2' , 'p3' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinComplexArrayVariant() :void
    {
        // posts whose tagIds[] contains a tag labelled PHP => p1, p3
        $binds = [] ;
        $facet  = [ AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' ] ;
        $filter = $this->stub()->callJoinComplex( 'tags' , [ 'label' => 'PHP' ] , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p3' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- JOIN (simple key-join)

    public function testJoinSimpleMatchesJoinedField() :void
    {
        // posts having an approved comment (comment.postId == post._key, comment.status == approved)
        $binds = [] ;
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' , AQL::FIELDS => 'status' ] ;
        $filter = $this->stub()->callJoin( 'comments' , 'approved' , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinSimpleArrayVariant() :void
    {
        // posts whose tagIds[] contains a tag labelled PHP => p1, p3
        $binds = [] ;
        $facet  = [ AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' , AQL::FIELDS => 'label' ] ;
        $filter = $this->stub()->callJoin( 'tags' , 'PHP' , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p3' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- JOIN_AGGREGATE (key-join)

    public function testJoinAggregateAverageScoreThreshold() :void
    {
        // avg comment score per post >= 3 : p1 (5,1 => 3), p3 (3) ; p2 (2) excluded.
        $binds  = [] ;
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ;
        $value  = [ 'agg' => 'avg' , 'field' => 'score' , 'op' => 'ge' , 'val' => 3 ] ;
        $filter = $this->stub()->callJoinAggregate( 'comments' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p1' , 'p3' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinAggregateCountThreshold() :void
    {
        // posts with at least 2 comments : only p1 (c1, c2).
        $binds  = [] ;
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ;
        $value  = [ 'agg' => 'count' , 'op' => 'ge' , 'val' => 2 ] ;
        $filter = $this->stub()->callJoinAggregate( 'comments' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    public function testJoinAggregateMinScoreThreshold() :void
    {
        // worst comment score per post >= 2 : p2 (2), p3 (3) ; p1 (min 1) excluded.
        $binds  = [] ;
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'postId' , Facet::PROPERTY => '_key' ] ;
        $value  = [ 'agg' => 'min' , 'field' => 'score' , 'op' => 'ge' , 'val' => 2 ] ;
        $filter = $this->stub()->callJoinAggregate( 'comments' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'p2' , 'p3' ] , $this->keys( 'posts' , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- EDGE_AGGREGATE (inbound graph)

    public function testEdgeAggregateAverageRevenueThreshold() :void
    {
        // avg revenue of linked balance sheets >= 1M : o1 (1.05M). o2 (200k) & o3/o4 (none) excluded.
        $binds  = [] ;
        $facet  = [ AQL::EDGE => 'balance_edges' ] ;
        $value  = [ 'agg' => 'avg' , 'field' => 'revenue' , 'op' => 'ge' , 'val' => 1000000 ] ;
        $filter = $this->stub()->callEdgeAggregate( 'balanceSheets' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'o1' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeAggregateSumRevenueThreshold() :void
    {
        // cumulative revenue >= 2M : o1 (2.1M) only.
        $binds  = [] ;
        $facet  = [ AQL::EDGE => 'balance_edges' ] ;
        $value  = [ 'agg' => 'sum' , 'field' => 'revenue' , 'op' => 'ge' , 'val' => 2000000 ] ;
        $filter = $this->stub()->callEdgeAggregate( 'balanceSheets' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'o1' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeAggregateMinRevenueBelowThreshold() :void
    {
        // floor revenue < 500k : o2 (200k). o1 (min 900k) and o3/o4 (no sheets) excluded.
        $binds  = [] ;
        $facet  = [ AQL::EDGE => 'balance_edges' ] ;
        $value  = [ 'agg' => 'min' , 'field' => 'revenue' , 'op' => 'lt' , 'val' => 500000 ] ;
        $filter = $this->stub()->callEdgeAggregate( 'balanceSheets' , $value , $binds , $facet , AQL::DOC ) ;
        $this->assertSame( [ 'o2' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }
}
