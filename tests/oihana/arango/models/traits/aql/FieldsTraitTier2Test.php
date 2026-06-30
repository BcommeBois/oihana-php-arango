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
