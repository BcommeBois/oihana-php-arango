<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Split input string(s) using the specified analyzer into an array of tokens.
 *
 * This helper wraps the ArangoDB AQL function `TOKENS(input, analyzer)` which
 * splits the input text using the specified analyzer and returns an array of tokens.
 * This is useful for text analysis and search operations.
 *
 * Example AQL usage:
 * ```aql
 * TOKENS("hello world", "text_en")          // returns ["hello", "world"]
 * TOKENS("Hello, World!", "text_en")        // returns ["hello", "world"]
 * TOKENS(doc.content, "text_en")            // returns tokens from content
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\tokens;
 *
 * $expr = tokens('doc.content', '"text_en"');
 * // Produces: 'TOKENS(doc.content, "text_en")'
 * ```
 *
 * @param string $init Text expression to tokenize (accepts recursive arrays of strings).
 * @param string $analyzer Name of the analyzer to use for tokenization.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#tokens
 * @see split() For simple string splitting.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function tokens( string $init , string $analyzer ): string
{
    return func(StringFunction::TOKENS , [ $init , $analyzer ] ) ;
}

