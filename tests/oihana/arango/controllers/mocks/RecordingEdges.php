<?php

namespace tests\oihana\arango\controllers\mocks;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * A test double for {@see \oihana\arango\models\Edges} recording the three calls
 * {@see \oihana\arango\controllers\EdgesController} makes — `existEdge()`,
 * `deleteEdge()` and `insertEdge()` — with the `$init` each one receives.
 *
 * The controller's whole seat is about **what reaches which call**, so the init is
 * what the tests assert; the canned returns only steer the branch taken.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class RecordingEdges extends MockEdges
{
    /**
     * The recorded calls, each `[ method , from , to , init ]`.
     *
     * @var array<int,array{0:string,1:?string,2:?string,3:array}>
     */
    public array $calls = [] ;

    /**
     * Canned return of {@see deleteEdge}.
     */
    public object|array|null $deleted = null ;

    /**
     * Canned answer of {@see existEdge}.
     */
    public bool $edgeExists = true ;

    /**
     * Canned return of {@see insertEdge}.
     */
    public ?object $inserted = null ;

    public function deleteEdge( ?string $from = null , ?string $to = null , array $init = [] ) :null|array|object
    {
        $this->calls[] = [ 'deleteEdge' , $from , $to , $init ] ;
        return $this->deleted ;
    }

    public function existEdge( ?string $from = null , ?string $to = null , array $init = [] ) :bool
    {
        $this->calls[] = [ 'existEdge' , $from , $to , $init ] ;
        return $this->edgeExists ;
    }

    public function insertEdge( string $from , string $to , array $doc = [] , array $init = [] ) :?object
    {
        $this->calls[] = [ 'insertEdge' , $from , $to , $init ] ;
        return $this->inserted ;
    }

    /**
     * The init of the first recorded call to the given method, or null.
     *
     * @return array<string,mixed>|null
     */
    public function initOf( string $method ) :?array
    {
        foreach ( $this->calls as $call )
        {
            if ( $call[ 0 ] === $method )
            {
                return $call[ 3 ] ;
            }
        }

        return null ;
    }
}
