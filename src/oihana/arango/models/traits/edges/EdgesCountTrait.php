<?php

namespace oihana\arango\models\traits\edges;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\edges\helpers\PrepareTraversalTrait;
use oihana\arango\models\traits\VerticesTrait;

use oihana\exceptions\BindException;

use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlCollect;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;

/**
 * Provides utilities for counting relationships (edges) and unique neighbors (vertices)
 * in an ArangoDB edge collection.
 *
 * This trait extends {@see ArangoTrait} and {@see VerticesTrait} and offers
 * two distinct counting strategies:
 *
 * #### 1. Edge Counting (via `countEdges`)

 * **What it does:**
 * Counts the *actual edge documents* that match a filter
 * (e.g., `_from == 'A' AND _to == 'B'`). It uses `RETURN LENGTH(FOR ... FILTER ...)`
 *
 * **Use case:**
 * Answers "How many *times* does relation X exist?"
 *
 * **Example:**
 * If 'user/1' follows 'user/2' twice (two edge documents),
 * `countEdges('user/1', 'user/2')` will return `2`.
 *
 * #### 2. Vertex Counting (via `countVertices`, `countOutboundVertices`, etc.)
 *
 * **What it does:**
 * Counts the *unique vertex documents* reached by a traversal
 * (`OUTBOUND`, `INBOUND`, `ANY`). It uses `FOR ... COLLECT WITH COUNT`.
 *
 * **Use case:**
 * Answers "How many *unique neighbors* does vertex X have?"
 *
 * **Example:**
 *
 * If 'user/1' follows 'user/2' twice,
 * `countOutboundVertices('user/1')` will return `1` (as 'user/2' is one unique vertex).
 *
 * ### Usage
 *
 * ```php
 * $edges = new Edges($container, ['collection' => 'user_follows']);
 *
 * // How many edge documents connect 'users/1' to 'posts/5'?
 * $edgeCount = $edges->countEdges('users/1', 'posts/5');
 *
 * // How many unique users does 'users/1' follow?
 * $vertexCount = $edges->countOutboundVertices('users/1');
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait EdgesCountTrait
{
    use PrepareTraversalTrait ;

    /**
     * Counts all unique vertices connected in any direction from the given vertex.
     *
     * This is a convenience method for `countVertices(Traversal::ANY, ...)`.
     * It counts unique *neighbors* (vertices), not *relations* (edges).
     *
     * @param string|null $vertex Optional '_key' or '_id' of the vertex to start from.
     * @param array $init Optional query initialization array (e.g., `AQL::GRAPH`).
     *
     * @return int The total count of unique connected vertices.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function countAnyVertices( ?string $vertex = null , array $init = [] ) : int
    {
        return $this->countVertices( Traversal::ANY , $vertex , $init ) ;
    }

    /**
     * Counts the number of edge documents matching the specified vertices.
     *
     * This counts the *relations* (edges), not the unique neighbors.
     *
     * @param string|null $from Optional '_from' vertex identifier.
     * @param string|null $to   Optional '_to' vertex identifier.
     * @param array       $init Optional query options (e.g., `AQL::BINDS`, `operator` => `Logic::OR`).
     *
     * @return int The total count of matching edge documents.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function countEdges
    (
        ?string $from = null ,
        ?string $to   = null ,
        array   $init = []
    )
    :int
    {
        $binds = $init[ AQL::BINDS ] ?? [] ;
        $query = $this->countEdgesQuery( $from , $to , $binds , $init ) ;
        return $this->getFirstResult( $query , $binds ) ;
    }

    /**
     * Counts all unique inbound vertices connected to the given 'to' vertex.
     *
     * This is a convenience method for `countVertices(Traversal::INBOUND, ...)`.
     * It counts unique *neighbors* (vertices), not *relations* (edges).
     *
     * @param string|null $to   Optional '_key' or '_id' of the vertex to traverse to.
     * @param array       $init Optional query initialization array (e.g., `AQL::GRAPH`).
     *
     * @return int The total count of unique inbound vertices.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function countInboundVertices( ?string $to = null , array $init = [] ) : int
    {
        return $this->countVertices( Traversal::INBOUND , $to , $init ) ;
    }

    /**
     * Counts all unique outbound vertices connected from the given 'from' vertex.
     *
     * This is a convenience method for `countVertices(Traversal::OUTBOUND, ...)`.
     * It counts unique *neighbors* (vertices), not *relations* (edges).
     *
     * @param string|null $from Optional '_key' or '_id' of the vertex to start from.
     * @param array       $init Optional query initialization array (e.g., `AQL::GRAPH`).
     *
     * @return int The total count of unique outbound vertices.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function countOutboundVertices( ?string $from = null , array $init = [] ) : int
    {
        return $this->countVertices( Traversal::OUTBOUND , $from , $init ) ;
    }

    /**
     * Counts all vertices connected with a specific direction.
     *
     * This counts the destination vertex documents.
     *
     * @param string $direction The direction of the relation: {@see Traversal::OUTBOUND}, {@see Traversal::INBOUND}, or {@see Traversal::ANY}.
     * @param string|null $vertex Optional '_key' or '_id' of the vertex to start the relation from.
     * @param array{
     *     vertexRef?:       string ,                             // Variable name for the vertex. Default: "vertex".
     *     edgeRef?:         string|null ,                        // Variable name for the edge (optional).
     *     pathRef?:         string|null ,                        // Variable name for the path (optional).
     *     direction?:       string ,                             // Traversal direction (OUTBOUND, INBOUND, ANY). Default: Traversal::OUTBOUND.
     *     startVertex?:     string ,                             // Starting vertex (can be bound with @param). Required.
     *     graph?:           string|null ,                        // Graph name to traverse. Required if no EDGE_COLLECTION.
     *     edgeCollection?:  array|string|null ,                  // Edge collections (alternative to graph traversal).
     *     minDepth?:        int|null ,                           // Minimum depth of traversal. Default: 1.
     *     maxDepth?:        int|null ,                           // Maximum depth of traversal. Default: 1.
     *     prune?:           string|array|null ,                  // Condition to stop traversal early (PRUNE ...).
     *     options?:         array|object|string|null ,           // Traversal options, hydrated via {@see TraversalOptions}.
     *     filter?:          string|array|null ,                  // Optional AQL FILTER expression(s).
     *     binds?:           array<string,mixed> ,                // Bind variables to inject into the query.
     *     from?:            string|null ,                        // Default collection or vertex "from".
     *     to?:              string|null ,                        // Default collection or vertex "to".
     *     anyRef?:          string|null ,                        // Reference used for ANY traversals (default: AQL::FROM).
     *     docRef?:          string|null                          // Document variable name used in FOR (default: AQL::VERTEX).
     * } $init Optional query initialization and configuration array.
     *
     * @return int The total count of matching vertices.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function countVertices
    (
        string  $direction ,
        ?string $vertex    = null ,
        array   $init      = []
    ) : int
    {
        [ $bindVars , $filter ] = $this->prepareTraversal( $direction , $vertex , $init ) ;

        $query = compile
        ([
            aqlTraversal ( $init , $bindVars ) ,
            aqlFilter    ( $filter ) ,
            aqlCollect   ( [ AQL::WITH_COUNT => AQL::LENGTH ] ) , // COLLECT WITH COUNT INTO length
            aqlReturn    ( AQL::LENGTH ) ,
        ]) ;

        // echo 'countVertices query    : ' . $query . PHP_EOL ;
        // echo 'countVertices bindVars : ' . json_encode( $bindVars , JSON_UNESCAPED_SLASHES ) . PHP_EOL ;

        return $this->getFirstResult( $query , $bindVars ) ;
    }

    // ---------- protected

    /**
     * Generates the count query and fill the binds array reference.
     *
     * @param ?string $from  The from vertex identifier
     * @param ?string $to    The to vertex identifier
     * @param array   $binds The bindVars array reference.
     * @param array   $init  The option of the method.
     *  - 'collection'   : The name of the collection, by default use the $this->collection property.
     *  - 'name'         : The name of the bindVariable collection, by default use the `@@collection`.
     *  - 'operator'     : Indicates if the filter of the vertices use a {@see Logic::AND} or {@see Logic::OR} operator (default {@see Logic::AND})
     *  - 'variableName' : The name of the document in the query (default 'doc') {@see AQL::DOC_REF}
     *
     * @return string The AQL query expression.
     *
     * @throws BindException
     * @throws ReflectionException
     *
     * @example
     * ```php
     * [ $query , $binds ] = $this->countEdgeQuery( $from , $to ) ;
     * ```
     */
    protected function countEdgesQuery
    (
        ?string $from   = null ,
        ?string $to     = null ,
        array   &$binds = []   ,
        array   $init   = []
    )
    :string
    {
        // RETURN LENGTH( FOR doc IN @@collection FILTER doc._from == @from RETURN 1 )
        $binds = $init[ AQL::BINDS ] ?? [] ;
        return aqlReturn
        ([
            length
            ([
                aqlFor    ( [ ...$init , AQL::IN => $this->bindCollection( $binds , $init ) ] ) ,
                aqlFilter ( $this->prepareVertices( $from , $to , $binds , $init ) ) ,
                aqlReturn ( 1 ) ,
            ])
        ]) ;
    }
}