<?php

namespace oihana\arango\db\operations;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Traversal;
use oihana\arango\db\options\TraversalOptions;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\binds\aqlBind;

use function oihana\arango\db\binds\aqlBindCollection;
use function oihana\arango\db\helpers\isAQLId;
use function oihana\core\arrays\toArray;
use function oihana\core\strings\betweenQuotes;
use function oihana\core\strings\compile;

/**
 * Builds a full **AQL traversal clause** for ArangoDB queries.
 *
 * This helper constructs the canonical Arango Query Language (AQL) traversal expression,
 * optionally including depth ranges, edge collections or graph names, direction, and bind variables.
 *
 * It supports flexible initialization via the `$init` array, automatic bind variable injection,
 * and seamless hydration of traversal options via {@see TraversalOptions}.
 *
 * ### 🧩 Canonical Form
 *
 * ```aql
 * FOR <vertexRef>, <edgeRef>, <pathRef>
 * IN <minDepth>..<maxDepth> <direction> <startVertex>
 * GRAPH <graphName>
 * OPTIONS { ... }
 * ```
 *
 * or, when using edge collections instead of a graph:
 *
 * ```aql
 * FOR <vertexRef>, <edgeRef>, <pathRef>
 * IN <minDepth>..<maxDepth> <direction> <startVertex>
 * <edgeCollection1>, <edgeCollection2>, ...
 * ```
 *
 *
 * ### 🔒 Bind Variables
 *
 * If `$binds` is provided, both `AQL::GRAPH` and `AQL::START_VERTEX`
 * (and optionally `AQL::EDGE_COLLECTION`) are automatically bound using {@see aqlBind()},
 * ensuring safe, injection-free query generation.
 *
 * Example of secure binding:
 *
 * ```php
 * $binds = [];
 * $aql = aqlTraversal([
 * AQL::GRAPH        => 'socialGraph',
 * AQL::START_VERTEX => '@start',
 * AQL::DIRECTION    => Traversal::INBOUND
 * ], $binds);
 *
 * print_r($binds);
 * // ['@start' => 'users/123', '@graph' => 'socialGraph']
 * ```
 *
 * ### 💡 Usage Examples
 *
 * 1 - Simple graph traversal
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::GRAPH         => 'socialGraph',
 *     AQL::START_VERTEX  => 'users/123',
 * ]);
 * // FOR vertex IN OUTBOUND 'users/123' GRAPH 'socialGraph'
 * ```
 *
 * 2 - Traversal with edges and path references
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::VERTEX_REF    => 'v',
 *     AQL::EDGE_REF      => 'e',
 *     AQL::PATH_REF      => 'p',
 *     AQL::DIRECTION     => Traversal::INBOUND,
 *     AQL::GRAPH         => 'organization',
 *     AQL::START_VERTEX  => 'employees/42',
 * ]);
 * // FOR v, e, p IN INBOUND 'employees/42' GRAPH 'organization'
 * ```
 *
 * 3 - Depth-limited traversal
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::GRAPH         => 'socialGraph',
 *     AQL::START_VERTEX  => 'users/123',
 *     AQL::MIN_DEPTH     => 1,
 *     AQL::MAX_DEPTH     => 3,
 * ]);
 * // FOR vertex IN 1..3 OUTBOUND 'users/123' GRAPH 'socialGraph'
 * ```
 * 4 - Traversal using multiple edge collections
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::EDGE_COLLECTION => ['follows', 'likes'],
 *     AQL::START_VERTEX    => 'users/123',
 *     AQL::DIRECTION       => Traversal::OUTBOUND,
 * ]);
 * // FOR vertex IN OUTBOUND 'users/123' follows, likes
 * ```
 *
 * 5 - With PRUNE condition
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::GRAPH         => 'socialGraph',
 *     AQL::START_VERTEX  => 'users/123',
 *     AQL::PRUNE         => 'vertex.age < 18',
 * ]);
 * // FOR vertex IN OUTBOUND 'users/123' GRAPH 'socialGraph' PRUNE vertex.age < 18
 * ```
 *
 * 6 - With OPTIONS
 * ```php
 * echo aqlTraversal
 * ([
 *     AQL::GRAPH         => 'companyGraph',
 *     AQL::START_VERTEX  => 'departments/1',
 *     AQL::OPTIONS       => ['bfs' => true, 'uniqueVertices' => 'global'],
 * ]);
 * // FOR vertex IN OUTBOUND 'departments/1' GRAPH 'companyGraph' OPTIONS { "bfs": true, "uniqueVertices": "global" }
 * ```
 *
 * @param array{
 *    vertexRef?: string                  , // Variable name for vertex. Default: "vertex".
 *    edgeRef?: ?string                   , // Variable name for edge. Optional.
 *    pathRef?: ?string                   , // Variable name for path. Optional.
 *    direction?: string                  , // Traversal direction: OUTBOUND, INBOUND, or ANY. Default: Traversal::OUTBOUND.
 *    startVertex?: string                 , // Starting vertex (e.g. "users/123" or "@start").
 *    graph?: ?string                     , // Graph name to traverse.
 *    edgeCollection?: array|string|null  , // Edge collections (alternative to GRAPH).
 *    minDepth?: int|null                 , // Minimum traversal depth. Default: 1.
 *    maxDepth?: int|null                 , // Maximum traversal depth. Default: 1.
 *    prune?: string|array|null           , // Condition to stop traversal early (PRUNE clause).
 *    options?: array|object|string|null    // Traversal options hydrated via TraversalOptions.
 * }
 * $init Configuration for the traversal expression.
 *
 * @param array|null $binds Optional reference to a bind variable array; used for safe variable substitution.
 *
 * @return string The generated AQL traversal clause, or an empty string if input is invalid.
 *
 * @throws BindException       If a variable binding fails.
 * @throws ConstantException   If the direction is not a valid {@see Traversal} constant.
 * @throws ReflectionException If {@see aqlOptions()} hydration fails due to reflection issues.
 *
 * @since 1.0.0
 * @author Marc Alcaraz
 * @package oihana\arango\db\operations
 *
 * @see https://docs.arangodb.com/stable/aql/graphs/traversals/
 */
