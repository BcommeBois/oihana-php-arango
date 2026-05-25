<?php

namespace oihana\arango\db\functions\strings;

use oihana\arango\db\enums\functions\StringFunction;
use function oihana\core\strings\func;

/**
 * Return a universally unique identifier (UUID).
 *
 * This helper wraps the ArangoDB AQL function `UUID()` which generates and returns
 * a universally unique identifier. UUIDs are useful for generating unique identifiers
 * that are globally unique across different systems and time periods.
 *
 * Example AQL usage:
 * ```aql
 * UUID()                        // returns something like "550e8400-e29b-41d4-a716-446655440000"
 * UUID()                        // returns a different UUID each time
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\strings\uuid;
 *
 * $expr = uuid();
 * // Produces: 'UUID()'
 * ```
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/3.12/aql/functions/string/#uuid
 * @see randomToken() For generating random tokens.
 *
 * @package oihana\arango\db\functions\strings
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function uuid(): string
{
    return func(StringFunction::UUID ) ;
}

