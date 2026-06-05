<?php

namespace oihana\arango\db\functions\arrays;

use oihana\arango\db\enums\Clause;
use oihana\enums\Char;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\assertAttributeName;

/**
 * Project an array of objects onto a single sub-field.
 *
 * Builds the AQL inline projection `array[* RETURN CURRENT.<field>]`, which maps
 * an array of objects to an array holding only the requested sub-field of each
 * element. Combined with an aggregate (`AVERAGE`, `SUM`, `MAX`, …) it lets a
 * filter reduce over a property of embedded objects:
 *
 * ```aql
 * AVERAGE(doc.items[* RETURN CURRENT.price])   // average price across doc.items
 * ```
 *
 * The `[* RETURN expr]` form is AQL's array inline projection (the read-only
 * sibling of the array filter `[* FILTER cond]`). Because `$field` is typically
 * user-supplied (it comes from the `alt` chain), it is validated with
 * {@see assertAttributeName()} before being interpolated, guarding against AQL
 * injection.
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\arrays\pluck;
 *
 * $expr = pluck('doc.items', 'price');
 * // Produces: 'doc.items[* RETURN CURRENT.price]'
 * ```
 *
 * @param string $array The array expression to project (e.g. `doc.items`).
 * @param string $field The sub-field to keep from each element (e.g. `price`).
 *
 * @return string The formatted AQL inline projection.
 *
 * @throws ValidationException When `$field` is not a safe attribute name.
 *
 * @package oihana\arango\db\functions\arrays
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function pluck( string $array , string $field ) : string
{
    assertAttributeName( $field ) ; // URL-provided sub-field → guard against AQL injection
    return arrayMap( $array , Clause::CURRENT . Char::DOT . $field ) ;
}
