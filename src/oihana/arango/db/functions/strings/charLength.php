<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the number of characters in a string (not byte length).
 *
 * This helper wraps the ArangoDB AQL function `CHAR_LENGTH(str)` which returns
 * the number of characters in a string, counting Unicode characters properly
 * rather than bytes.
 *
 * Example AQL usage:
 * ```aql
 * CHAR_LENGTH("hello")          // returns 5
 * CHAR_LENGTH("café")           // returns 4 (not 5 bytes)
 * CHAR_LENGTH(doc.title)        // returns character count of title
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\charLength;
 *
 * $expr = charLength('doc.title');
 * // Produces: 'CHAR_LENGTH(doc.title)'
 * ```
 *
 * @param string $expression String expression to count characters of.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#char_length
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function charLength( string $expression ) : string
{
    return func( StringFunction::CHAR_LENGTH , $expression ) ;
}

