<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Scope;

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
