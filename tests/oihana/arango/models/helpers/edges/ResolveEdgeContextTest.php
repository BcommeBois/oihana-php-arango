<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\Edges;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\resolveEdgeContext;

/**
 * Coverage for {@see resolveEdgeContext()} — the shared preamble of
 * {@see oihana\arango\models\helpers\edges\buildEdgeVariable()} and
 * {@see oihana\arango\models\helpers\edges\buildEdgeCountVariable()}: it resolves
 * and validates the Edges model and returns `[ $model , $edgeCollection , $direction ]`.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class ResolveEdgeContextTest extends TestCase
{
    public function testReturnsModelCollectionAndOutboundByDefault() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        [ $model , $collection , $direction ] = resolveEdgeContext( [ AQL::MODEL => $edges ] ) ;

        $this->assertInstanceOf( Edges::class , $model ) ;
        $this->assertSame( $edges , $model ) ;
        $this->assertSame( 'user_has_roles' , $collection ) ;
        $this->assertSame( Traversal::OUTBOUND , $direction ) ;
    }

    public function testHonorsExplicitDirection() :void
    {
        [ , , $direction ] = resolveEdgeContext
        (
            [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , AQL::DIRECTION => Traversal::INBOUND ]
        ) ;

        $this->assertSame( Traversal::INBOUND , $direction ) ;
    }

    public function testThrowsWhenModelIsNotEdges() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        resolveEdgeContext( [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ;
    }

    public function testThrowsWhenCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        resolveEdgeContext( [ AQL::MODEL => new MockEdges( '' ) ] ) ;
    }
}
