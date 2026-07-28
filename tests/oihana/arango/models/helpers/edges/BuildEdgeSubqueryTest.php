<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\buildEdgeSubquery;

/**
 * Focused coverage for {@see buildEdgeSubquery()} — the inner edge traversal
 * sub-query ({@see \oihana\arango\models\helpers\edges\buildEdgeVariable()} prefixes
 * it with `LET name = `, {@see \oihana\arango\models\helpers\edges\buildPolymorphicEdgeVariable()}
 * wraps several bodies in `APPEND`). The historical behaviour is already covered
 * through `buildEdgeVariable`; here we pin the two additions: the *no-LET* output
 * shape and the `$extraConditions` FILTER injection.
 *
 * The vertex / edge loop refs are random (`vertex_<n>` / `edge_<n>`), normalized
 * before the exact assertions.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgeSubqueryTest extends TestCase
{
    public function testReturnsParenthesizedTraversalWithoutLet() :void
    {
        $result = $this->normalize
        (
            buildEdgeSubquery( 'roles' , [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , Arango::PROPERTY => 'name' ] )
        ) ;

        $this->assertSame
        (
            '(FOR vertex, edge IN OUTBOUND doc user_has_roles ' .
            'OPTIONS {"order":"bfs","uniqueVertices":"global"} SORT edge.created DESC RETURN vertex.name)' ,
            $result
        ) ;
    }

    public function testExtraConditionsEmitAFilterAfterTheTraversal() :void
    {
        $result = $this->normalize
        (
            buildEdgeSubquery
            (
                'roles' ,
                [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , Arango::PROPERTY => 'name' ] ,
                AQL::DOC ,
                null ,
                [] ,
                [ 'doc.kind == "warehouse"' ] // discriminator guard
            )
        ) ;

        $this->assertSame
        (
            '(FOR vertex, edge IN OUTBOUND doc user_has_roles ' .
            'OPTIONS {"order":"bfs","uniqueVertices":"global"} ' .
            'FILTER doc.kind == "warehouse" SORT edge.created DESC RETURN vertex.name)' ,
            $result
        ) ;
    }

    // ------------------------------------------------------------------ AQL::WHERE (vertex scope)

    /**
     * The declared predicate is compiled against the TRAVERSED VERTEX — not the
     * start vertex, which is what `$extraConditions` carries — and emitted in the
     * same `FILTER` slot.
     */
    public function testWhereCompilesAgainstTheTraversedVertex() :void
    {
        $result = $this->normalize( buildEdgeSubquery( 'roles' , $this->definition
        ([
            AQL::WHERE => [ 'status' , 'active' ] ,
        ])) ) ;

        $this->assertSame
        (
            '(FOR vertex, edge IN OUTBOUND doc user_has_roles ' .
            'OPTIONS {"order":"bfs","uniqueVertices":"global"} ' .
            "FILTER vertex.status == 'active' SORT edge.created DESC RETURN vertex.name)" ,
            $result
        ) ;
    }

    /**
     * The main use case: the retained set is decided by a bind supplied at query
     * time. Only the `@name` token is emitted — nothing is inlined.
     */
    public function testWhereAcceptsARuntimeBindReference() :void
    {
        $result = $this->normalize( buildEdgeSubquery( 'roles' , $this->definition
        ([
            AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
        ])) ) ;

        $this->assertStringContainsString( 'FILTER vertex.id NOT IN @hiddenTerms ' , $result ) ;
        $this->assertStringNotContainsString( 'hiddenTerms"' , $result ) ; // no inlined value
        $this->assertStringNotContainsString( "'hiddenTerms'" , $result ) ;
    }

    /** The full `Field::WHEN` grammar is available — here a group, negations included. */
    public function testWhereSupportsTheWholeConditionGrammar() :void
    {
        $result = $this->normalize( buildEdgeSubquery( 'roles' , $this->definition
        ([
            AQL::WHERE => [ 'or' , [ 'status' , 'active' ] , [ 'not' , [ 'hidden' , true ] ] ] ,
        ])) ) ;

        $this->assertStringContainsString
        (
            "FILTER (vertex.status == 'active' || !(vertex.hidden == true))" ,
            $result
        ) ;
    }

    /**
     * The predicate is APPENDED to the injected conditions, never substituted for
     * them: a polymorphic branch guard keeps its head position and both land in the
     * same `FILTER`. Losing the guard would make every branch of an `APPEND` yield
     * rows at once.
     */
    public function testWhereCumulatesWithTheInjectedGuardInsteadOfReplacingIt() :void
    {
        $result = $this->normalize
        (
            buildEdgeSubquery
            (
                'roles' ,
                $this->definition( [ AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ] ) ,
                AQL::DOC ,
                null ,
                [] ,
                [ 'doc.kind == "warehouse"' ]
            )
        ) ;

        $this->assertStringContainsString
        (
            'FILTER doc.kind == "warehouse" && vertex.id NOT IN @hiddenTerms ' ,
            $result
        ) ;
    }

    /** Without the key, the emitted AQL is strictly the historical one. */
    public function testWithoutWhereTheOutputIsUnchanged() :void
    {
        $this->assertSame
        (
            $this->normalize( buildEdgeSubquery( 'roles' , $this->definition() ) ) ,
            $this->normalize( buildEdgeSubquery( 'roles' , $this->definition( [ AQL::WHERE => null ] ) ) )
        ) ;
    }

    /** A malformed descriptor fails loud rather than emitting no `FILTER`. */
    public function testMalformedWhereThrows() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        buildEdgeSubquery( 'roles' , $this->definition( [ AQL::WHERE => [] ] ) ) ;
    }

    /** An attribute name able to break out of a `vertex.<attr>` accessor is rejected. */
    public function testUnsafeWhereAttributeThrows() :void
    {
        $this->expectException( ValidationException::class ) ;

        buildEdgeSubquery( 'roles' , $this->definition( [ AQL::WHERE => [ 'a" || true || "' , 'x' ] ] ) ) ;
    }

    /**
     * The base definition every AQL::WHERE case builds on — a scalar `PROPERTY`
     * projection, so the assertions stay on the FILTER rather than on a field list.
     *
     * @param array $extra Keys merged over the base definition.
     *
     * @return array
     */
    private function definition( array $extra = [] ) :array
    {
        return [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , Arango::PROPERTY => 'name' , ...$extra ] ;
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
