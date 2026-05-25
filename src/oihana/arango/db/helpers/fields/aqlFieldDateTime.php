<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\dates\dateFormat;
use function oihana\arango\db\functions\isDateString;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for a DateTime field.
 *
 * This helper builds a snippet suitable for inclusion in a `RETURN { ... }` block.
 * It performs the following steps:
 * 1. Checks whether the specified document field contains a valid date string using `IS_DATE_STRING()`.
 * 2. If valid, formats the date to the provided ISO 8601 pattern using `DATE_FORMAT()`.
 * 3. If not valid, returns `null`.
 *
 * This ensures that the resulting AQL object always contains a valid ISO-formatted date or null.
 *
 * Example usage:
 * ```aql
 * // PHP call
 * aqlFieldDateTime('createdAt');
 *
 * // Generates
 * createdAt: IS_DATE_STRING(doc.createdAt) ? DATE_FORMAT(doc.createdAt, "%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null
 * ```
 *
 * @param string      $key     The key to use in the resulting AQL object (e.g. `"createdAt"`).
 * @param string      $doc     The document alias or variable name (default: `AQL::DOC`).
 * @param string|null $keyName Optional field name in the document; if omitted, `$key` is used.
 * @param string|null $format  Optional AQL date format pattern (default: ISO 8601 style `"%yyyy-%mm-%ddT%hh:%ii:%ssZ"`).
 *
 * @return string AQL key/value expression string, e.g.:
 * `"createdAt: IS_DATE_STRING(doc.createdAt) ? DATE_FORMAT(doc.createdAt, "...") : null"`.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldDateTime
(
    string  $key ,
    string  $doc     = AQL::DOC ,
    ?string $keyName = null ,
    ?string $format  = null ,
)
: string
{
    $format ??= "%yyyy-%mm-%ddT%hh:%ii:%ssZ" ; // default
    $docKey = key( $keyName ?? $key , $doc ) ;
    return keyValue( $key , ternary
    (
        condition  : isDateString( $docKey ) ,
        trueValue  : dateFormat( $docKey , $format ) ,
        falseValue : AQL::NULL
    )) ;
}