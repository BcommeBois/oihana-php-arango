<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Remove all occurrences of a value from an array.
 *
 * This helper wraps the ArangoDB AQL function `REMOVE_VALUE(anyArray, value, limit)`
 * which removes all occurrences of a specified value from an array. An optional
 * limit can be specified to limit the number of removals.
 *
 * Example AQL usage:
 * ```aql
 * REMOVE_VALUE([1, 2, 3, 2, 4], 2)        // returns [1, 3, 4]
 * REMOVE_VALUE([1, 2, 3, 2, 4], 2, 1)     // returns [1, 3, 2, 4] (only first occurrence)
 * REMOVE_VALUE([1, 2, 3], 5)               // returns [1, 2, 3] (value not found)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\removeValue;
 *
 * $expr = removeValue('doc.tags', 'deprecated');
 * // Produces: 'REMOVE_VALUE(doc.tags, "deprecated")'
 *
 * $expr = removeValue('doc.tags', 'deprecated', 1);
 * // Produces: 'REMOVE_VALUE(doc.tags, "deprecated", 1)'
 * ```
 *
 * @param string $anyArray Array expression to remove value from.
 * @param mixed $value Value to remove from the array.
 * @param int|null $limit Optional limit for number of removals (null = no limit).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#remove_value
 * @see removeValues() For removing multiple values at once.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function removeValue( string $anyArray , mixed $value , ?int $limit ) : string
{
    return func( ArrayFunction::REMOVE_VALUES , [ $anyArray , $value , $limit > 0 ? $limit : Char::EMPTY ] ) ;
}

