<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

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
