<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return the Soundex fingerprint of a value.
 *
 * This helper wraps the ArangoDB AQL function `SOUNDEX(value)` which returns
 * the Soundex fingerprint of a string. Soundex is a phonetic algorithm for
 * indexing names by sound, as pronounced in English.
 *
 * Example AQL usage:
 * ```aql
 * SOUNDEX("Smith")              // returns "S530"
 * SOUNDEX("Smyth")              // returns "S530" (same as Smith)
 * SOUNDEX("Johnson")            // returns "J525"
 * SOUNDEX(doc.name)             // returns Soundex code of name
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\soundex;
 *
 * $expr = soundex('doc.name');
 * // Produces: 'SOUNDEX(doc.name)'
 * ```
 *
 * @param string $value String expression to calculate Soundex for.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#soundex
 * @see https://en.wikipedia.org/wiki/Soundex
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function soundex( string $value ): string
{
    return func(StringFunction::SOUNDEX , $value  ) ;
}

