<?php

namespace tests\oihana\arango\auth\mocks;

use Throwable;

use DI\Container;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

/**
 * Minimal {@see Documents} double for the Casbin-sync tests.
 *
 * Bypasses the DI container / ArangoDB driver and overrides the public
 * {@see get()} seam directly (rather than the lower-level ArangoTrait
 * fetch seams) so each test can map a `_key` to a canned document and
 * assert which key was requested. Query building itself is already
 * covered by the document-trait suite, so it is short-circuited here.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class FakeDocuments extends Documents
{
    /**
     * Canned documents keyed by their `_key` (the `Arango::VALUE` of the get call).
     *
     * @var array<string,object|null>
     */
    public array $getResults = [] ;

    /**
     * Every `$init` array passed to {@see get()}, in call order.
     *
     * @var array<int,array>
     */
    public array $getCalls = [] ;

    /**
     * When set, {@see get()} throws it instead of returning — exercises the
     * caller's catch path.
     */
    public ?Throwable $getThrows = null ;

    /**
     * Every `$init` array passed to {@see list()}, in call order.
     *
     * @var array<int,array>
     */
    public array $listCalls = [] ;

    /**
     * Canned rows returned by {@see list()}.
     *
     * @var array<int,object|array>
     */
    public array $listResult = [] ;

    /**
     * When set, {@see list()} throws it instead of returning.
     */
    public ?Throwable $listThrows = null ;

    /**
     * Every `$init` array passed to {@see update()}, in call order.
     *
     * @var array<int,array>
     */
    public array $updateCalls = [] ;

    /**
     * When set, {@see update()} throws it instead of returning.
     */
    public ?Throwable $updateThrows = null ;

    /**
     * @param string $collection The collection name.
     */
    public function __construct( string $collection = 'permissions' )
    {
        $this->collection = $collection ;
        $this->container  = new Container() ;
        $this->queryId    = 'q' ;
        $this->debug      = false ;
        $this->mock       = false ;
    }

    /**
     * Records the call and returns the canned document mapped to the
     * requested `Arango::VALUE` key (null when absent).
     *
     * @param array $init The lookup init array.
     *
     * @return object|null
     *
     * @throws Throwable When {@see $getThrows} is set.
     */
    public function get( array $init = [] ) :?object
    {
        $this->getCalls[] = $init ;

        if( $this->getThrows !== null )
        {
            throw $this->getThrows ;
        }

        $key = $init[ Arango::VALUE ] ?? null ;

        return $this->getResults[ $key ] ?? null ;
    }

    /**
     * Records the call and returns the canned {@see $listResult}.
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

        return $this->listResult ;
    }

    /**
     * Records the call (no persistence) and returns null.
     *
     * @param array $init The update init array.
     *
     * @return object|null
     *
     * @throws Throwable When {@see $updateThrows} is set.
     */
    public function update( array $init = [] ) :?object
    {
        $this->updateCalls[] = $init ;

        if( $this->updateThrows !== null )
        {
            throw $this->updateThrows ;
        }

        return null ;
    }
}
