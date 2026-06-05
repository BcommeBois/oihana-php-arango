<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use oihana\enums\Boolean;
use oihana\enums\Char;
use function oihana\core\strings\func;

/**
 * Check whether a pattern matches a string using wildcard matching.
 *
 * This helper wraps the ArangoDB AQL function `LIKE(text, search, caseInsensitive)`
 * which checks if the text matches the search pattern using wildcard characters.
 * The pattern supports wildcards: _ (single character) and % (multiple characters).
 *
 * Example AQL usage:
 * ```aql
 * LIKE("hello", "h%")           // returns true
 * LIKE("hello", "h_llo")        // returns true
 * LIKE("hello", "world")        // returns false
 * LIKE("Hello", "h%", true)     // returns true (case-insensitive)
 * LIKE("hello", "\\_")          // returns false (literal underscore)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\like;
 *
 * echo like('doc.name', '"John%"');        // LIKE(doc.name,"John%")        case-sensitive
 * echo like('doc.name', '"john%"', true);  // LIKE(doc.name,"john%",true)   case-insensitive
 * ```
 *
 * @param string $text          The text to search in.
 * @param string $search        The search pattern with wildcards (_ and %).
 * @param bool   $caseInsensitive When true, matching is case-insensitive (maps to AQL's
 *                                third `caseInsensitive` argument). Default false = case-sensitive.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#like
 * @see contains() For simple substring matching.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function like( string $text , string $search , bool $caseInsensitive = false ): string
{
    return func(StringFunction::LIKE , [ $text , $search , $caseInsensitive ? Boolean::TRUE : Char::EMPTY ] ) ;
}

