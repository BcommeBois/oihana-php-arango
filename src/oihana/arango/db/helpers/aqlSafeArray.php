<?php

namespace oihana\arango\db\helpers;

use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenParentheses;

/**
 * Wraps an AQL path in a safety check to ensure it resolves to an array.
 *
 * Useful for FOR loops to prevent runtime errors when the source property is null.
 * Transforms `doc.items` into `(IS_ARRAY(doc.items) ? doc.items : [])`.
 *
 * @param string      $path    The AQL path to the property (e.g., "doc.offers").
 * @param string|null $default The default value if the doc property is not an array (Default '[]').
 *
 * @return string The secured AQL expression.
 *
 * @example
 * ```php
 * echo aqlSafeArray('doc.offers');
 * // Returns: "(IS_ARRAY(doc.offers) ? doc.offers : [])"
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlSafeArray
(
    string  $path ,
    ?string $default        = null ,
    bool    $useParentheses = true ,
)
: string
{
    return betweenParentheses
    (
        expression     : ternary( isArray( $path ) , $path , aqlArray( $default ) ) ,
        useParentheses : $useParentheses
    ) ;
}
