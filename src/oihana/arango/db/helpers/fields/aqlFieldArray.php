<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\helpers\aqlSafeArray;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for counting elements in an array field.
 *
 * This helper constructs an expression suitable for inclusion in a `RETURN { ... }` block.
 * It safely checks if the field is an array using `IS_ARRAY()` and returns its length
 * with `LENGTH()`. If the field is not an array, it defaults to `0`.
 *
 * Example usage:
 * ```php
 * aqlFieldArrayCount('tags');
 * // Produces: "tags: IS_ARRAY(doc.tags) ? doc.tags : null"
 *
 * // Count elements in a custom document variable "u" with property "authors"
 * aqlFieldArrayCount('authors', 'edge', '[]');
 * // Produces: "authors: IS_ARRAY(edge.authors) ? LENGTH(edge.authors) : []"
 * ```
 *
 * @param string      $key     Logical key to use in the resulting AQL object.
 * @param string      $docRef  Document variable reference (default: `AQL::DOC`).
 * @param string|null $default The default value if the doc property is not an array (Default '[]').
 *
 * @return string AQL key/value snippet returns an array element.
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlFieldArray
(
    string  $key ,
    string  $docRef  = AQL::DOC ,
    ?string $default = '[]'
)
: string
{
    $docKey = key( $key , $docRef ) ;
    return keyValue
    (
        $key ,
        aqlSafeArray( $docKey , $default , false )
    ) ;
}