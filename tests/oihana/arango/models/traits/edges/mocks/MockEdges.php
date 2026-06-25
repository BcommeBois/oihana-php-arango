<?php

namespace tests\oihana\arango\models\traits\edges\mocks;

use Closure;

use DI\Container;

use oihana\arango\models\Edges;

use org\schema\helpers\SchemaResolver;

/**
 * Reusable test double for the edge traits.
 *
 * Mirrors MockDocuments but extends {@see Edges}: it bypasses the DI container /
 * ArangoDB driver and overrides the three ArangoTrait fetch seams (getObject,
 * getDocuments, getFirstResult) to capture the executed query + binds and return
 * canned results. The `$from` / `$to` vertex models stay null (their default),
 * which is enough for the `_from` / `_to` filter-based edge methods.
 */
class MockEdges extends Edges
{
    public function __construct( string $collection = 'follows' )
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
        array                              $context  = [] ,
    )
    :array
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->documentsResult ;
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
        array                              $context  = [] ,
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
        array                              $context  = [] ,
    )
    :?object
    {
        $this->lastQuery = $query ;
        $this->lastBinds = $bindVars ;
        return $this->objectResult ;
    }
}
