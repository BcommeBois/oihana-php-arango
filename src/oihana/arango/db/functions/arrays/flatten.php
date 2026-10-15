<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\functions\ArrayFunction;

use function oihana\core\strings\func;

/**
 * Turn an array of arrays into a flat array, down to a given depth.
 *
 * This helper wraps the ArangoDB AQL function `FLATTEN(anyArray, depth)`. Without a
 * depth, one level is flattened.
 *
 * Example AQL usage:
 * ```aql
 * FLATTEN([ 1 , [ 2 , 3 ] , [ 4 , [ 5 ] ] ])     // [ 1 , 2 , 3 , 4 , [ 5 ] ]
 * FLATTEN([ 1 , [ 2 , 3 ] , [ 4 , [ 5 ] ] ] , 2) // [ 1 , 2 , 3 , 4 , 5 ]
 * FLATTEN(doc.stops[*].scans)                    // every scan of every stop
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\flatten;
 *
 * flatten( 'doc.stops[*].scans' ) ;     // 'FLATTEN(doc.stops[*].scans)'
 * flatten( 'doc.a[*].b[*].c' , 2 ) ;    // 'FLATTEN(doc.a[*].b[*].c,2)'
 * ```
 *
 * @param mixed    $anyArray The array expression to flatten.
 * @param int|null $depth    How many levels to flatten, one when null.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/array/#flatten
 *
 * @package oihana\arango\db\functions\arrays
 * @author Marc Alcaraz
 */
function flatten( mixed $anyArray , ?int $depth = null ) : string
{
    return func( ArrayFunction::FLATTEN , $depth === null ? [ $anyArray ] : [ $anyArray , $depth ] ) ;
}
