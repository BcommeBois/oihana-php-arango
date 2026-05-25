<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;

/**
 * Concatenate strings using a separator.
 *
 * This helper wraps the ArangoDB AQL function `CONCAT_SEPARATOR(separator, value1, value2, ... valueN)`
 * which concatenates multiple values into a single string using the specified separator
 * between each value.
 *
 * Example AQL usage:
 * ```aql
 * CONCAT_SEPARATOR(", ", "a", "b", "c")     // returns "a, b, c"
 * CONCAT_SEPARATOR(" - ", doc.first, doc.last)  // returns "John - Doe"
 * CONCAT_SEPARATOR("", "a", "b", "c")       // returns "abc" (no separator)
 * ```
 *
 * @param string $separator The separator string to use between values.
 * @param array|string|null $arguments An AQL string expression or an array of AQL values.
 * @return string The formatted AQL expression.
 *
 * @throws UnsupportedOperationException
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\concatSeparator;
 *
 * $expr = concatSeparator('", "', ['"a"', '"b"', '"c"']);
 * // Produces: 'CONCAT_SEPARATOR(", ", "a", "b", "c")'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#concat_separator
 * @see concat() For concatenating without a separator.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function concatSeparator( string $separator = Char::EMPTY , array|string|null $arguments = null ): string
{
    $arguments = $arguments ?? [] ;

    if( is_array( $arguments ) )
    {
        $arguments = array_map( fn( $value ) => aqlValue( $value ) , $arguments ) ;
    }

    return func( StringFunction::CONCAT_SEPARATOR , compile( [ aqlValue($separator) , $arguments ] , Char::COMMA ) ) ;
}

