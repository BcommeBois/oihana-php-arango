<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use function oihana\core\strings\func;

/**
 * Reverse the elements of an array or characters in a string.
 *
 * This helper wraps the ArangoDB AQL function `REVERSE(expression)` which can
 * reverse both arrays and strings. For arrays, it reverses the order of elements.
 * For strings, it reverses the order of characters.
 *
 * Example AQL usage:
 * ```aql
 * REVERSE([1, 2, 3])          // returns [3, 2, 1]
 * REVERSE("hello")            // returns "olleh"
 * REVERSE(doc.items)          // reverses the order of items
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\reverse;
 *
 * $expr = reverse('[1, 2, 3]');
 * // Produces: 'REVERSE([1, 2, 3])'
 *
 * $expr = reverse('"hello"');
 * // Produces: 'REVERSE("hello")'
 * ```
 *
 * @param mixed $anyArray Array or string expression to reverse.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#reverse
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function reverse( mixed $anyArray ) : string
{
    return func( ArrayFunction::REVERSE , $anyArray ) ;
}

