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
 * `LET name = LENGTH( FOR vertex IN <dir> startVertex edgeCollection RETURN vertex )`
 * count expression.
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
            'LET rolesCount = (LENGTH(FOR vertex IN OUTBOUND doc user_has_roles RETURN vertex))' ,
            buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => $edges ] )
        ) ;
    }

    public function testHonorsDirectionStartVertexAndUniqueName() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $this->assertSame
        (
            'LET cnt = (LENGTH(FOR vertex IN INBOUND v user_has_roles RETURN vertex))' ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [ AQL::MODEL => $edges , AQL::DIRECTION => Traversal::INBOUND , AQL::UNIQUE => 'cnt' ] ,
                'v'
            )
        ) ;
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
