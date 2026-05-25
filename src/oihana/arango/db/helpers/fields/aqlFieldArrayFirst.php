<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for extracting the first element of an array field.
 *
 * This helper constructs an expression suitable for inclusion in a `RETURN { ... }` block.
 * It checks if the field is an array using `IS_ARRAY()` and returns the first element
 * using `FIRST()`. If the field is not an array, it returns `null`.
 *
 * Example usage:
 * ```php
 * // Extract the first author from "doc.authors"
 * aqlFieldArrayFirst('mainAuthor', 'doc.authors');
 * // Produces: "mainAuthor: IS_ARRAY(doc.authors) ? FIRST(doc.authors) : null"
 *
 * // Extract the first tag from a custom variable
 * aqlFieldArrayFirst('firstTag', 'tagsList');
 * // Produces: "firstTag: IS_ARRAY(tagsList) ? FIRST(tagsList) : null"
 * ```
 *
 * @param string $key   Logical key to use in the resulting AQL object.
 * @param string $value AQL field reference to evaluate (e.g. `'doc.authors'`).
 *
 * @return string AQL key/value snippet extracting the first array element.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldArrayFirst( string $key , string $value ): string
{
    return keyValue
    (
        $key ,
        ternary( isArray( $value ) , first( $value ) , AQL::NULL )
    );
}