function aqlTraversal( array $init = [] , ?array &$binds = null ): string
{
    $graph          = $init[ AQL::GRAPH           ] ?? Char::EMPTY ;
    $startVertex    = $init[ AQL::START_VERTEX    ] ?? Char::EMPTY ;
    $edgeCollection = $init[ AQL::EDGE_COLLECTION ] ?? null ;

    if ( empty( $startVertex ) || ( empty( $graph ) && empty( $edgeCollection ) ) )
    {
        return Char::EMPTY ;
    }

    $direction = $init[ AQL::DIRECTION ] ?? Traversal::OUTBOUND ;

    Traversal::validate( $direction ) ;

    $vertexRef = $init[ AQL::VERTEX_REF ] ?? AQL::VERTEX ;
    $edgeRef   = $init[ AQL::EDGE_REF   ] ?? null ;
    $pathRef   = $init[ AQL::PATH_REF   ] ?? null ;
    $minDepth  = $init[ AQL::MIN_DEPTH  ] ?? null ;
    $maxDepth  = $init[ AQL::MAX_DEPTH  ] ?? null ;
    $prune     = $init[ AQL::PRUNE      ] ?? null ;

    if( is_string( $edgeCollection ) )
    {
        $edgeCollection = toArray( $edgeCollection ) ;
    }

    $hasBinds = is_array( $binds ) ;

    if( isAQLId( $startVertex ) )
    {
        $startVertex = $hasBinds ? aqlBind( $startVertex , $binds , AQL::START_VERTEX ) : betweenQuotes( $startVertex ) ;
    }

    if( !empty( $graph ) )
    {
        $graph = $hasBinds ? aqlBind( $graph  , $binds , AQL::GRAPH ) : betweenQuotes( $graph ) ;
    }
    else if ( !empty( $edgeCollection ) )
    {
        if( $hasBinds )
        {
            foreach ( $edgeCollection as $index => $collection )
            {
                $edgeCollection[ $index ] = aqlBindCollection
                (
                    value    : $collection ,binds : $binds ,
                    to       : AQL::EDGE_COLLECTION . Char::UNDERLINE . uniqid() ,
                    toPrefix : 'ec'
                );
            }
        }
    }

    $parts =
    [
        Operation::FOR,
        compile( [ $vertexRef , $edgeRef , $pathRef ] , Char::COMMA . Char::SPACE ) ,
        Comparator::IN ,
        aqlTraversalRange( $minDepth , $maxDepth , $binds ) ,
        $direction ,
        $startVertex
    ];

    if ( !empty( $graph ) )
    {
        $parts[] = Operation::GRAPH ;
        $parts[] = $graph ;
    }
    else if ( !empty( $edgeCollection ) )
    {
        $parts[] = compile( (array) $edgeCollection , Char::COMMA . Char::SPACE );
    }

    $parts[] = aqlPrune( $prune ) ;
    $parts[] = aqlOptions( $init , TraversalOptions::class ) ;

    return compile( $parts ) ;
}