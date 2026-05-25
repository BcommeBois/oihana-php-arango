<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Resolves a {@see Documents} instance from various types of input definitions.
 *
 * This helper function returns a {@see Documents} object from:
 * a direct instance, an array definition, a service name within a PSR-11 container,
 * or falls back to a provided default value.
 *
 * ### Behavior:
 * - If `$definition` is a {@see Documents} instance, it is returned as-is.
 * - If `$definition` is an array, the function looks for the `$key` (default: {@see Arango::DOCUMENTS}).
 * - If `$definition` is a non-empty string and `$container` contains a service with that name,
 * the corresponding service is fetched.
 * - If none of the above conditions are met, the `$default` value is returned.
 *
 * @param array|string|Documents|null $definition Input definition that may represent an `Documents` instance,
 *                                                an associative array containing one, or a container service name.
 * @param ContainerInterface|null     $container  Optional PSR-11 container used to resolve string service names.
 * @param string                      $key        Array key to look for when `$definition` is an array
 * @param Documents|null              $default    Default `Documents` instance to return if resolution fails.
 *                                                (defaults to {@see Arango::DOCUMENTS}).
 *
 * @return Documents|null Returns the resolved {@see Documents} instance or the default value if not found.
 *
 * @throws ContainerExceptionInterface If an error occurs while retrieving the service from the container.
 * @throws NotFoundExceptionInterface  If the service is not found in the container.
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\getDocuments;
 * use oihana\arango\models\Documents;
 * use oihana\arango\enums\Arango;
 * use Psr\Container\ContainerInterface;
 *
 * $docs = new Documents(['_key' => 'user_1']);
 *
 * // Example 1: Direct instance
 * $result = getDocuments($docs);
 * // → returns the same $docs instance
 *
 * // Example 2: From array definition
 * $result = getDocuments([Arango::DOCUMENTS => $docs]);
 * // → returns the $docs instance from the array
 *
 * // Example 3: From container service
 * $container->method('has')->willReturn(true);
 * $container->method('get')->willReturn($docs);
 * $result = getDocuments('my.documents.service', $container);
 * // → returns the $docs instance from the container
 *
 * // Example 4: Default fallback
 * $default = new Documents(['_key' => 'fallback']);
 * $result  = getDocuments(null, null, $default);
 * // → returns $default
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 * @version 1.0.0
 */
function getDocuments
(
    array|string|null|Documents $definition = null ,
    ?ContainerInterface         $container  = null ,
    string                      $key        = Arango::DOCUMENTS ,
    ?Documents                  $default    = null ,
)
:?Documents
{
    if( $definition instanceof Documents )
    {
        return $definition ;
    }

    if( is_array( $definition ) )
    {
        $definition = $definition[ $key ] ?? null ;
    }

    if( is_string( $definition ) && !empty( $definition) && $container?->has( $definition ) )
    {
        $definition = $container->get( $definition ) ;
    }

    return $definition instanceof Documents ? $definition : $default ;
}