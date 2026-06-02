<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;

use DI\Container;
use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesToTrait}:
 * initializeTo() / releaseTo() (symmetric to EdgesFromTrait).
 */
final class EdgesToTraitTest extends TestCase
{
    private function vertexModel() :MockDocuments
    {
        $model = new MockDocuments( 'roles' ) ;
        $model->initializeDeleteSignals() ;
        return $model ;
    }

    public function testInitializeToWithDocumentSetsItAndReturnsSelf() :void
    {
        $to    = $this->vertexModel() ;
        $edges = new MockEdges( 'follows' ) ;

        $this->assertSame( $edges , $edges->initializeTo( $to ) ) ;
        $this->assertSame( $to , $edges->to ) ;
    }

    public function testInitializeToAcceptsTheArrayForm() :void
    {
        $to    = $this->vertexModel() ;
        $edges = new MockEdges( 'follows' ) ;

        $edges->initializeTo( [ AQL::TO => $to ] ) ;
        $this->assertSame( $to , $edges->to ) ;
    }

    public function testInitializeToWithNullLeavesToNull() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeTo( null ) ;
        $this->assertNull( $edges->to ) ;
    }

    public function testReleaseToClearsTheReference() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeTo( $this->vertexModel() ) ;

        $edges->releaseTo() ;
        $this->assertNull( $edges->to ) ;
    }

    public function testInitializeToResolvesAStringServiceThroughTheContainer() :void
    {
        $to        = $this->vertexModel() ;
        $container = new Container() ;
        $container->set( 'rolesModel' , $to ) ;

        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeTo( 'rolesModel' , $container ) ;

        $this->assertSame( $to , $edges->to ) ;
    }
}
