<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\operators\ternary;
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
 * // Count elements in "tags" array in "doc"
 * aqlFieldArrayCount('tagCount');
 * // Produces: "tagCount: IS_ARRAY(doc.tags) ? LENGTH(doc.tags) : 0"
 *
 * // Count elements in a custom document variable "u" with property "authors"
 * aqlFieldArrayCount('authorCount', 'u', 'authors');
 * // Produces: "authorCount: IS_ARRAY(u.authors) ? LENGTH(u.authors) : 0"
 * ```
 *
 * @param string      $key    Logical key to use in the resulting AQL object.
 * @param string      $docRef Document variable reference (default: `AQL::DOC`).
 * @param string|null $alias  Optional alias or property name in the document if different from `$key`.
 *
 * @return string AQL key/value snippet counting array elements.
 *
 * @package oihana\arango\db\helpers
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlFieldArrayCount
(
    string  $key ,
    string  $docRef = AQL::DOC ,
    ?string $alias  = null
)
: string
{
    $docKey = key( $alias ?? $key , $docRef ) ;
    return keyValue
    (
        $key ,
        ternary( isArray( $docKey ) , length( $docKey ) , 0 )
    ) ;
}