<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\db\enums\AQL as DbAQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\aql\FieldsTrait;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Host exposing {@see FieldsTrait} together with a (possibly null) container,
 * so the edges/joins and prepared-queryFields branches of returnFields() — the
 * ones reaching into buildJoinVariables / buildEdgesVariables / aqlFields — can
 * be exercised. The container is unused here because the relation models are
 * injected directly as `AQL::MODEL` instances (getDocuments/getEdges accept an
 * instance and short-circuit the container lookup).
 */
class FieldsTraitTier2Host
{
    use FieldsTrait ;
}

/**
 * Tier-2 coverage for {@see FieldsTrait::returnFields()}: the joins/edges
 * relation projection (`*` branch) and the prepared-queryFields branch.
 */
final class FieldsTraitTier2Test extends TestCase
{
    private function host() :FieldsTraitTier2Host
    {
        $host = new FieldsTraitTier2Host() ;
        $host->container = new Container() ;
        return $host ;
    }

    private function documents( string $collection ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container , [ DbAQL::COLLECTION => $collection , DbAQL::LAZY => false ] ) ;
    }

    public function testReturnFieldsWithJoinsProjectsRelations() :void
    {
        $variables = [] ;

        $result = $this->host()->returnFields
        (
            [ Arango::JOINS => [ 'roles' => [ Arango::MODEL => $this->documents( 'roles' ) ] ] ] ,
            $variables ,
        ) ;

        $this->assertStringContainsString( 'RETURN' , $result ) ;
        $this->assertStringContainsString( 'roles' , $result ) ;
        $this->assertNotEmpty( $variables ) ;
    }

    public function testReturnFieldsWithEdgesProjectsRelations() :void
    {
        $variables = [] ;

        $edge = new MockEdges( 'permissions_edges' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'permissions' ) ;

        $result = $this->host()->returnFields
        (
            [ Arango::EDGES => [ 'permissions' => [ Arango::MODEL => $edge ] ] ] ,
            $variables ,
        ) ;

        $this->assertStringContainsString( 'RETURN' , $result ) ;
        $this->assertStringContainsString( 'permissions' , $result ) ;
        $this->assertNotEmpty( $variables ) ;
    }

    public function testReturnFieldsWithPreparedQueryFields() :void
    {
        $variables = [] ;

        $result = $this->host()->returnFields
        (
            [ Arango::QUERY_FIELDS => [ 'name' => Filter::DEFAULT ] ] ,
            $variables ,
        ) ;

        $this->assertSame( 'RETURN {name:doc.name}' , $result ) ;
    }

    /**
     * Regression: projecting a `Filter::EDGES_COUNT` field through a vertex traversal
     * (DOC_REF = 'vertex') must anchor the count `LET` sub-query on the outer loop
     * variable (`vertex`), not on an unbound `doc_vertex` alias.
     */
    public function testEdgesCountProjectedThroughTraversalAnchorsOnOuterLoopVariable() :void
    {
        $variables = [] ;

        $edge = new MockEdges( 'user_has_roles' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'roles' ) ;

        $this->host()->returnFields
        (
            [
                Arango::DOC_REF      => 'vertex' ,
                Arango::QUERY_FIELDS => [ 'rolesCount' => [ Field::FILTER => Filter::EDGES_COUNT ] ] ,
                Arango::EDGES        => [ 'rolesCount' => [ Arango::MODEL => $edge ] ] ,
            ] ,
            $variables ,
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringContainsString( 'IN OUTBOUND vertex user_has_roles' , $variables[ 0 ] ) ;
        $this->assertStringNotContainsString( 'doc_vertex' , $variables[ 0 ] ) ;
    }

    /**
     * Regression: same anchoring guarantee for a `Filter::EDGES` sub-traversal projected
     * through a vertex traversal — the inner `FOR … IN OUTBOUND vertex` starts from the
     * outer loop variable, never an unbound `doc_vertex`.
     */
    public function testEdgesProjectedThroughTraversalAnchorsOnOuterLoopVariable() :void
    {
        $variables = [] ;

        $edge = new MockEdges( 'permissions_edges' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'permissions' ) ;

        $this->host()->returnFields
        (
            [
                Arango::DOC_REF      => 'vertex' ,
                Arango::QUERY_FIELDS => [ 'permissions' => [ Field::FILTER => Filter::EDGES ] ] ,
                Arango::EDGES        => [ 'permissions' => [ Arango::MODEL => $edge ] ] ,
            ] ,
            $variables ,
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringContainsString( 'OUTBOUND vertex permissions_edges' , $variables[ 0 ] ) ;
        $this->assertStringNotContainsString( 'doc_vertex' , $variables[ 0 ] ) ;
    }

    /**
     * Regression: a `Filter::JOINS` (array join) projected through a vertex traversal
     * correlates its FILTER on the outer loop variable (`vertex.<field>`), not on an
     * unbound `doc_vertex` alias. The `returnFields()` fix covers joins, not only edges.
     */
    public function testJoinsProjectedThroughTraversalAnchorsOnOuterLoopVariable() :void
    {
        $variables = [] ;

        $this->host()->returnFields
        (
            [
                Arango::DOC_REF      => 'vertex' ,
                Arango::QUERY_FIELDS => [ 'roles' => [ Field::FILTER => Filter::JOINS ] ] ,
                Arango::JOINS        => [ 'roles' => [ Arango::MODEL => $this->documents( 'roles' ) ] ] ,
            ] ,
            $variables ,
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringContainsString( 'vertex.roles' , $variables[ 0 ] ) ;
        $this->assertStringNotContainsString( 'doc_vertex' , $variables[ 0 ] ) ;
    }

    /**
     * Regression: a `Filter::MAP` sub-array projection through a vertex traversal sources
     * its inner `FOR … IN vertex.<field>` from the outer loop variable, never `doc_vertex`.
     * The MAP expression is inline in the RETURN projection (not a LET), so it is asserted
     * on the returned string.
     */
    public function testMapProjectedThroughTraversalAnchorsOnOuterLoopVariable() :void
    {
        $variables = [] ;

        $result = $this->host()->returnFields
        (
            [
                Arango::DOC_REF      => 'vertex' ,
                Arango::QUERY_FIELDS =>
                [
                    'addresses' =>
                    [
                        Field::FILTER => Filter::MAP ,
                        Field::FIELDS => [ 'street' => Filter::DEFAULT ] ,
                    ] ,
                ] ,
            ] ,
            $variables ,
        ) ;

        $this->assertStringContainsString( 'vertex.addresses' , $result ) ;
        $this->assertStringNotContainsString( 'doc_vertex' , $result ) ;
    }

    // ---------------------------------------------------------------- nested Field::SKINS (deep skin filtering)

    /**
     * End-to-end : a `Field::SKINS` marker on a DOCUMENT sub-field varies the
     * generated AQL with the requested skin — included when the skin matches,
     * absent otherwise, and everything passes when no skin is requested.
     */
    public function testNestedSkinsFilterDocumentProjectionEndToEnd() :void
    {
        $init =
        [
            Arango::QUERY_FIELDS =>
            [
                'name' => Filter::DEFAULT ,
                'addr' =>
                [
                    Field::FILTER => Filter::DOCUMENT ,
                    Field::FIELDS =>
                    [
                        'street' => Filter::DEFAULT ,
                        'zip'    => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                    ] ,
                ] ,
            ] ,
        ] ;

        $variables = [] ;

        $this->assertSame
        (
            'RETURN {name:doc.name, addr:{street:doc.addr.street, zip:doc.addr.zip}}' ,
            $this->host()->returnFields( $init + [ Arango::SKIN => 'full' ] , $variables ) ,
        ) ;

        $this->assertSame
        (
            'RETURN {name:doc.name, addr:{street:doc.addr.street}}' ,
            $this->host()->returnFields( $init + [ Arango::SKIN => 'main' ] , $variables ) ,
        ) ;

        $this->assertSame
        (
            'RETURN {name:doc.name, addr:{street:doc.addr.street, zip:doc.addr.zip}}' ,
            $this->host()->returnFields( $init , $variables ) ,
        ) ;
    }

    /**
     * End-to-end : when the skin filters out a MAP sub-field carrying an edge
     * marker, the matching `LET` sub-traversal is not emitted at all — the edge
     * collection never appears in the generated AQL.
     */
    public function testSkinFilteredNestedEdgeMarkerDropsItsLetVariable() :void
    {
        $edge = new MockEdges( 'offer_has_sellers' ) ;
        $edge->from = $this->documents( 'offers' ) ;
        $edge->to   = $this->documents( 'sellers' ) ;

        $init =
        [
            Arango::QUERY_FIELDS =>
            [
                'offers' =>
                [
                    Field::FILTER => Filter::MAP ,
                    Field::FIELDS =>
                    [
                        'price'   => Filter::DEFAULT ,
                        'sellers' => [ Field::FILTER => Filter::EDGES , Field::SKINS => [ 'full' ] ] ,
                    ] ,
                    Field::EDGES => [ 'sellers' => [ Arango::MODEL => $edge ] ] ,
                ] ,
            ] ,
        ] ;

        $variables = [] ;
        $full      = $this->host()->returnFields( $init + [ Arango::SKIN => 'full' ] , $variables ) ;

        $this->assertStringContainsString( 'offer_has_sellers' , $full ) ;
        $this->assertStringContainsString( 'sellers' , $full ) ;

        $variables = [] ;
        $main      = $this->host()->returnFields( $init + [ Arango::SKIN => 'main' ] , $variables ) ;

        $this->assertStringNotContainsString( 'offer_has_sellers' , $main ) ;
        $this->assertStringNotContainsString( 'sellers' , $main ) ;
    }

    /**
     * End-to-end : a structural parent whose declared sub-fields are ALL removed
     * by the skin disappears from the projection — no raw-document fallback for
     * DOCUMENT, no exception for WRAP.
     */
    public function testParentDroppedEndToEndWhenTheSkinEmptiesItsSubFields() :void
    {
        foreach ( [ Filter::MAP , Filter::DOCUMENT , Filter::WRAP ] as $filter )
        {
            $variables = [] ;

            $result = $this->host()->returnFields
            (
                [
                    Arango::SKIN         => 'main' ,
                    Arango::QUERY_FIELDS =>
                    [
                        'name'    => Filter::DEFAULT ,
                        'pricing' =>
                        [
                            Field::FILTER => $filter ,
                            Field::FIELDS =>
                            [
                                'internalCost' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                            ] ,
                        ] ,
                    ] ,
                ] ,
                $variables ,
            ) ;

            $this->assertSame( 'RETURN {name:doc.name}' , $result , 'filter: ' . $filter ) ;
        }
    }

    /**
     * End-to-end : Field::SKINS (view) and Field::REQUIRES (security) cohabit on
     * the same nested sub-field. With a matching skin, the permission gating
     * still decides — granted projects the field, denied drops it.
     */
    public function testNestedSkinsAndRequiresCohabitOnTheSameSubField() :void
    {
        $init =
        [
            Arango::SKIN         => 'full' ,
            Arango::QUERY_FIELDS =>
            [
                'addr' =>
                [
                    Field::FILTER => Filter::DOCUMENT ,
                    Field::FIELDS =>
                    [
                        'street' => Filter::DEFAULT ,
                        'zip'    =>
                        [
                            Field::FILTER   => Filter::DEFAULT ,
                            Field::SKINS    => [ 'full' ] ,
                            Field::REQUIRES => 'addr.zip:read' ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] ;

        $variables = [] ;

        $granted = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => true ] , $variables ) ;
        $this->assertStringContainsString( 'zip:doc.addr.zip' , $granted ) ;

        $denied = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => false ] , $variables ) ;
        $this->assertStringNotContainsString( 'zip' , $denied ) ;
        $this->assertStringContainsString( 'street:doc.addr.street' , $denied ) ;
    }

    // ---------------------------------------------------------------- definition-level AQL::REQUIRES

    /**
     * Whole-document branch (`*`): a join/edge definition declaring a denied
     * `AQL::REQUIRES` is dropped from BOTH sides — no `LET` is emitted and the
     * RETURN merge does not reference it (no unbound variable).
     */
    public function testStarBranchDropsDeniedDefinitionsFromBothSides() :void
    {
        $edge = new MockEdges( 'user_has_roles' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'roles' ) ;

        $init =
        [
            Arango::JOINS => [ 'team'  => [ Arango::MODEL => $this->documents( 'teams' ) ] ] ,
            Arango::EDGES => [ 'roles' => [ Arango::MODEL => $edge , DbAQL::REQUIRES => 'users.roles:list' ] ] ,
        ] ;

        $variables = [] ;
        $denied    = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => false ] , $variables ) ;

        $this->assertStringNotContainsString( 'roles' , $denied ) ;             // not referenced in the merge
        $this->assertStringContainsString( 'team' , $denied ) ;                 // ungated join untouched
        $this->assertCount( 1 , $variables ) ;                                  // only the team LET
        $this->assertStringNotContainsString( 'user_has_roles' , $variables[ 0 ] ) ;

        $variables = [] ;
        $granted   = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => true ] , $variables ) ;

        $this->assertStringContainsString( 'roles' , $granted ) ;
        $this->assertCount( 2 , $variables ) ;
    }

    /**
     * Whole-document branch (`*`): a string alias entry follows its target's
     * authorization — a denied target drops the alias too (both would otherwise
     * emit a dangling reference in the RETURN merge).
     */
    public function testStarBranchAliasFollowsItsTargetAuthorization() :void
    {
        $init =
        [
            Arango::JOINS =>
            [
                'alias' => 'team' ,
                'team'  => [ Arango::MODEL => $this->documents( 'teams' ) , DbAQL::REQUIRES => 'teams:read' ] ,
            ] ,
        ] ;

        $variables = [] ;
        $denied    = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => false ] , $variables ) ;

        $this->assertSame( 'RETURN doc' , $denied ) ; // no relation survives
        $this->assertSame( [] , $variables ) ;

        $variables = [] ;
        $granted   = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => true ] , $variables ) ;

        $this->assertStringContainsString( 'alias' , $granted ) ;
        $this->assertCount( 2 , $variables ) ; // the alias LET and the target LET
    }

    /**
     * Prepared branch: a relation marker WITHOUT its own `Field::REQUIRES`,
     * whose definition declares a denied `AQL::REQUIRES`, disappears from both
     * the projection and the `LET` list — the definition-level gate documented
     * in the wiki (permission-gated edges and joins).
     */
    public function testPreparedBranchDropsMarkersOfDeniedDefinitions() :void
    {
        $edge = new MockEdges( 'user_has_roles' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'roles' ) ;

        $init =
        [
            Arango::QUERY_FIELDS => [ 'name' => Filter::DEFAULT , 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            Arango::EDGES        => [ 'roles' => [ Arango::MODEL => $edge , DbAQL::REQUIRES => 'users.roles:list' ] ] ,
        ] ;

        $variables = [] ;
        $denied    = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => false ] , $variables ) ;

        $this->assertSame( 'RETURN {name:doc.name}' , $denied ) ;
        $this->assertSame( [] , $variables ) ;

        $variables = [] ;
        $granted   = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => true ] , $variables ) ;

        $this->assertStringContainsString( 'roles:' , $granted ) ;
        $this->assertCount( 1 , $variables ) ;
    }

    /**
     * Nested MAP: a sub-field edge marker whose nested definition declares a
     * denied `AQL::REQUIRES` is dropped from the generated sub-query — no inner
     * `LET`, no projected key, no trace of the edge collection.
     */
    public function testNestedMapDropsMarkersOfDeniedDefinitions() :void
    {
        $edge = new MockEdges( 'offer_has_sellers' ) ;
        $edge->from = $this->documents( 'offers' ) ;
        $edge->to   = $this->documents( 'sellers' ) ;

        $init =
        [
            Arango::QUERY_FIELDS =>
            [
                'offers' =>
                [
                    Field::FILTER => Filter::MAP ,
                    Field::FIELDS => [ 'price' => Filter::DEFAULT , 'sellers' => [ Field::FILTER => Filter::EDGES ] ] ,
                    Field::EDGES  => [ 'sellers' => [ Arango::MODEL => $edge , DbAQL::REQUIRES => 'offers.sellers:list' ] ] ,
                ] ,
            ] ,
        ] ;

        $variables = [] ;
        $denied    = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => false ] , $variables ) ;

        $this->assertStringNotContainsString( 'sellers' , $denied ) ;
        $this->assertStringNotContainsString( 'offer_has_sellers' , $denied ) ;
        $this->assertStringContainsString( 'price' , $denied ) ;

        $variables = [] ;
        $granted   = $this->host()->returnFields( $init + [ Arango::AUTHORIZER => fn() => true ] , $variables ) ;

        $this->assertStringContainsString( 'offer_has_sellers' , $granted ) ;
    }

    /**
     * Non-regression for the main query: with the default DOC_REF ('doc'), a
     * `Filter::EDGES_COUNT` still anchors its `LET` on `doc` exactly as before.
     */
    public function testEdgesCountInMainQueryStillAnchorsOnDoc() :void
    {
        $variables = [] ;

        $edge = new MockEdges( 'user_has_roles' ) ;
        $edge->from = $this->documents( 'users' ) ;
        $edge->to   = $this->documents( 'roles' ) ;

        $this->host()->returnFields
        (
            [
                Arango::QUERY_FIELDS => [ 'rolesCount' => [ Field::FILTER => Filter::EDGES_COUNT ] ] ,
                Arango::EDGES        => [ 'rolesCount' => [ Arango::MODEL => $edge ] ] ,
            ] ,
            $variables ,
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringContainsString( 'IN OUTBOUND doc user_has_roles' , $variables[ 0 ] ) ;
    }
}
