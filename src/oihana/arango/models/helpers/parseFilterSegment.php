<?php

namespace oihana\arango\models\helpers;

use RuntimeException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Filter;
use oihana\arango\models\utils\FilterPath;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Parse a single path segment from hierarchical configuration.
 *
 * This function analyzes a filter path segment and determines its type (simple field, document,
 * array expansion, edge, or join). It validates the segment against the configuration and,
 * for edges and joins, resolves nested relations from target models.
 *
 * **Key Features:**
 * - Validates array notation consistency (e.g., `employee[*]` must be of type EDGES/JOINS/ARRAY_EXPANSION)
 * - Resolves nested edges/joins from target models for multi-level traversals
 * - Supports both explicit relation references (via AQL::RELATION) and implicit (segment key)
 * - Accumulates full path for better error reporting
 *
 * **Nested Relations Resolution:**
 * For edges, the function:
 * 1. Gets the edge model from the container
 * 2. Determines target model based on traversal direction (INBOUND → from, OUTBOUND → to)
 * 3. Extracts edges/joins from target model
 * 4. Merges with explicit nested relations from edge configuration
 *
 * For joins, the function:
 * 1. Gets the join target model from the container
 * 2. Extracts edges/joins from target model
 *
 * @param string                  $segment    Current path segment (e.g., "employee[*]", "address", "workLocation")
 * @param array                   $filters    Current level AQL::FILTERS configuration
 * @param array                   $edges      Available edges configuration at current level
 * @param array                   $joins      Available joins configuration at current level
 * @param array                   $parentPath Accumulated path from parent segments for error reporting
 * @param ContainerInterface|null $container  DI container for resolving target models and their relations
 *
 * @return FilterPath|null Parsed segment information with nested relations, or null if segment is not allowed
 *
 * @throws RuntimeException             If relation reference is not found in edges/joins configuration
 * @throws ContainerExceptionInterface  If container encounters an error resolving models
 * @throws NotFoundExceptionInterface   If target model is not found in container
 *
 * @example
 * ```php
 * // Simple field
 * $info = parseFilterSegment('email', ['email' => FilterType::STRING], [], [], []);
 * // → type: 'string', path: ['email'], nestedEdges: [], nestedJoins: []
 *
 * // Custom callable
 * $customFilter = fn($init, &$binds, $doc) => "LOWER($doc.name) == 'test'";
 * $info = parseFilterSegment('custom', ['custom' => $customFilter], [], [], []);
 * // → type: Closure, path: ['custom']
 *
 * // Edge with nested relations
 * $info = parseFilterSegment(
 *     'employee[*]',
 *     ['employee' => ['type' => Filter::EDGES, 'filters' => [...]]],
 *     ['employee' => ['model' => Models::EMPLOYEE_EDGE]],
 *     [],
 *     [],
 *     $container
 * );
 * // → type: 'edges', path: ['employee'], nestedEdges: [...from target model...], nestedJoins: [...]
 * ```
 */
function parseFilterSegment
(
    string              $segment           ,
    array               $filters           ,
    array               $edges      = []   ,
    array               $joins      = []   ,
    array               $parentPath = []   ,
    ?ContainerInterface $container  = null ,
)
:?FilterPath
{
    // Check for array notation
    $hasArrayNotation = str_contains( $segment , Operator::ARRAY_EXPANSION ) ;
    $cleanSegment     = str_replace(Operator::ARRAY_EXPANSION , '' , $segment ) ;

    $fullPath = [ ...$parentPath , $segment ] ;

    // Check if segment exists in configuration
    if ( !isset( $filters[ $cleanSegment ] ) )
    {
        return null ; // Not allowed
    }

    $config = $filters[ $cleanSegment ] ;

    // Simple type (leaf):
    // - String FilterType constant (e.g., FilterType::STRING)
    // - Callable/Closure for custom filters
    if ( is_string( $config ) || is_callable( $config ) )
    {
        return new FilterPath
        (
            type       : $config   ,
            path       : $fullPath ,
            typeConfig : $config   ,
        );
    }

    // Complex configuration
    if ( !is_array( $config ) )
    {
        return null ;
    }

    $type          = $config[ AQL::TYPE    ] ?? null ;
    $nestedFilters = $config[ AQL::FILTERS ] ?? null ;

    if ( !$type )
    {
        return null ;
    }

    // Validate array notation consistency
    $needsArray = in_array( $type , [ Filter::ARRAY_EXPANSION , Filter::EDGES , Filter::JOINS ] ) ;

    if ( $hasArrayNotation !== $needsArray )
    {
        return null ; // Mismatch
    }

    // Get relation reference for edges/joins
    $relationRef = null ;

    $nestedEdges = []   ;
    $nestedJoins = []   ;

    if ( in_array( $type , [ Filter::EDGE, Filter::EDGES, Filter::JOIN, Filter::JOINS ] ) )
    {
        $relationRef = $config[ AQL::RELATION ] ?? $cleanSegment;

        // Validate relation exists
        $isEdge      = in_array( $type , [ Filter::EDGE, Filter::EDGES ] ) ;
        $relationMap = $isEdge ? $edges : $joins ;

        if ( !isset( $relationMap[ $relationRef ] ) )
        {
            throw new RuntimeException
            (
                "Relation '{$relationRef}' not found in " .
                ( $isEdge ? 'edges' : 'joins' ) .
                " configuration"
            );
        }

        // Extract nested relations from target model using shared helper
        $relationConfig     = $relationMap[ $relationRef ] ;
        $extractedRelations = extractNestedRelations
        (
            config    : $relationConfig ,
            isEdge    : $isEdge         ,
            container : $container      ,
        ) ;

        $nestedEdges = $extractedRelations[ AQL::EDGES ] ;
        $nestedJoins = $extractedRelations[ AQL::JOINS ] ;
    }

    return new FilterPath
    (
        type          : $type ,
        path          : $fullPath ,
        typeConfig    : $type ,
        relationRef   : $relationRef ,
        nestedFilters : $nestedFilters ,
        nestedEdges   : $nestedEdges,
        nestedJoins   : $nestedJoins
    );
}