<?php

namespace tests\oihana\arango\controllers\mocks;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

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

    /**
     * The recorded {@see prepareFilter} calls, each `[ urlFilter , docRef , hasAuthorizer ]`.
     *
     * @var array<int,array{0:mixed,1:string,2:bool}>
     */
    public array $filterCalls = [] ;

    /**
     * Canned AQL fragment returned by {@see prepareFilter} (null = nothing filterable).
     */
    public ?string $compiledFilter = null ;

    /**
     * Per-call canned fragments consumed in order (for the ?filter= + ?prune=
     * composition) ; falls back to {@see compiledFilter} once empty.
     *
     * @var array<int,?string>
     */
    public array $compiledFilterQueue = [] ;

    /**
     * Canned binds written back through {@see prepareFilter}'s `&$binds`.
     */
    public array $compiledBinds = [] ;

    /**
     * Records the compile call (the URL predicate, the target docRef and whether an
     * authorizer was forwarded) and returns the canned fragment/binds — the real
     * JSON→AQL engine is covered by {@see \oihana\arango\models\traits\aql\FilterTrait}
     * tests, so the double isolates the controller's wiring.
     */
    public function prepareFilter( ?array $init = [] , ?array &$binds = null , string $docRef = AQL::DOC , array $auth = [] ) :?string
    {
        $this->filterCalls[] =
        [
            $init[ Arango::FILTER ] ?? null ,
            $docRef ,
            ( $init[ Arango::AUTHORIZER ] ?? null ) !== null ,
        ] ;

        if ( $binds !== null )
        {
            $binds = $this->compiledBinds ;
        }

        if ( $this->compiledFilterQueue !== [] )
        {
            return array_shift( $this->compiledFilterQueue ) ;
        }

        return $this->compiledFilter ;
    }

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
