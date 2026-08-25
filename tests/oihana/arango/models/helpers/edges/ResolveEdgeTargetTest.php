<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\resolveEdgeTarget;

/**
 * Coverage for {@see resolveEdgeTarget()} — the vertex model a traversal lands
 * on, shared by the projection sub-query, the nested relation walker and the
 * hierarchical filters. All three used to pick it with a binary ternary, which
 * has no room for {@see Traversal::ANY}.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class ResolveEdgeTargetTest extends TestCase
{
    public function testInboundReachesTheFromModel() :void
    {
        $edges = $this->edges( 'articles' , 'authors' ) ;

        $this->assertSame( $edges->from , resolveEdgeTarget( $edges , Traversal::INBOUND ) ) ;
    }

    public function testOutboundReachesTheToModel() :void
    {
        $edges = $this->edges( 'articles' , 'authors' ) ;

        $this->assertSame( $edges->to , resolveEdgeTarget( $edges , Traversal::OUTBOUND ) ) ;
    }

    /**
     * The case `ANY` exists for: a self-referential relation, both ends on the
     * same collection. The two ends are **distinct instances** here, so what is
     * compared is the collection they designate, not object identity — and the
     * resolved end stays the `_to` one the ternary picked, so an unambiguous
     * declaration keeps compiling exactly as before.
     */
    public function testAnyResolvesASelfReferentialRelationOnItsToEnd() :void
    {
        $edges = $this->edges( 'users' , 'users' ) ;

        $this->assertSame( $edges->to , resolveEdgeTarget( $edges , Traversal::ANY ) ) ;
    }

    /**
     * The defect this helper closes: `ANY` reaches both ends, so on a
     * heterogeneous relation the far side's vertices used to be projected with
     * the near side's fields — and gated by the near side's `Field::REQUIRES`.
     */
    public function testAnyIsRefusedOverTwoDifferentCollections() :void
    {
        $edges = $this->edges( 'articles' , 'authors' ) ;

        $this->expectException( UnexpectedValueException::class ) ;
        $this->expectExceptionMessage( 'articles_authors' ) ;

        resolveEdgeTarget( $edges , Traversal::ANY ) ;
    }

    /**
     * One end unwired is not "the other end wins": nothing declares what comes
     * back from the far side, so `ANY` cannot designate a single model either.
     */
    public function testAnyIsRefusedWhenOneEndIsNotWired() :void
    {
        $edges = $this->edges( null , 'authors' ) ;

        $this->expectException( UnexpectedValueException::class ) ;

        resolveEdgeTarget( $edges , Traversal::ANY ) ;
    }

    /**
     * A model that wires no vertex end at all keeps answering `null` — the shape
     * the `_from` / `_to` filter methods live with, and which the callers that
     * tolerate a null target already handle.
     */
    public function testBothEndsUnwiredStaysNull() :void
    {
        $edges = $this->edges( null , null ) ;

        $this->assertNull( resolveEdgeTarget( $edges , Traversal::ANY ) ) ;
        $this->assertNull( resolveEdgeTarget( $edges , Traversal::INBOUND ) ) ;
        $this->assertNull( resolveEdgeTarget( $edges , Traversal::OUTBOUND ) ) ;
    }

    public function testAnOrientedDirectionNeverInspectsTheOtherEnd() :void
    {
        $edges = $this->edges( null , 'authors' ) ;

        $this->assertNull( resolveEdgeTarget( $edges , Traversal::INBOUND ) ) ;
        $this->assertSame( $edges->to , resolveEdgeTarget( $edges , Traversal::OUTBOUND ) ) ;
    }

    /**
     * A {@see MockEdges} with its two vertex ends wired (or left null). The
     * delete signals are initialized, otherwise the Edges destructor disconnects
     * a null signal.
     *
     * @param string|null $from The `_from` vertex collection, or null to leave it unwired.
     * @param string|null $to   The `_to` vertex collection, or null to leave it unwired.
     *
     * @return MockEdges
     */
    private function edges( ?string $from , ?string $to ) :MockEdges
    {
        $edges = new MockEdges( 'articles_authors' ) ;

        if ( $from !== null )
        {
            $edges->from = new MockDocuments( $from ) ;
            $edges->from->initializeDeleteSignals() ;
        }

        if ( $to !== null )
        {
            $edges->to = new MockDocuments( $to ) ;
            $edges->to->initializeDeleteSignals() ;
        }

        return $edges ;
    }
}
