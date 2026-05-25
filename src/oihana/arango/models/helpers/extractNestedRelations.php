<?php

namespace oihana\arango\models\helpers;

use Exception;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use Psr\Container\NotFoundExceptionInterface;
use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Extract nested edges and joins from a relation configuration or resolved model.
 *
 * This function can work in two modes:
 * 1. **Resolution mode**: Resolves target model from config via container, then extracts relations
 * 2. **Direct mode**: Extracts relations from an already-resolved target model
 *
 * In both modes, the function merges the extracted relations with any explicit
 * AQL::EDGES and AQL::JOINS defined in the relation configuration.
 *
 * **Resolution mode (for parseFilterSegment):**
 * ```php
 * $relations = extractNestedRelations(
 *     config: $edgeConfig,
 *     isEdge: true,
 *     container: $container
 * );
 * ```
 *
 * **Direct mode (for buildEdgeTraversal/buildJoinTraversal):**
 * ```php
 * $relations = extractNestedRelations(
 *     config: $edgeConfig,
 *     targetModel: $alreadyResolvedModel
 * );
 * ```
 *
 * @param array $config The relation configuration (edge or join config).
 * @param object|null $targetModel Optional: already-resolved target model.
 * @param bool|null $isEdge Required if $targetModel is null: true for edges, false for joins.
 * @param ContainerInterface|null $container Required if $targetModel is null: DI container.
 *
 * @return array{edges: array, joins: array} Associative array with 'edges' and 'joins' keys.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 */
function extractNestedRelations
(
    array               $config             ,
    ?object             $targetModel = null ,
    ?bool               $isEdge      = null ,
    ?ContainerInterface $container   = null ,
)
: array
{
    $edges = [] ;
    $joins = [] ;

    // Mode 1: Target model already resolved (direct extraction)
    if ( $targetModel !== null )
    {
        $edges = $targetModel->edges ?? [] ;
        $joins = $targetModel->joins ?? [] ;
    }
    // Mode 2: Need to resolve target model first
    else if ( $container !== null && $isEdge !== null )
    {
        // FOR EDGES: Resolve edge model and get target based on direction
        if ( $isEdge )
        {
            $edgeModel = getEdges( $config[ AQL::MODEL ] ?? null , $container ) ;

            if ( $edgeModel )
            {
                $direction      = $config[ AQL::DIRECTION ] ?? Traversal::OUTBOUND ;
                $resolvedTarget = $direction === Traversal::INBOUND ? $edgeModel->from : $edgeModel->to ;

                if ( $resolvedTarget )
                {
                    $edges = $resolvedTarget->edges ?? [] ;
                    $joins = $resolvedTarget->joins ?? [] ;
                }
            }
        }
        // FOR JOINS: Resolve join target model directly
        else
        {
            $model = $config[ AQL::MODEL ] ?? null ;

            if ( $model )
            {
                try
                {
                    $resolvedTarget = $container->get( $model ) ;

                    if ( $resolvedTarget )
                    {
                        $edges = $resolvedTarget->edges ?? [] ;
                        $joins = $resolvedTarget->joins ?? [] ;
                    }
                }
                catch ( Exception $e )
                {
                    // Model not found, continue with empty relations
                }
            }
        }
    }

    // Merge with explicit edges/joins from config (common to both modes)
    if ( isset( $config[ AQL::EDGES ] ) && is_array( $config[ AQL::EDGES ] ) )
    {
        $edges = array_merge( $edges , $config[ AQL::EDGES ] ) ;
    }

    if ( isset( $config[ AQL::JOINS ] ) && is_array( $config[ AQL::JOINS ] ) )
    {
        $joins = array_merge( $joins , $config[ AQL::JOINS ] ) ;
    }

    return
    [
        AQL::EDGES => $edges ,
        AQL::JOINS => $joins ,
    ] ;
}