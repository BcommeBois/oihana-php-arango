<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\buildVariables;

/**
 * Characterization coverage for {@see buildVariables()} — the dispatcher that
 * turns a normalized field map into the list of `LET` sub-traversal / sub-query
 * variables (edges, edge counts, joins, nested documents), honoring field-level
 * gating.
 *
 * Random loop refs (`vertex_<n>` / `edge_<n>` / `doc_join_<n>`) are normalized
 * before assertions.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class BuildVariablesTest extends TestCase
{
    public function testEmptyFieldsIsANoOp() :void
    {
        $variables = [] ;
        buildVariables( $variables , [] ) ;

        $this->assertSame( [] , $variables ) ;
    }

    public function testSimpleFieldsAreSkipped() :void
    {
        $variables = [] ;
        // a plain projection field (FILTER = attribute name) matches no switch arm
        buildVariables( $variables , [ 'name' => [ Field::FILTER => 'name' ] ] ) ;

        $this->assertSame( [] , $variables ) ;
    }

    // ---- edges ----------------------------------------------------------

    public function testEdgeFilterBuildsAnEdgeVariableWithUniqueAndProperty() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'roles' => [ Field::FILTER => Filter::EDGE , Field::UNIQUE => 'r' , Field::PROPERTY => 'name' ] ] ,
            [ 'roles' => [ AQL::MODEL => $this->wiredEdges() ] ]
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringStartsWith( 'LET r = (FOR ', $this->normalize( $variables[ 0 ] ) ) ;
        $this->assertStringContainsString( 'RETURN vertex.name' , $this->normalize( $variables[ 0 ] ) ) ;
    }

    public function testEdgesFilterBuildsAnEdgeVariable() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            [ 'roles' => [ AQL::MODEL => $this->wiredEdges() ] ]
        ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertStringContainsString( 'IN OUTBOUND doc user_has_roles' , $this->normalize( $variables[ 0 ] ) ) ;
    }

    public function testEdgesCountFilterBuildsACountVariable() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'roles' => [ Field::FILTER => Filter::EDGES_COUNT ] ] ,
            [ 'roles' => [ AQL::MODEL => new MockEdges( 'user_has_roles' ) ] ]
        ) ;

        $this->assertSame
        (
            [ 'LET roles = (LENGTH(FOR vertex IN OUTBOUND doc user_has_roles RETURN vertex))' ] ,
            $this->normalize( $variables )
        ) ;
    }

    public function testEdgeStringReferenceResolvesToAnotherDefinition() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'alias' => [ Field::FILTER => Filter::EDGE ] ] ,
            [ 'alias' => 'roles' , 'roles' => [ AQL::MODEL => $this->wiredEdges() ] ]
        ) ;

        $this->assertCount( 1 , $variables ) ;
    }

    public function testEdgeWithMissingDefinitionIsSkipped() :void
    {
        $variables = [] ;
        buildVariables( $variables , [ 'roles' => [ Field::FILTER => Filter::EDGE ] ] , [] ) ;

        $this->assertSame( [] , $variables ) ;
    }

    public function testEdgeDeniedByGatingIsSkipped() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'roles' => [ Field::FILTER => Filter::EDGE , Field::REQUIRES => 'users.roles:list' ] ] ,
            [ 'roles' => [ AQL::MODEL => $this->wiredEdges() ] ] ,
            [] ,   // joins
            null , // container
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn() => false ] // denies → break
        ) ;

        $this->assertSame( [] , $variables ) ;
    }

    // ---- joins ----------------------------------------------------------

    public function testJoinFilterBuildsAScalarJoinVariable() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'role' => [ Field::FILTER => Filter::JOIN ] ] ,
            [] ,
            [ 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ]
        ) ;

        $this->assertSame
        (
            [ 'LET role = (FOR doc_join IN roles FILTER doc_join._key == doc.role RETURN doc_join)' ] ,
            $this->normalize( $variables )
        ) ;
    }

    public function testJoinsFilterBuildsAnArrayJoinVariable() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'roles' => [ Field::FILTER => Filter::JOINS ] ] ,
            [] ,
            [ 'roles' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ]
        ) ;

        $this->assertStringContainsString
        (
            'FILTER doc_join._key IN (IS_ARRAY(doc.roles) ? doc.roles : [])' ,
            $this->normalize( $variables[ 0 ] )
        ) ;
    }

    public function testJoinStringReferenceResolvesToAnotherDefinition() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'alias' => [ Field::FILTER => Filter::JOIN ] ] ,
            [] ,
            [ 'alias' => 'role' , 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ]
        ) ;

        $this->assertCount( 1 , $variables ) ;
    }

    public function testJoinWithMissingDefinitionIsSkipped() :void
    {
        $variables = [] ;
        buildVariables( $variables , [ 'role' => [ Field::FILTER => Filter::JOIN ] ] , [] , [] ) ;

        $this->assertSame( [] , $variables ) ;
    }

    public function testJoinDeniedByGatingIsSkipped() :void
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'role' => [ Field::FILTER => Filter::JOIN , Field::REQUIRES => 'x' ] ] ,
            [] ,
            [ 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ] ,
            null ,
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn() => false ]
        ) ;

        $this->assertSame( [] , $variables ) ;
    }

    // ---- nested document ------------------------------------------------

    public function testDocumentFilterRecursesIntoSubFields() :void
    {
        $variables = [] ;
        buildVariables( $variables ,
        [
            'address' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS => [ 'role' => [ Field::FILTER => Filter::JOIN ] ] ,
                Field::JOINS  => [ 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ] ,
            ] ,
        ] ) ;

        // the nested join is keyed on the parent document ref (doc.address)
        $this->assertStringContainsString( 'doc_join._key == doc.address.role' , $this->normalize( $variables[ 0 ] ) ) ;
    }

    public function testDocumentFilterWithoutSubFieldsIsANoOp() :void
    {
        $variables = [] ;
        buildVariables( $variables , [ 'address' => [ Field::FILTER => Filter::DOCUMENT ] ] ) ;

        $this->assertSame( [] , $variables ) ;
    }

    /**
     * A {@see MockEdges} with a wired `to` vertex model whose delete signals
     * are initialized (the Edges destructor disconnects the delete signal).
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
     * Normalizes the random loop refs to stable tokens.
     *
     * @param string|array $aql
     *
     * @return string|array
     */
    private function normalize( string|array $aql ) :string|array
    {
        $patterns     = [ '/vertex_\d+/' , '/edge_\d+/' , '/doc_join_\d+/' ] ;
        $replacements = [ 'vertex' , 'edge' , 'doc_join' ] ;

        return is_array( $aql )
             ? array_map( fn( string $s ) => preg_replace( $patterns , $replacements , $s ) , $aql )
             : preg_replace( $patterns , $replacements , $aql ) ;
    }
}
