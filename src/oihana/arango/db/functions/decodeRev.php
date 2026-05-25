<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Decompose the specified revision string into its components. The resulting object has a date and a count attribute. This function is supposed to be called with the _rev attribute value of a database document as argument.
 *
 * revision (string): revision ID string
 * returns details (object|null): object with two attributes date (string in ISO 8601 format) and count (integer number), or null
 * If the input revision ID is not a string or cannot be processed, the function issues a warning and returns null.
 *
 * @param ?string $value
 *
 * @return string The formatted AQL expression (e.g. `'DECODE_REV(user.email)'`).
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\decodeRev;
 *
 * $expr = decodeRev( '"_YU0HOEG---"' );
 * // Produces: 'DECODE_REV("_YU0HOEG---")'
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#decode_rev
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function decodeRev( ?string $value ) :string
{
    return func( MiscFunction::DECODE_REV , $value ) ;
}
