<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Remove all occurrences of multiple values from an array.
 *
 * This helper wraps the ArangoDB AQL function `REMOVE_VALUES(anyArray, values)`
 * which removes all occurrences of multiple specified values from an array.
 * The values parameter should be an array expression containing the values to remove.
 *
 * Example AQL usage:
 * ```aql
 * REMOVE_VALUES([1, 2, 3, 2, 4, 3], [2, 3])    // returns [1, 4]
 * REMOVE_VALUES(doc.tags, ["old", "deprecated"]) // removes multiple tags
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\removeValues;
 *
 * $expr = removeValues('doc.tags', '["old", "deprecated"]');
 * // Produces: 'REMOVE_VALUES(doc.tags, ["old", "deprecated"])'
 * ```
 *
 * @param string $anyArray Array expression to remove values from.
 * @param string $values Array expression containing values to remove.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#remove_values
 * @see removeValue() For removing a single value.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function removeValues( string $anyArray , string $values ) : string
{
    return func( ArrayFunction::REMOVE_VALUES , [ $anyArray , $values ] ) ;
}

