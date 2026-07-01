<?php

namespace tests\oihana\arango\controllers\mocks;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * A test double for {@see \oihana\arango\models\Edges} that records the traversal
 * calls (method + id + init) and returns canned vertices — {@see \oihana\arango\models\Edges}
 * cannot be produced by PHPUnit's mock generator, so a hand-written double is used.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class RecordingTraversalEdges extends MockEdges
{
    /**
     * The recorded calls, each `[ method , id , init ]`.
     *
     * @var array<int,array{0:string,1:?string,2:array}>
     */
    public array $calls = [] ;

    /**
     * Canned return of {@see getFirstInboundVertex}.
     */
    public object|array|null $firstInbound = null ;

    /**
     * Canned return of {@see getFirstOutboundVertex}.
     */
    public object|array|null $firstOutbound = null ;

    /**
     * Canned return of {@see getInboundVertices}.
     */
    public object|array|null $inbound = [] ;

    /**
     * Canned return of {@see getOutboundVertices}.
     */
    public object|array|null $outbound = [] ;

    public function getFirstInboundVertex( ?string $to = null , array $init = [] ) :object|array|null
    {
        $this->calls[] = [ 'getFirstInboundVertex' , $to , $init ] ;
        return $this->firstInbound ;
    }

    public function getFirstOutboundVertex( ?string $from = null , array $init = [] ) :object|array|null
    {
        $this->calls[] = [ 'getFirstOutboundVertex' , $from , $init ] ;
        return $this->firstOutbound ;
    }

    public function getInboundVertices( ?string $to = null , array $init = [] ) :object|array|null
    {
        $this->calls[] = [ 'getInboundVertices' , $to , $init ] ;
        return $this->inbound ;
    }

    public function getOutboundVertices( ?string $from = null , array $init = [] ) :object|array|null
    {
        $this->calls[] = [ 'getOutboundVertices' , $from , $init ] ;
        return $this->outbound ;
    }
}
