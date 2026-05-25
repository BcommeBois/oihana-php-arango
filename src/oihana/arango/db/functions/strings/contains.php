<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use oihana\enums\Boolean;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\core\strings\func;

/**
 * Check whether a string contains a substring (case-sensitive).
 *
 * This helper wraps the ArangoDB AQL function `CONTAINS(text, search, returnIndex)`
 * which checks if the search string is contained within the text string.
 * The matching is case-sensitive by default.
 *
 * Example AQL usage:
 * ```aql
 * CONTAINS("Hello World", "World")        // returns true
 * CONTAINS("Hello World", "world")        // returns false (case-sensitive)
 * CONTAINS("Hello World", "World", true)  // returns 6 (position)
 * ```
 *
 * @param string $text The text to search in.
 * @param string $search The substring to search for.
 * @param bool $returnIndex When true, returns the position; when false, returns boolean.
 *
 * @return string The formatted AQL expression.
 *
 * @throws UnsupportedOperationException
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\contains;
 *
 * $expr = contains('doc.title', '"World"');
 * // Produces: 'CONTAINS(doc.title, "World")'
 *
 * $expr = contains('doc.title', '"World"', true);
 * // Produces: 'CONTAINS(doc.title, "World", true)'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#contains
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function contains( string $text , string $search , bool $returnIndex = false ): string
{
    return func(StringFunction::CONTAINS , [ aqlValue($text) , aqlValue($search) , $returnIndex ? Boolean::TRUE : Char::EMPTY ] ) ;
}

