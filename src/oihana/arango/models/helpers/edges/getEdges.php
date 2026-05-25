<?php

namespace oihana\arango\models\helpers\edges;

use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Retrieves an {@see Edges} instance from various types of input definitions.
 *
 * This helper function resolves an `Edges` object from a direct instance, an array definition,
 * a service name within a PSR-11 container, or falls back to a provided default value.
 *
 * ### Behavior:
 * - If `$definition` is an `Edges` instance, it is returned as-is.
 * - If `$definition` is an array, the function looks for the `Arango::EDGES` key.
 * - If `$definition` is a non-empty string and `$container` contains a service with that name,
 *   the corresponding service is fetched.
 * - If none of the above conditions are met, the `$default` value is returned.
 *
 * @param array|string|Edges|null $definition Input definition that may represent an `Edges` instance,
 *                                            an associative array containing one, or a container service name.
 * @param ContainerInterface|null $container  Optional PSR-11 container used to resolve string service names.
 * @param string                  $key        Array key to look for when `$definition` is an array
 * @param Edges|null              $default    Default `Edges` instance to return if resolution fails.
 *
 * @return Edges|null Returns the resolved `Edges` instance or the default value if not found.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\getEdges;
 * use oihana\arango\models\Edges;
 * use oihana\arango\enums\Arango;
 * use Psr\Container\ContainerInterface;
 *
 * $edges = new Edges(['_from' => 'users/1', '_to' => 'posts/5']);
 *
 * // Example 1: Direct instance
 * $result = getEdges($edges);
 * // → returns the same $edges instance
 *
 * // Example 2: From array definition
 * $result = getEdges([Arango::EDGES => $edges]);
 * // → returns the $edges instance from the array
 *
 * // Example 3: From container service name
 * $container->method('has')->willReturn(true);
 * $container->method('get')->willReturn($edges);
 * $result = getEdges('my.edges.service', $container);
 * // → returns the $edges instance resolved from the container
 *
 * // Example 4: With default fallback
 * $default = new Edges(['_from' => 'fallback/A', '_to' => 'fallback/B']);
 * $result = getEdges(null, null, $default);
 * // → returns $default
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function getEdges
(
    array|string|null|Edges $definition = null ,
    ?ContainerInterface     $container  = null ,
    string                  $key        = Arango::EDGES ,
    ?Edges                  $default    = null ,
)
:?Edges
{
    if( $definition instanceof Edges )
    {
        return $definition ;
    }

    if( is_array( $definition ) && array_key_exists( $key , $definition ) )
    {
        $definition = $definition[ $key ] ?? null ;
    }

    if( is_string( $definition ) && !empty( $definition ) && $container?->has( $definition ) )
    {
        $definition = $container->get( $definition ) ;
    }

    return $definition instanceof Edges ? $definition : $default ;
}