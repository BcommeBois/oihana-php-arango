<?php

namespace oihana\arango\db\functions;

use oihana\arango\db\enums\functions\MiscFunction;
use function oihana\core\strings\func;

/**
 * Dynamically look up one or multiple documents from any collections,
 * either using a collection name and one or more document keys, or one or more document identifiers.
 *
 * The collections do not need to be known at query compile time, they can be computed at runtime.
 *
 * This helper wraps the ArangoDB AQL function `DOCUMENT()`.
 *
 * Example AQL output:
 * ```aql
 * RETURN DOCUMENT( persons, "persons/alice" )
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\document;
 *
 * $expr = document( 'persons', '"alice"' );
 * // Produces: 'DOCUMENT(persons,"alice")'
 * ```
 *
 * @param mixed ...$values
 *
 * @return string The formatted AQL expression (e.g. `'NOT_NULL(user.email)'`).
 *
 * @see https://docs.arangodb.com/stable/aql/functions/miscellaneous/#document
 *
 * @package oihana\arango\db\functions
 * @since   1.0.0
 * author  Marc Alcaraz
 */
function document( mixed ...$values ) :string
{
    return func( MiscFunction::DOCUMENT , $values ) ;
}
