<?php

namespace oihana\arango\db\functions\search;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Match documents where an attribute is within a range (index-accelerated).
 *
 * Wraps the ArangoDB AQL function
 * `IN_RANGE(path, low, high, includeLow, includeHigh)`. Inside a `SEARCH`
 * operation it searches more efficiently than the equivalent pair of
 * comparisons combined with `AND`; the same function also exists as a
 * miscellaneous function outside of `SEARCH` (hence the constant living in
 * {@see MiscFunction}).
 *
 * `low` and `high` can be numbers or strings, but both must share the same
 * data type. Note that string ranges in `SEARCH` follow byte order, not the
 * Analyzer locale collation.
 *
 * Example AQL usage:
 * ```aql
 * IN_RANGE(doc.value, 3, 5, true, true)     // 3 <= value <= 5
 * IN_RANGE(doc.value, "a", "f", true, false) // "a" <= value < "f"
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\search\inRange;
 *
 * echo inRange( 'doc.value' , 3 , 5 , true , true ) ;
 * // 'IN_RANGE(doc.value,3,5,true,true)'
 *
 * echo inRange( 'doc.value' , 'a' , 'f' , true , false ) ;
 * // 'IN_RANGE(doc.value,"a","f",true,false)'
 * ```
 *
 * @param string $path        Attribute path expression to test (kept raw).
 * @param mixed  $low         Minimum value of the range (JSON-encoded: strings are quoted, numbers kept raw).
 * @param mixed  $high        Maximum value of the range (JSON-encoded, same data type as `$low`).
 * @param bool   $includeLow  Whether the minimum value is included (left-closed interval).
 * @param bool   $includeHigh Whether the maximum value is included (right-closed interval).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/arangosearch/#in_range
 *
 * @package oihana\arango\db\functions\search
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function inRange( string $path , mixed $low , mixed $high , bool $includeLow , bool $includeHigh ) : string
{
    return func
    (
        MiscFunction::IN_RANGE ,
        [
            $path ,
            json_encode( $low ) ,
            json_encode( $high ) ,
            json_encode( $includeLow ) ,
            json_encode( $includeHigh ) ,
        ]
    ) ;
}
