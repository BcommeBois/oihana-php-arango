<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\models\enums\Purge;
use oihana\models\enums\NoticeType;
use oihana\signals\notices\Payload;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for the {@see \oihana\arango\models\traits\edges\callbacks\OnDeleteVertex}
 * cascade: when a vertex is deleted, onDeleteVertex() removes its edges and then
 * purges the opposite-side vertices according to the `$purge` direction.
 */
final class OnDeleteVertexTest extends TestCase
{
    /**
     * Builds an edge model wired to from/to vertex doubles. The edge's
     * deleteEdges() returns the given canned edge documents.
     *
     * @param string                          $purge The Purge direction.
     * @param array<int,object>               $edges The edges deleteEdges() should return.
     *
     * @return array{0:MockEdges,1:MockDocuments,2:MockDocuments} [edge, from, to]
     */
    private function wire( string $purge , array $edges ) :array
    {
        $from = new MockDocuments( 'webapis' ) ;
        $from->initializeDeleteSignals() ;

        $to = new MockDocuments( 'permissions' ) ;
        $to->initializeDeleteSignals() ;

        $edge = new MockEdges( 'webapi_has_permission' ) ;
        $edge->initializeFrom( $from ) ;
        $edge->initializeTo( $to ) ;
        $edge->purge = $purge ;
        $edge->documentsResult = $edges ;

        return [ $edge , $from , $to ] ;
    }

    private function payload( mixed $data , ?object $target ) :Payload
    {
        return new Payload( type: NoticeType::AFTER_DELETE , data: $data , target: $target ) ;
    }

    public function testOutboundPurgeDeletesToVerticesWhenFromVertexRemoved() :void
    {
        [ $edge , $from , $to ] = $this->wire
        (
            Purge::OUTBOUND ,
            [ (object) [ '_key' => 'e1' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ] ,
        ) ;

        $edge->onDeleteVertex( $this->payload( (object) [ '_key' => '1' ] , $from ) ) ;

        // The edges of the deleted vertex were removed...
        $this->assertStringContainsString( 'REMOVE' , $edge->lastQuery ) ;
        // ...and the TO side (permissions/9) was purged.
        $this->assertStringContainsString( 'IN permissions RETURN OLD' , $to->lastQuery ) ;
        $this->assertContains( 'permissions/9' , $to->lastBinds ) ;
        $this->assertSame( '' , $from->lastQuery , 'OUTBOUND must not purge the FROM side' ) ;
    }

    public function testInboundPurgeDeletesFromVerticesWhenToVertexRemoved() :void
    {
        [ $edge , $from , $to ] = $this->wire
        (
            Purge::INBOUND ,
            [ (object) [ '_key' => 'e1' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ] ,
        ) ;

        $edge->onDeleteVertex( $this->payload( (object) [ '_key' => '9' ] , $to ) ) ;

        $this->assertStringContainsString( 'IN webapis RETURN OLD' , $from->lastQuery ) ;
        $this->assertContains( 'webapis/1' , $from->lastBinds ) ;
        $this->assertSame( '' , $to->lastQuery , 'INBOUND must not purge the TO side' ) ;
    }

    public function testBothPurgeFromOriginSideDeletesTheOppositeSide() :void
    {
        [ $edge , $from , $to ] = $this->wire
        (
            Purge::BOTH ,
            [ (object) [ '_key' => 'e1' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ] ,
        ) ;

        $edge->onDeleteVertex( $this->payload( (object) [ '_key' => '1' ] , $from ) ) ;

        // target === from, so BOTH purges the TO side here.
        $this->assertStringContainsString( 'IN permissions RETURN OLD' , $to->lastQuery ) ;
    }

    public function testNoPurgeConfiguredSkipsVertexPurge() :void
    {
        $from = new MockDocuments( 'webapis' ) ;
        $from->initializeDeleteSignals() ;
        $to = new MockDocuments( 'permissions' ) ;
        $to->initializeDeleteSignals() ;

        $edge = new MockEdges( 'webapi_has_permission' ) ;
        $edge->initializeFrom( $from ) ;
        $edge->initializeTo( $to ) ;
        // purge left null
        $edge->documentsResult = [ (object) [ '_key' => 'e1' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ] ;

        $edge->onDeleteVertex( $this->payload( (object) [ '_key' => '1' ] , $from ) ) ;

        $this->assertSame( '' , $to->lastQuery ) ;
        $this->assertSame( '' , $from->lastQuery ) ;
    }

    public function testHandlesAnArrayOfDeletedVertices() :void
    {
        $to = new MockDocuments( 'permissions' ) ;
        $to->initializeDeleteSignals() ;
        // The recursive purge delete on `to` returns these edges, keeping the
        // re-entrant onDeleteVertex fed with non-empty data.
        $to->documentsResult = [ (object) [ '_key' => 'p9' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ] ;

        $from = new MockDocuments( 'webapis' ) ;
        $from->initializeDeleteSignals() ;

        $edge = new MockEdges( 'webapi_has_permission' ) ;
        $edge->initializeFrom( $from ) ;
        $edge->initializeTo( $to ) ;
        $edge->purge = Purge::OUTBOUND ;
        $edge->documentsResult =
        [
            (object) [ '_key' => 'e1' , '_from' => 'webapis/1' , '_to' => 'permissions/9' ] ,
            (object) [ '_key' => 'e2' , '_from' => 'webapis/2' , '_to' => 'permissions/8' ] ,
        ] ;

        $edge->onDeleteVertex( $this->payload( [ (object) [ '_key' => '1' ] , (object) [ '_key' => '2' ] ] , $from ) ) ;

        // Both TO vertices of the removed edges are purged.
        $this->assertStringContainsString( 'IN permissions RETURN OLD' , $to->lastQuery ) ;
        $this->assertContains( 'permissions/9' , $to->lastBinds ) ;
        $this->assertContains( 'permissions/8' , $to->lastBinds ) ;
    }

    public function testMissingPayloadDataIsANoOp() :void
    {
        [ $edge , $from , $to ] = $this->wire( Purge::BOTH , [] ) ;

        $edge->onDeleteVertex( $this->payload( null , $from ) ) ;

        $this->assertSame( '' , $edge->lastQuery , 'no edges should be deleted without payload data' ) ;
        $this->assertSame( '' , $to->lastQuery ) ;
    }

    public function testMissingTargetIsANoOp() :void
    {
        [ $edge , , $to ] = $this->wire( Purge::BOTH , [] ) ;

        $edge->onDeleteVertex( $this->payload( (object) [ '_key' => '1' ] , null ) ) ;

        $this->assertSame( '' , $edge->lastQuery , 'no edges should be deleted without a target' ) ;
        $this->assertSame( '' , $to->lastQuery ) ;
    }

    public function testEmptyArrayDataIsANoOpAndDoesNotCrash() :void
    {
        // Regression guard: normalize([]) → null must short-circuit, not reach
        // deleteEdges(null) (which would TypeError).
        [ $edge , $from , $to ] = $this->wire( Purge::BOTH , [] ) ;

        $edge->onDeleteVertex( $this->payload( [] , $from ) ) ;

        $this->assertSame( '' , $edge->lastQuery ) ;
        $this->assertSame( '' , $to->lastQuery ) ;
    }
}
