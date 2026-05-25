<?php

namespace tests\oihana\arango\models\traits\edges;

use ArrayObject;
use Closure;
use PHPUnit\Framework\TestCase;

use oihana\arango\models\Edges;
use oihana\models\notices\AfterDelete;
use oihana\models\notices\BeforeDelete;
use org\schema\helpers\SchemaResolver;

/**
 * Light integration tests for {@see \oihana\arango\models\traits\edges\EdgesDeleteTrait}.
 *
 * The bug they protect against:
 * `deleteEdge()` and `deleteEdges()` execute an AQL `REMOVE` against the
 * collection but never emit `beforeDelete` / `afterDelete`. Listeners
 * (e.g. `CasbinPolicySync::onEdgeDelete`) therefore never react to detach
 * operations, leaving Casbin groupings/policies orphaned in `rbac`.
 *
 * The tests use a minimal `Edges` subclass that bypasses the DI container and
 * the ArangoDB driver: only `getDocuments()` is stubbed so the trait's logic
 * (filter building, bind preparation, signal emission) executes for real.
 */
final class EdgesDeleteTraitTest extends TestCase
{
    public function testDeleteEdgeEmitsBeforeAndAfterDeleteSignals() :void
    {
        $edges  = $this->createEdges( returnDocuments: [ [ '_key' => 'edge1' , '_from' => 'users/u1' , '_to' => 'roles/r1' ] ] ) ;
        $events = $this->captureSignals( $edges ) ;

        $result = $edges->deleteEdge( 'users/u1' , 'roles/r1' ) ;

        $this->assertCount( 1 , $events[ 'before' ] , 'beforeDelete must be emitted exactly once' ) ;
        $this->assertCount( 1 , $events[ 'after'  ] , 'afterDelete must be emitted exactly once' ) ;

        $beforePayload = $events[ 'before' ][ 0 ] ;
        $afterPayload  = $events[ 'after'  ][ 0 ] ;

        $this->assertInstanceOf( BeforeDelete::class , $beforePayload ) ;
        $this->assertInstanceOf( AfterDelete::class  , $afterPayload  ) ;

        $this->assertSame( $edges , $beforePayload->target ) ;
        $this->assertSame( $edges , $afterPayload->target  ) ;

        $this->assertSame( $result , $afterPayload->data , 'afterDelete payload must carry the OLD documents from the REMOVE' ) ;
    }

    public function testDeleteEdgesEmitsBeforeAndAfterDeleteSignals() :void
    {
        $edges  = $this->createEdges( returnDocuments: [ [ '_key' => 'edge1' , '_from' => 'users/u1' , '_to' => 'roles/r1' ] ] ) ;
        $events = $this->captureSignals( $edges ) ;

        $result = $edges->deleteEdges( 'users/u1' ) ;

        $this->assertCount( 1 , $events[ 'before' ] , 'beforeDelete must be emitted exactly once on bulk detach' ) ;
        $this->assertCount( 1 , $events[ 'after'  ] , 'afterDelete must be emitted exactly once on bulk detach'  ) ;

        $this->assertInstanceOf( BeforeDelete::class , $events[ 'before' ][ 0 ] ) ;
        $this->assertInstanceOf( AfterDelete::class  , $events[ 'after'  ][ 0 ] ) ;

        $this->assertSame( $result , $events[ 'after' ][ 0 ]->data ) ;
    }

    public function testDeleteEdgeEmitsAfterDeleteEvenWhenNoDocumentRemoved() :void
    {
        $edges  = $this->createEdges( returnDocuments: [] ) ;
        $events = $this->captureSignals( $edges ) ;

        $result = $edges->deleteEdge( 'users/u1' , 'roles/r1' ) ;

        $this->assertNull( $result ) ;
        $this->assertCount( 1 , $events[ 'before' ] ) ;
        $this->assertCount( 1 , $events[ 'after'  ] , 'afterDelete must still be emitted when REMOVE matched no edge' ) ;
    }

    public function testDeleteEdgeFromDelegatesAndEmitsSignals() :void
    {
        $edges  = $this->createEdges( returnDocuments: [ [ '_key' => 'edge1' , '_from' => 'users/u1' , '_to' => 'roles/r1' ] ] ) ;
        $events = $this->captureSignals( $edges ) ;

        $edges->deleteEdgeFrom( 'users/u1' ) ;

        $this->assertCount( 1 , $events[ 'before' ] , 'deleteEdgeFrom must propagate beforeDelete via deleteEdge' ) ;
        $this->assertCount( 1 , $events[ 'after'  ] , 'deleteEdgeFrom must propagate afterDelete via deleteEdge'  ) ;
    }

    public function testDeleteEdgeToDelegatesAndEmitsSignals() :void
    {
        $edges  = $this->createEdges( returnDocuments: [ [ '_key' => 'edge1' , '_from' => 'users/u1' , '_to' => 'roles/r1' ] ] ) ;
        $events = $this->captureSignals( $edges ) ;

        $edges->deleteEdgeTo( 'roles/r1' ) ;

        $this->assertCount( 1 , $events[ 'before' ] , 'deleteEdgeTo must propagate beforeDelete via deleteEdge' ) ;
        $this->assertCount( 1 , $events[ 'after'  ] , 'deleteEdgeTo must propagate afterDelete via deleteEdge'  ) ;
    }

    /**
     * Build a minimal Edges instance bypassing the DI container.
     *
     * @param array<int,array<string,mixed>> $returnDocuments Fake OLD documents the stubbed AQL execution returns.
     */
    private function createEdges( array $returnDocuments ) :Edges
    {
        return new class( $returnDocuments ) extends Edges
        {
            public string $lastQuery = '' ;
            public array  $lastBinds = [] ;

            public function __construct( public array $documentsToReturn = [] )
            {
                $this->collection = 'rbac_edges' ;
                $this->queryId    = 'qid_test' ;
                $this->initializeDeleteSignals() ;
            }

            public function getDocuments
            (
                string                                $query    ,
                array                                 $bindVars = []    ,
                array                                 $options  = []    ,
                bool                                  $raw      = false ,
                SchemaResolver|Closure|string|null    $schema   = null
            )
            :array
            {
                $this->lastQuery = $query ;
                $this->lastBinds = $bindVars ;
                return $this->documentsToReturn ;
            }
        } ;
    }

    /**
     * Connect listeners that record every BeforeDelete / AfterDelete payload.
     *
     * Returns an `ArrayObject` shared by reference with the listeners so the
     * caller observes the recorded payloads after each emit.
     */
    private function captureSignals( Edges $edges ) :ArrayObject
    {
        $events = new ArrayObject( [ 'before' => [] , 'after' => [] ] ) ;

        $edges->beforeDelete?->connect( function( BeforeDelete $payload ) use ( $events ) :void
        {
            $current = $events[ 'before' ] ;
            $current[] = $payload ;
            $events[ 'before' ] = $current ;
        }) ;

        $edges->afterDelete?->connect( function( AfterDelete $payload ) use ( $events ) :void
        {
            $current = $events[ 'after' ] ;
            $current[] = $payload ;
            $events[ 'after' ] = $current ;
        }) ;

        return $events ;
    }
}
