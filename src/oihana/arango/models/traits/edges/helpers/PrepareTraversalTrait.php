<?php

namespace oihana\arango\models\traits\edges\helpers;

use InvalidArgumentException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\VerticesTrait;
use oihana\enums\Char;
use oihana\models\traits\BindsTrait;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\operations\aqlWith;
use function oihana\arango\models\helpers\vertexID;

/**
 * Trait providing utility to prepare vertex traversal queries in ArangoDB.
 *
 * This trait standardizes the preparation of traversal parameters,
 * vertex IDs, bind variables, filters, and edge/graph references
 * for use in AQL queries.
 *
 * It is designed to be mixed into edge collection models
 * and can be used in conjunction with {@see ArangoTrait} and
 * {@see BindsTrait}.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait PrepareTraversalTrait
{
    use ArangoTrait ,
        BindsTrait ,
        VerticesTrait ;

    /**
     * Prepares traversal parameters and returns key elements required
     * to execute a vertex traversal query.
     *
     * This method validates the direction, computes the full vertex ID,
     * prepares bind variables, and sets default edge or graph collections
     * if not provided. It is a utility for methods that fetch or count
     * vertices via {@see Traversal} in an ArangoDB edge collection.
     *
     * @param string $direction The direction of traversal:
     *                          {@see Traversal::OUTBOUND},
     *                          {@see Traversal::INBOUND}, or
     *                          {@see Traversal::ANY}.
     * @param string|null $vertex Optional '_key' or '_id' of the vertex to start the traversal from.
     *                            If null, the default `$this->from` or `$this->to` is used
     *                            depending on the direction.
     * @param array{
     *     vertexRef?:       string,                             // Variable name for the vertex (default: "vertex").
     *     edgeRef?:         string|null,                        // Variable name for the edge (optional).
     *     pathRef?:         string|null,                        // Variable name for the path (optional).
     *     direction?:       string,                             // Traversal direction override (default: $direction).
     *     startVertex?:     string,                             // Computed vertex ID (output by this method).
     *     graph?:           string|null,                        // Graph name to traverse.
     *     edgeCollection?:  array|string|null,                  // Edge collections to traverse if no graph.
     *     minDepth?:        int|null,                           // Minimum traversal depth (default: 1).
     *     maxDepth?:        int|null,                           // Maximum traversal depth (default: 1).
     *     prune?:           string|array|null,                  // Condition to stop traversal early (PRUNE).
     *     options?:         array|object|string|null,           // Traversal options hydrated via {@see TraversalOptions}.
     *     filter?:          string|array|null,                  // Optional AQL FILTER expression(s).
     *     binds?:           array<string,mixed>,                // Bind variables for the query.
     *     from?:            string|null,                        // Default "from" collection/vertex.
     *     to?:              string|null,                        // Default "to" collection/vertex.
     *     anyRef?:          string|null,                        // Reference used for ANY traversals (default: AQL::FROM).
     *     docRef?:          string|null                          // Document variable name used in FOR (default: AQL::VERTEX).
     * } $init Optional reference to array of query initialization and configuration.
     *
     * @param-out array<string,mixed> $init The init array stays an open map: the method only
     *        adds keys by reference (direction, startVertex, edgeCollection), never removes any,
     *        so callers keep reading their own keys (target, sort, return, …) after the call.
     *
     * @return array{0: array<string,mixed>, 1: string|array|null, 2: string|null, 3: string|null, 4: string}
     *   Returns an array containing:
     *   0 => prepared bind variables,
     *   1 => filter expression(s),
     *   2 => "from" collection/vertex,
     *   3 => "to" collection/vertex.
     *   4 => resolved vertex ID,
     *
     * @throws ConstantException If the $direction is not a valid {@see Traversal} value.
     * @throws InvalidArgumentException If the resolved vertex ID is null or empty.
     *
     * @example
     * ```php
     * [$binds, $filter, $from, $to, $vertexId] = $this->prepareTraversal(
     *     Traversal::OUTBOUND,
     *     '123',
     *     ['graph' => 'my_graph']
     * );
     * ```
     */
    public function prepareTraversal
    (
        string  $direction ,
        ?string $vertex    = null ,
        array   &$init     = []
    )
    : array
    {
        Traversal::validate( $direction ) ;

        $anyRef   = $init[ AQL::ANY_REF ] ?? AQL::FROM ;
        $bindVars = $this->prepareBindVars( $init ) ;
        $filter   = $init[ AQL::FILTER  ] ?? null ;
        $from     = $init[ AQL::FROM    ] ?? $this->from ;
        $to       = $init[ AQL::TO      ] ?? $this->to ;

        $vertexId = match ( $direction )
        {
            Traversal::OUTBOUND => vertexID( $vertex , $from ) ,
            Traversal::INBOUND  => vertexID( $vertex , $to   ) ,
            Traversal::ANY      => vertexID( $vertex , $anyRef === AQL::TO ? $to : $from ),
        };

        if ( empty( $vertexId ) )
        {
            throw new InvalidArgumentException("Vertex ID cannot be null or empty for $direction traversal." ) ;
        }

        $init[ AQL::DIRECTION    ] = $direction ;
        $init[ AQL::START_VERTEX ] = $vertexId ;

        $edgeCollection = $init[ AQL::EDGE_COLLECTION ] ?? null ;
        $graph          = $init[ AQL::GRAPH           ] ?? null ;

        if ( empty( $graph ) || empty( $edgeCollection ) )
        {
            $init[ AQL::EDGE_COLLECTION ] = $this->collection ;
        }

        return [ $bindVars , $filter , $from , $to , $vertexId ] ;
    }

    /**
     * Derives the AQL `WITH` clause declaring the vertex collections reached by an
     * **anonymous** traversal (edge collection, no named graph).
     *
     * In a cluster, collections accessed dynamically by collection-set traversals must
     * be declared up front so they are all locked at query start, which avoids deadlocks.
     * Named-graph traversals already know their collections, so this returns an empty
     * string for them. The clause is harmless (a no-op) on a single server.
     *
     * Declared collections, by direction:
     * - {@see Traversal::OUTBOUND} → the `$to` vertex collection,
     * - {@see Traversal::INBOUND}  → the `$from` vertex collection,
     * - {@see Traversal::ANY}      → both `$from` and `$to` (de-duplicated).
     *
     * An explicit `AQL::WITH` entry in `$init` (a collection name or an array of names)
     * overrides the direction-based derivation.
     *
     * @param string         $direction Traversal direction ({@see Traversal}).
     * @param Documents|null $from      The `_from` vertex model (may be null).
     * @param Documents|null $to        The `_to` vertex model (may be null).
     * @param array          $init      The traversal init array (read-only here).
     *
     * @return string The `WITH coll1, coll2, ...` clause, or an empty string when there
     *                is nothing to declare (named graph, missing models, or explicit empty).
     *
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/with/
     */
    private function prepareTraversalWith
    (
        string     $direction ,
        ?Documents $from = null ,
        ?Documents $to   = null ,
        array      $init = []
    )
    :string
    {
        if ( !empty( $init[ AQL::GRAPH ] ) )
        {
            return Char::EMPTY ; // named graph: collections are already known
        }

        if ( isset( $init[ AQL::WITH ] ) )
        {
            $collections = (array) $init[ AQL::WITH ] ; // explicit override
        }
        else
        {
            $collections = [] ;

            // INBOUND reaches the `_from` collection, OUTBOUND the `_to` one, ANY both.
            if ( $direction === Traversal::INBOUND || $direction === Traversal::ANY )
            {
                $collections[] = $from?->collection ;
            }

            if ( $direction === Traversal::OUTBOUND || $direction === Traversal::ANY )
            {
                $collections[] = $to?->collection ;
            }
        }

        $collections = array_values( array_unique( array_filter
        (
            $collections ,
            fn( $collection ) => is_string( $collection ) && $collection !== Char::EMPTY
        ) ) ) ;

        return aqlWith( ...$collections ) ;
    }
}