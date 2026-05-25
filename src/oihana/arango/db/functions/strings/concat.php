<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;

/**
 * Concatenate multiple values into a single string.
 *
 * This helper wraps the ArangoDB AQL function `CONCAT(value1, value2, ... valueN)`
 * which concatenates multiple values into a single string. Values are automatically
 * converted to strings during concatenation.
 *
 * Example AQL usage:
 * ```aql
 * CONCAT("Hello", " ", "World")     // returns "Hello World"
 * CONCAT(doc.firstName, " ", doc.lastName)  // concatenates name parts
 * CONCAT("ID: ", doc._key)          // returns "ID: 123"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\concat;
 *
 * $expr = concat(['"Hello"', '" "', '"World"']);
 * // Produces: 'CONCAT("Hello", " ", "World")'
 *
 * $expr = concat('doc.firstName, " ", doc.lastName');
 * // Produces: 'CONCAT(doc.firstName, " ", doc.lastName)'
 * ```
 *
 * @param array|string|null $arguments An AQL string expression or an array of AQL values.
 * @return string The formatted AQL expression.
 * @throws UnsupportedOperationException
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#concat
 * @see concatSeparator() For concatenating with a separator.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function concat( array|string|null $arguments ): string
{
    if( is_array( $arguments ) )
    {
        $arguments = compile( array_map( fn( $value ) => aqlValue( $value ) , $arguments ) , Char::COMMA );
    }
    else if( is_string( $arguments ) )
    {
        $arguments = aqlValue( $arguments ) ;
    }
    elseif( is_null( $arguments ) )
    {
        $arguments = Char::EMPTY ;
    }
    return func(StringFunction::CONCAT , $arguments ) ;
}

