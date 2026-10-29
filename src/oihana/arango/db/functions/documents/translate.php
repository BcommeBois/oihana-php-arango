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
 * TRANSLATE("unknown", {"draft": "D", "published": "P"}, null)   // returns null
 * ```
 *
 * Every argument is AQL text, written verbatim — nothing is quoted or encoded here.
 * A literal string must be quoted first (`aqlValue( 'unknown' )` → `'unknown'`).
 *
 * The default value : a PHP `null` (or an empty string) OMITS the third argument, and
 * `TRANSLATE()` then returns the value itself when it is not in the lookup. To return
 * the AQL `null` instead, pass `AQL::NULL` (the text `null`).
 *
 * @example
 * ```php
 * use oihana\arango\db\enums\AQL;
 * use function oihana\arango\db\functions\documents\translate;
 * use function oihana\arango\db\helpers\aqlValue;
 *
 * $lookup = aqlValue( [ 'draft' => 'D' , 'published' => 'P' ] ) ;
 *
 * translate( 'doc.status' , $lookup ) ;
 * // TRANSLATE(doc.status,{draft:'D',published:'P'})            → the status itself when unknown
 *
 * translate( 'doc.status' , $lookup , aqlValue( 'unknown' ) ) ;
 * // TRANSLATE(doc.status,{draft:'D',published:'P'},'unknown')  → 'unknown' when unknown
 *
 * translate( 'doc.status' , '@labels' , AQL::NULL ) ;
 * // TRANSLATE(doc.status,@labels,null)                         → null when unknown
 * ```
 *
 * @param mixed $value The value to look up in the lookup document (AQL text).
 * @param mixed $lookupDocument The document containing key-value mappings (AQL text).
 * @param mixed $defaultValue Optional default value (AQL text) returned if the key is not found ;
 *                            `null` omits it, `AQL::NULL` writes the AQL `null`.
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

