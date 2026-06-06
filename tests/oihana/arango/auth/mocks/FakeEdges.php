<?php

namespace tests\oihana\arango\auth\mocks;

use Throwable;

use DI\Container;

use oihana\arango\models\Edges;

/**
 * Minimal {@see Edges} double for the Casbin-sync tests.
 *
 * Bypasses the DI container / ArangoDB driver and overrides the public
 * {@see list()} seam directly so each test can hand back canned edge rows
 * (and assert the conditions / binds that were issued). The `$from` / `$to`
 * vertex models stay null — their default — which is safe for the
 * `_from` / `_to` filter-based listing the sync traits perform.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class FakeEdges extends Edges
{
    /**
     * @param string $collection The edge collection name.
     */
    public function __construct( string $collection = 'role_has_permissions' )
    {
        $this->collection = $collection ;
        $this->container  = new Container() ;
        $this->queryId    = 'q' ;
        $this->debug      = false ;
        $this->mock       = false ;
    }

    /**
     * Canned rows returned by {@see list()} when {@see $listResolver} is null.
     *
     * @var array<int,object|array>
     */
    public array $listResult = [] ;

    /**
     * Optional resolver mapping the `$init` array to the rows to return —
     * use it when a single double must answer several different listings
     * (e.g. by bind value).
     *
     * @var callable(array):array|null
     */
    public $listResolver = null ;

    /**
     * Every `$init` array passed to {@see list()}, in call order.
     *
     * @var array<int,array>
     */
    public array $listCalls = [] ;

    /**
     * When set, {@see list()} throws it instead of returning — exercises the
     * caller's catch path.
     */
    public ?Throwable $listThrows = null ;

    /**
     * Every `insertEdge()` call as `[ from , to ]`, in call order.
     *
     * @var array<int,array{0:string,1:string}>
     */
    public array $insertEdgeCalls = [] ;

    /**
     * Optional resolver mapping a `[ from , to ]` pair to a Throwable to
     * raise from {@see insertEdge()} — use it to simulate an Error409 on a
     * specific edge.
     *
     * @var callable(string,string):?Throwable|null
     */
    public $insertEdgeThrowResolver = null ;

    /**
     * Records the call and optionally raises a per-edge Throwable.
     *
     * @param string $from
     * @param string $to
     * @param array  $doc
     * @param array  $init
     *
     * @return object|null Always null (no persistence).
     *
     * @throws Throwable When {@see $insertEdgeThrowResolver} returns one.
     */
    public function insertEdge( string $from , string $to , array $doc = [] , array $init = [] ) :?object
    {
        $this->insertEdgeCalls[] = [ $from , $to ] ;

        if( $this->insertEdgeThrowResolver !== null )
        {
            $throwable = ( $this->insertEdgeThrowResolver )( $from , $to ) ;

            if( $throwable !== null )
            {
                throw $throwable ;
            }
        }

        return null ;
    }

    /**
     * Records the call and returns the canned rows.
     *
     * @param array $init The listing init array.
     *
     * @return array
     *
     * @throws Throwable When {@see $listThrows} is set.
     */
    public function list( array $init = [] ) :array
    {
        $this->listCalls[] = $init ;

        if( $this->listThrows !== null )
        {
            throw $this->listThrows ;
        }

        if( $this->listResolver !== null )
        {
            return ( $this->listResolver )( $init ) ;
        }

        return $this->listResult ;
    }
}
