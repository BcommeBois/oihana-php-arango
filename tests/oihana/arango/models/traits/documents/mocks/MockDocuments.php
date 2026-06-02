<?php

namespace tests\oihana\arango\models\traits\documents\mocks;

use Closure;

use DI\Container;

use oihana\arango\models\Documents;

use org\schema\helpers\SchemaResolver;

/**
 * Reusable test double for the document traits.
 *
 * It extends {@see Documents} but bypasses the DI container / ArangoDB driver
 * (same approach as EdgesDeleteTraitTest): the constructor only sets the few
 * properties the build/orchestration logic needs, and the three result-fetching
 * seams of ArangoTrait — getObject(), getDocuments(), getFirstResult() — are
 * overridden to capture the executed query + binds and return canned results.
 *
 * This exercises the real trait logic (query building, the count==1 vs N
 * branching, debug/mock short-circuits, return shaping) without any HTTP I/O.
 */
class MockDocuments extends Documents
{
    public function __construct( string $collection = 'users' )
    {
        $this->collection = $collection ;
        $this->container  = new Container() ;
        $this->queryId    = 'q' ;
        $this->debug      = false ;
        $this->mock       = false ;
    }

    /** The last AQL query passed to a fetch seam. */
    public string $lastQuery = '' ;

    /** The last bind variables passed to a fetch seam. */
    public array $lastBinds = [] ;

    /** Canned value returned by {@see getObject()}. */
    public ?object $objectResult = null ;

    /** Canned value returned by {@see getFirstResult()}. */
    public mixed $firstResult = null ;

    /** Canned value returned by {@see getDocuments()}. */
    public array $documentsResult = [] ;

    /**
     * Public proxy over the protected executeWriteOperation() so tests can reach
     * its validation guard directly.
     *
     * @param array  $init      The write configuration.
     * @param string $operation The write operation (UPDATE or REPLACE).
     *
     * @return ?object The canned object result.
     */
    public function callExecuteWriteOperation( array $init , string $operation ) :?object
    {
        return $this->executeWriteOperation( $init , $operation ) ;
    }

    /**
     * Captures the executed query/binds and returns the canned {@see $firstResult}.
     *
     * @param string                             $query    The AQL query the trait built.
     * @param array                              $bindVars The bind variables the trait built.
     * @param array                              $options  Cursor options (ignored by the double).
     * @param bool                               $raw      Whether to skip hydration (ignored).
     * @param null|SchemaResolver|Closure|string $schema   Hydration schema (ignored).
     *
     * @return mixed The canned first result.
     */
    public function getFirstResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :mixed
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->firstResult ;
    }

    /**
     * Captures the executed query/binds and returns the canned {@see $objectResult}.
     *
     * @param string                             $query    The AQL query the trait built.
     * @param array                              $bindVars The bind variables the trait built.
     * @param array                              $options  Cursor options (ignored by the double).
     * @param bool                               $raw      Whether to skip hydration (ignored).
     * @param null|SchemaResolver|Closure|string $schema   Hydration schema (ignored).
     *
     * @return ?object The canned object result.
     */
    public function getObject
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :?object
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->objectResult ;
    }

    /**
     * Captures the executed query/binds and returns the canned {@see $documentsResult}.
     *
     * @param string                             $query    The AQL query the trait built.
     * @param array                              $bindVars The bind variables the trait built.
     * @param array                              $options  Cursor options (ignored by the double).
     * @param bool                               $raw      Whether to skip hydration (ignored).
     * @param null|SchemaResolver|Closure|string $schema   Hydration schema (ignored).
     *
     * @return array The canned documents result.
     */
    public function getDocuments
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :array
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->documentsResult ;
    }
}
