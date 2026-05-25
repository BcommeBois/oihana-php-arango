<?php

namespace oihana\arango\models\helpers\edges;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\controllers\helpers\resolveDependency;
use function oihana\core\arrays\isIndexed;

/**
 * Resolves and initializes internal edge model definitions from a dependency container.
 *
 * This helper ensures that all edge-related dependencies defined in the model configuration
 * (under `AQL::EDGES`) are properly instantiated in the DI container.
 *
 * It supports multiple edge definition formats:
 * - **Associative arrays** mapping a property to its edge configuration.
 * - **Indexed arrays** containing simple string references to container entries.
 * - The special key **`AQL::RESOLVE`** (or `__resolve__`) for one-shot dependency resolution,
 * used to pre-load edge models in memory without explicit configuration.
 *
 * Typical usage:
 * ```php
 * resolveEdges( $model[AQL::EDGES] ?? [], $container );
 * ```
 *
 * Behavior:
 * - Each string identifier found in indexed or `AQL::RESOLVE` arrays is resolved through the container.
 * - Associative definitions are analyzed to resolve their `AQL::MODEL` entry if it refers to a container ID.
 * - Existing `Edges` instances are left untouched.
 *
 * @param array|null     $edges     The array of edge definitions to resolve (may be associative or indexed).
 * @param Container|null $container The DI container used to resolve `Edges` references.
 *
 * @return void
 *
 * @throws DependencyException           If a dependency cannot be loaded by the DI container.
 * @throws NotFoundException             If a referenced container entry is not found.
 * @throws ContainerExceptionInterface   If the container encounters a general error while resolving.
 * @throws NotFoundExceptionInterface    If a referenced entry is missing in a PSR-11 container.
 */
function resolveEdges
(
    ?array     &$edges    = [] ,
    ?Container $container = null ,
)
:void
{
    if ( empty( $edges ) || !$container )
    {
        return;
    }

    // ---- Handle special "__resolve__" entries first ----

    $resolve = $edges[ AQL::RESOLVE ] ?? null ;

    if( is_array( $resolve ) && isIndexed( $resolve ) )
    {
        foreach ( $resolve as $dependency )
        {
            resolveDependency( $dependency , $container ) ;
        }
        unset( $edges[ AQL::RESOLVE ] ) ; // remove the special key after resolution
    }

    $isIndexed = isIndexed( $edges ) ;

    foreach( $edges as $key => $definition )
    {
        // ---- Simple container references in indexed arrays ----

        if ( $isIndexed && is_string( $definition ) )
        {
            resolveDependency( $definition , $container ) ;
            unset( $edges[ $key ] );
            continue ;
        }

        // ---- Shortcut references in associative array ----

        if ( is_string( $definition ) && isset( $edges[ $definition ] ) )
        {
            $definition = $edges[ $definition ] ;
        }

        // ---- Resolve AQL::MODEL if needed ----

        if ( is_array( $definition ) && !empty( $definition ) )
        {
            $entry = resolveDependency( $definition[ AQL::MODEL ] ?? null , $container ) ;
            if ( $entry instanceof Edges )
            {
                $definition[ AQL::MODEL ] = $entry ;
                     $edges[ $key       ] = $definition ;
            }
        }
    }
}
