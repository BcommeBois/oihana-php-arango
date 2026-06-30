<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\buildEdgeCountVariable;

/**
 * Characterization coverage for {@see buildEdgeCountVariable()} — builds a
 * `LET name = LENGTH( FOR <name>_v IN <dir> startVertex edgeCollection RETURN <name>_v )`
 * count expression. The inner loop variable is derived from the LET name (never the
 * shared `vertex`) so the count composes inside a vertex traversal without collision.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgeCountVariableTest extends TestCase
{
    public function testBuildsOutboundCountByDefault() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND doc user_has_roles RETURN rolesCount_v))' ,
            buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => $edges ] )
        ) ;
    }

    public function testHonorsDirectionStartVertexAndUniqueName() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $this->assertSame
        (
            'LET cnt = (LENGTH(FOR cnt_v IN INBOUND v user_has_roles RETURN cnt_v))' ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [ AQL::MODEL => $edges , AQL::DIRECTION => Traversal::INBOUND , AQL::UNIQUE => 'cnt' ] ,
                'v'
            )
        ) ;
    }

    /**
     * Regression: when the count is projected through a vertex traversal
     * (Edges::getVertices()), the outer loop is already named `vertex`. The inner
     * count loop must use a distinct variable, otherwise ArangoDB raises
     * "variable 'vertex' is assigned multiple times". Here the start vertex is the
     * outer `vertex` and the inner loop must NOT reuse it.
     */
    public function testInnerLoopDoesNotCollideWithAnOuterVertexLoop() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $aql = buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => $edges ] , AQL::VERTEX ) ;

        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND vertex user_has_roles RETURN rolesCount_v))' ,
            $aql
        ) ;

        // Two distinct FOR loop variables, none reusing the shared `vertex` loop name.
        $this->assertStringNotContainsString( 'FOR vertex IN' , $aql ) ;
    }

    public function testThrowsWhenModelIsNotEdges() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ;
    }

    public function testThrowsWhenCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockEdges( '' ) ] ) ;
    }
}
