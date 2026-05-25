<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Get the number of elements in an array, object, or collection.
 *
 * This helper wraps the ArangoDB AQL function `LENGTH(expression)` which returns
 * the number of elements in arrays, the number of attributes in objects, or
 * the number of documents in collections.
 *
 * Example AQL usage:
 * ```aql
 * LENGTH([1, 2, 3, 4])        // returns 4
 * LENGTH(doc.items)           // returns number of items
 * LENGTH({a: 1, b: 2})       // returns 2 (object attributes)
 * LENGTH(collection)          // returns number of documents
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\length;
 *
 * $expr = length('doc.tags');
 * // Produces: 'LENGTH(doc.tags)'
 * ```
 *
 * @param mixed $expression Array, object, or collection expression to measure.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#length
 * @see count() For the equivalent COUNT function.
 *
 * @package oihana\arango\db\functions\arrays
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function length( mixed $expression ) : string
{
    return func( ArrayFunction::LENGTH , $expression , Char::SPACE ) ;
}

