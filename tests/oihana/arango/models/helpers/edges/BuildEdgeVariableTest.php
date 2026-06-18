<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\enums\Scope;
use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\buildEdgeVariable;

/**
 * Characterization coverage for {@see buildEdgeVariable()} — builds a
 * `LET name = ( FOR vertex, edge IN <dir> startVertex edgeCollection
 * [OPTIONS] [SORT] RETURN ... )` sub-traversal variable.
 *
 * The vertex / edge loop refs are random (`vertex_<n>` / `edge_<n>`), so they
 * are normalized to `vertex` / `edge` before the exact assertions.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgeVariableTest extends TestCase
{
    public function testThrowsWhenNameIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeVariable( '' , [ AQL::MODEL => new MockEdges( 'user_has_roles' ) ] ) ;
    }

    public function testThrowsWhenModelIsNotEdges() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeVariable( 'roles' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ;
    }

    public function testThrowsWhenCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeVariable( 'roles' , [ AQL::MODEL => new MockEdges( '' ) ] ) ;
    }

    public function testPropertyBranchReturnsTheVertexProperty() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $result = $this->normalize( buildEdgeVariable( 'roles' , [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] ) ) ;

        $this->assertSame
        (
            'LET roles = (FOR vertex, edge IN OUTBOUND doc user_has_roles ' .
            'OPTIONS {"order":"bfs","uniqueVertices":"global"} SORT edge.created DESC RETURN vertex.name)' ,
            $result
        ) ;
    }

    public function testNoFieldsBranchReturnsTheWholeVertex() :void
    {
        $edges = $this->wiredEdges() ; // wired `to` model, no FIELDS → empty projection

        $result = $this->normalize( buildEdgeVariable( 'roles' , [ AQL::MODEL => $edges ] ) ) ;

        $this->assertSame
        (
            'LET roles = (FOR vertex, edge IN OUTBOUND doc user_has_roles ' .
            'OPTIONS {"order":"bfs","uniqueVertices":"global"} SORT edge.created DESC RETURN vertex)' ,
            $result
        ) ;
    }

    public function testFieldsBranchReturnsAProjection() :void
    {
        $edges = $this->wiredEdges() ;

        $result = $this->normalize( buildEdgeVariable( 'roles' , [ AQL::MODEL => $edges , AQL::FIELDS => [ 'name' ] ] ) ) ;

        $this->assertStringStartsWith( 'LET roles = (FOR vertex, edge IN OUTBOUND doc user_has_roles' , $result ) ;
        $this->assertStringContainsString( 'RETURN {' , $result ) ; // projected object, not the bare vertex
    }

    public function testFieldsBranchProjectsEdgeScopedFieldsFromTheEdge() :void
    {
        $edges = $this->wiredEdges() ;

        // `name` comes from the target vertex, `role` is carried by the edge.
        $result = $this->normalize( buildEdgeVariable( 'roles' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'name' => [] ,
                'role' => [ Field::SCOPE => Scope::EDGE ] ,
            ] ,
        ] ) ) ;

        $this->assertStringContainsString( 'RETURN {' , $result ) ;
        $this->assertStringContainsString( 'name:vertex.name' , $result ) ;
        $this->assertStringContainsString( 'role:edge.role' , $result ) ;
    }

    /**
     * `Filter::WRAP` nests the traversal vertex under a named key (`subject`)
     * beside an edge-scoped scalar (`role`), instead of flattening the vertex
     * fields at the root. Exercises the full model path (`prepareQueryFields` →
     * `normalizeFieldDefinition` → `aqlFields` → `aqlFieldWrap`) — the
     * normalization must preserve `Field::FIELDS` for `Filter::WRAP`.
     */
    public function testFieldsBranchWrapsTheVertexUnderAKeyWithFilterWrap() :void
    {
        $edges = $this->wiredEdges() ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'role'    => [ Field::SCOPE => Scope::EDGE ] ,
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'id'   => [] ,
                        'name' => [] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        $this->assertStringContainsString( 'role:edge.role' , $result ) ;
        $this->assertStringContainsString( 'subject:{id:vertex.id, name:vertex.name}' , $result ) ;
    }

    /**
     * `Filter::WRAP` with `Field::RAW => true` embeds the whole vertex under the
     * key — the normalization must preserve `Field::RAW`.
     */
    public function testFieldsBranchWrapsTheWholeVertexWithFieldRaw() :void
    {
        $edges = $this->wiredEdges() ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' => [ Field::FILTER => Filter::WRAP , Field::RAW => true ] ,
            ] ,
        ] ) ) ;

        $this->assertStringContainsString( 'subject:vertex' , $result ) ;
    }

    /**
     * `Filter::WRAP` can carry the wrapped vertex's own relations : a sub-edge
     * declared under `Field::EDGES` is traversed **from the wrapped vertex** and
     * nested **inside** the wrapped object, beside the scalar fields — in a single
     * query. The cardinality marker (`Filter::EDGE`) lives in `Field::FIELDS`,
     * exactly like a top-level projection. The backing `LET` is emitted in the
     * enclosing `FOR vertex` scope and projected as `relation:(IS_OBJECT(...))`.
     */
    public function testWrapNestsAnOutboundSubEdgeUnderTheWrappedKey() :void
    {
        $edges = $this->wiredEdges() ;
        $sub   = $this->wiredSubEdges( 'org_has_member' , 'organizations' , inbound: false ) ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'id'       => [] ,
                        'name'     => [] ,
                        'worksFor' => [ Field::FILTER => Filter::EDGE ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'worksFor' => [ AQL::MODEL => $sub , AQL::FIELDS => [ 'id' => [] , 'name' => [] ] ] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        // The sub-edge LET is emitted inside the FOR-vertex scope, traversing FROM the wrapped vertex.
        $this->assertMatchesRegularExpression( '/LET worksFor_e\d+ = \(FOR vertex, edge IN OUTBOUND vertex org_has_member/' , $result ) ;
        // The wrapped object nests the related entity beside the scalar fields.
        $this->assertStringContainsString( 'subject:{' , $result ) ;
        $this->assertStringContainsString( 'id:vertex.id' , $result ) ;
        $this->assertMatchesRegularExpression( '/worksFor:IS_OBJECT\(worksFor_e\d+\)/' , $result ) ;
    }

    /**
     * The related entity is most often reached the other way round : a sub-edge
     * declares `AQL::DIRECTION => Traversal::INBOUND` and is still traversed from
     * the wrapped vertex (`IN INBOUND vertex …`).
     */
    public function testWrapNestsAnInboundSubEdgeUnderTheWrappedKey() :void
    {
        $edges = $this->wiredEdges() ;
        $sub   = $this->wiredSubEdges( 'org_has_member' , 'organizations' , inbound: true ) ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name'     => [] ,
                        'worksFor' => [ Field::FILTER => Filter::EDGE ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'worksFor' => [ AQL::MODEL => $sub , AQL::DIRECTION => Traversal::INBOUND , AQL::FIELDS => [ 'name' => [] ] ] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        $this->assertMatchesRegularExpression( '/LET worksFor_e\d+ = \(FOR vertex, edge IN INBOUND vertex org_has_member/' , $result ) ;
        $this->assertStringContainsString( 'subject:{' , $result ) ;
        $this->assertMatchesRegularExpression( '/worksFor:IS_OBJECT\(worksFor_e\d+\)/' , $result ) ;
    }

    /**
     * A wrapped relation can also be a **count** : a `Filter::EDGES_COUNT` marker
     * nests the cardinality of the sub-traversal under the wrapped key, exactly
     * like a top-level edge count — proof the whole edge grammar applies verbatim.
     */
    public function testWrapNestsASubEdgeCountUnderTheWrappedKey() :void
    {
        $edges = $this->wiredEdges() ;
        $sub   = $this->wiredSubEdges( 'org_has_member' , 'organizations' , inbound: false ) ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name'      => [] ,
                        'teamCount' => [ Field::FILTER => Filter::EDGES_COUNT ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'teamCount' => [ AQL::MODEL => $sub ] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        $this->assertMatchesRegularExpression( '/LET teamCount_e\d+ = /' , $result ) ;
        $this->assertStringContainsString( 'OUTBOUND vertex org_has_member' , $result ) ;
        $this->assertMatchesRegularExpression( '/teamCount:teamCount_e\d+/' , $result ) ;
    }

    /**
     * A wrapped vertex can also nest a **join** (a stored reference resolved to
     * another collection's document), declared with the same top-level grammar :
     * the `Filter::JOIN` marker in `Field::FIELDS` and the resolution in
     * `Field::JOINS`. The join reads the stored attribute from the wrapped vertex.
     */
    public function testWrapNestsAJoinUnderTheWrappedKey() :void
    {
        $edges = $this->wiredEdges() ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name' => [] ,
                        'role' => [ Field::FILTER => Filter::JOIN ] ,
                    ] ,
                    Field::JOINS =>
                    [
                        'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        // The join LET resolves the stored reference from the wrapped vertex.
        $this->assertMatchesRegularExpression( '/LET role_\w+ = \(FOR \w+ IN roles FILTER \w+\._key == vertex\.role/' , $result ) ;
        $this->assertStringContainsString( 'subject:{' , $result ) ;
        $this->assertMatchesRegularExpression( '/role:IS_OBJECT\(role_\w+\)/' , $result ) ;
    }

    /**
     * Depth is recursive by nature : a wrapped sub-edge is an ordinary traversal,
     * so it carries its OWN edges — the related entity can project further
     * (`subject.worksFor` then `worksFor.locatedIn`). Two nested `LET` are emitted,
     * the inner one rooted on the sub-edge's own target vertex.
     */
    public function testWrapSubEdgeProjectsItsOwnNestedEdge() :void
    {
        $edges = $this->wiredEdges() ;

        // worksFor : org_has_member, whose target organization itself exposes locatedIn (org_in_place).
        $org = new MockEdges( 'org_has_member' ) ;
        $orgTo = new MockDocuments( 'organizations' ) ;
        $orgTo->initializeDeleteSignals() ;
        $org->to = $orgTo ;

        $place = $this->wiredSubEdges( 'org_in_place' , 'places' , inbound: false ) ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name'     => [] ,
                        'worksFor' => [ Field::FILTER => Filter::EDGE ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'worksFor' =>
                        [
                            AQL::MODEL  => $org ,
                            AQL::FIELDS =>
                            [
                                'name'      => [] ,
                                'locatedIn' => [ Field::FILTER => Filter::EDGE ] ,
                            ] ,
                            AQL::EDGES =>
                            [
                                'locatedIn' => [ AQL::MODEL => $place , AQL::FIELDS => [ 'name' => [] ] ] ,
                            ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] ) ) ;

        // outer sub-edge traverses from the wrapped vertex, inner one from the sub-edge's own vertex
        $this->assertStringContainsString( 'IN OUTBOUND vertex org_has_member' , $result ) ;
        $this->assertStringContainsString( 'IN OUTBOUND vertex org_in_place'   , $result ) ;
        $this->assertMatchesRegularExpression( '/worksFor:IS_OBJECT\(worksFor_e\d+\)/'            , $result ) ;
        $this->assertMatchesRegularExpression( '/locatedIn:IS_OBJECT\(\w*locatedIn_e\d+\)/' , $result ) ;
    }

    /**
     * Permission gating applies verbatim inside a WRAP : a denied sub-edge
     * (`Field::REQUIRES`) is dropped from BOTH sides — no `LET` is emitted and
     * the key does not appear in the wrapped object (no dangling reference).
     */
    public function testWrapSubEdgeDeniedByGatingIsFullyDropped() :void
    {
        $edges = $this->wiredEdges() ;
        $sub   = $this->wiredSubEdges( 'org_has_member' , 'organizations' , inbound: false ) ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'name'     => [] ,
                        'worksFor' => [ Field::FILTER => Filter::EDGE , Field::REQUIRES => 'org.read' ] ,
                    ] ,
                    Field::EDGES =>
                    [
                        'worksFor' => [ AQL::MODEL => $sub , AQL::FIELDS => [ 'name' => [] ] ] ,
                    ] ,
                ] ,
            ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;

        $this->assertStringContainsString( 'subject:{name:vertex.name}' , $result ) ; // only the scalar survives
        $this->assertStringNotContainsString( 'worksFor' , $result ) ;                // no projection key
        $this->assertStringNotContainsString( 'org_has_member' , $result ) ;          // no LET traversal
    }

    /**
     * Retro-compatibility : a `Filter::WRAP` field that declares no relation
     * behaves exactly as before — only the scalar projection, no extra `LET`.
     */
    public function testWrapWithoutEdgesIsUnchanged() :void
    {
        $edges = $this->wiredEdges() ;

        $result = $this->normalize( buildEdgeVariable( 'identities' ,
        [
            AQL::MODEL  => $edges ,
            AQL::FIELDS =>
            [
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS => [ 'id' => [] , 'name' => [] ] ,
                ] ,
            ] ,
        ] ) ) ;

        $this->assertStringContainsString( 'subject:{id:vertex.id, name:vertex.name}' , $result ) ;
        $this->assertStringNotContainsString( 'LET worksFor' , $result ) ;
    }

    public function testHonorsCustomStartVertex() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $result = $this->normalize( buildEdgeVariable( 'roles' , [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] , 'parent' ) ) ;

        $this->assertStringContainsString( 'IN OUTBOUND parent user_has_roles' , $result ) ;
    }

    /**
     * A {@see MockEdges} with a wired `to` vertex model whose delete signals
     * are initialized (otherwise the Edges destructor disconnects a null signal).
     *
     * @return MockEdges
     */
    private function wiredEdges() :MockEdges
    {
        $to = new MockDocuments( 'roles' ) ;
        $to->initializeDeleteSignals() ;

        $edges = new MockEdges( 'user_has_roles' ) ;
        $edges->to = $to ;

        return $edges ;
    }

    /**
     * A {@see MockEdges} for a wrapped sub-traversal : the target vertex model is
     * wired on `to` (OUTBOUND) or `from` (INBOUND), the direction buildEdgeVariable
     * reads to pick the projected document model.
     *
     * @param string $collection       The sub-edge collection (e.g. 'org_has_member').
     * @param string $vertexCollection The related vertex collection (e.g. 'organizations').
     * @param bool   $inbound          Whether the sub-edge is reached INBOUND.
     *
     * @return MockEdges
     */
    private function wiredSubEdges( string $collection , string $vertexCollection , bool $inbound ) :MockEdges
    {
        $vertex = new MockDocuments( $vertexCollection ) ;
        $vertex->initializeDeleteSignals() ;

        $edges = new MockEdges( $collection ) ;
        if ( $inbound )
        {
            $edges->from = $vertex ;
        }
        else
        {
            $edges->to = $vertex ;
        }

        return $edges ;
    }

    /**
     * Normalizes the random `vertex_<n>` / `edge_<n>` loop refs to stable tokens.
     *
     * @param string $aql
     *
     * @return string
     */
    private function normalize( string $aql ) :string
    {
        return preg_replace( [ '/vertex_\d+/' , '/edge_\d+/' ] , [ 'vertex' , 'edge' ] , $aql ) ;
    }
}
