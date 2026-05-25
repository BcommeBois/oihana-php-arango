<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Removes whitespace or specific characters from the start and/or end of a string.
 *
 * This helper wraps the ArangoDB AQL function `TRIM(value, chars)` which removes
 * whitespace characters from both ends of a string. You can specify custom
 * characters to remove instead of the default whitespace.
 *
 * 1. **Whitespace mode (numeric `type` argument)**
 * When the second parameter is an integer, it defines *which part* of the string
 * should be stripped of whitespace:
 * - `0` → start **and** end *(default)*
 * - `1` → start only
 * - `2` → end only
 *
 * ```aql
 * TRIM("  hello  ", 0)   // "hello"
 * TRIM("  hello  ", 1)   // "hello  "
 * TRIM("  hello  ", 2)   // "  hello"
 * ```
 *
 * 2. **Character mode (string `chars` argument)**
 * When the second parameter is a string, it defines a set of characters to remove
 * from both ends of the value, instead of whitespace.
 *
 * ```aql
 * TRIM("***hello***", "*")  // "hello"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\trim;
 *
 * // Default (trim whitespace from both ends)
 * $expr = trim('doc.title');
 * // Produces: TRIM(doc.title)
 *
 * // Trim only the start of the string
 * $expr = trim('doc.title', 1);
 * // Produces: TRIM(doc.title, 1)
 *
 * // Trim custom characters
 * $expr = trim('doc.title', '"*"');
 * // Produces: TRIM(doc.title, "*")
 * ```
 *
 * @param string $value The string or AQL expression to trim.
 * @param string|int|null $charsOrType Optional:
 * - `int` → whitespace mode (`0`, `1`, `2`)
 * - `string` → characters to strip (e.g. `"*"`)
 *
 * @return string The AQL expression representing the `TRIM()` call.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/string/#trim
 * @see ltrim() For trimming from the left only.
 * @see rtrim() For trimming from the right only.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function trim( string $value , string|int|null $charsOrType = null ): string
{
    return func(StringFunction::TRIM , [ $value , $charsOrType ] ) ;
}

