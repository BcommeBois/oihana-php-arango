<?php

namespace oihana\arango\db\functions\documents;

use oihana\arango\db\enums\functions\DocumentFunction;
use function oihana\core\strings\func;

/**
 * Look up the specified value in a lookup document and return the mapped value.
 *
 * This helper wraps the ArangoDB AQL function `TRANSLATE(value, lookupDocument, defaultValue)`
 * which performs a lookup operation. If the value is a key in the lookup document,
 * it returns the corresponding value. If not found, it returns the default value
 * (if specified) or the original value unchanged.
 *
 * Example AQL usage:
 * ```aql
 * TRANSLATE("draft", {"draft": "D", "published": "P"})           // returns "D"
 * TRANSLATE("unknown", {"draft": "D", "published": "P"})         // returns "unknown"
 * TRANSLATE("unknown", {"draft": "D", "published": "P"}, "X")    // returns "X"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\documents\translate;
 *
 * $expr = translate('status', '{"draft": "D", "published": "P"}', 'unknown');
 * // Produces: 'TRANSLATE("status", {"draft": "D", "published": "P"}, "unknown")'
 * ```
 *
 * @param mixed $value The value to look up in the lookup document.
 * @param mixed $lookupDocument The document containing key-value mappings.
 * @param mixed $defaultValue Optional default value to return if key is not found.
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/document-object/#translate
 *
 * @package oihana\arango\db\functions\documents
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function translate( mixed $value , mixed $lookupDocument , mixed $defaultValue = null ) : string
{
    return func( DocumentFunction::TRANSLATE , [ $value , $lookupDocument , $defaultValue ] ) ;
}

