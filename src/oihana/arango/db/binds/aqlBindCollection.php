<?php

namespace oihana\arango\db\binds;

use oihana\exceptions\BindException;

/**
 * Binds a collection name to an AQL variable.
 *
 * In AQL, collections are bound using double `@` prefixes (e.g. `@@collection`).
 *
 * @param mixed       $value    The collection name to bind.
 * @param array       &$binds   The array of all existing bindings. It is updated by reference.
 * @param string|null $to       The bind variable name (without `@`). If `null`, one is auto-generated.
 * @param string|null $toPrefix The optional prefix to prepend the variable name (default 'c').
 *
 * @return string The formatted AQL collection bind variable (e.g. `'@@collection'`).
 *
 * @throws BindException If the provided variable name is invalid.
 *
 * @example
 * ```php
 * $binds = [];
 *
 * $collectionVar = aqlBindCollection('users', $binds);
 * // $collectionVar => '@@c_654321'
 * // $binds         => [ '@c_654321' => 'users' ]
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlBindCollection
(
    mixed  $value ,
    array &$binds     = []   ,
    ?string $to       = null ,
    ?string $toPrefix = null
)
:string
{
    return aqlBind( $value , $binds , $to , $toPrefix ?? 'c' , isCollection: true ) ;
}