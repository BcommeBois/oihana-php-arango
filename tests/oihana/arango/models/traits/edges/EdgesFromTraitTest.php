<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;

use DI\Container;
use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesFromTrait}:
 * initializeFrom() / releaseFrom() (and the register/unregister of the
 * onDeleteVertex listener on the `from` model's afterDelete signal).
 */
final class EdgesFromTraitTest extends TestCase
{
    private function vertexModel() :MockDocuments
    {
        $model = new MockDocuments( 'users' ) ;
        $model->initializeDeleteSignals() ; // so register/unregister can (dis)connect afterDelete
        return $model ;
    }

    public function testInitializeFromWithDocumentSetsItAndReturnsSelf() :void
    {
        $from  = $this->vertexModel() ;
        $edges = new MockEdges( 'follows' ) ;

        $this->assertSame( $edges , $edges->initializeFrom( $from ) ) ;
        $this->assertSame( $from , $edges->from ) ;
    }

    public function testInitializeFromAcceptsTheArrayForm() :void
    {
        $from  = $this->vertexModel() ;
        $edges = new MockEdges( 'follows' ) ;

        $edges->initializeFrom( [ AQL::FROM => $from ] ) ;
        $this->assertSame( $from , $edges->from ) ;
    }

    public function testInitializeFromWithNullLeavesFromNull() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeFrom( null ) ;
        $this->assertNull( $edges->from ) ;
    }

    public function testReleaseFromClearsTheReference() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeFrom( $this->vertexModel() ) ;

        $edges->releaseFrom() ;
        $this->assertNull( $edges->from ) ;
    }

    public function testInitializeFromResolvesAStringServiceThroughTheContainer() :void
    {
        $from      = $this->vertexModel() ;
        $container = new Container() ;
        $container->set( 'usersModel' , $from ) ;

        $edges = new MockEdges( 'follows' ) ;
        $edges->initializeFrom( 'usersModel' , $container ) ;

        $this->assertSame( $from , $edges->from ) ;
    }
}
