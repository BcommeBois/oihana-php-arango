<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use function oihana\core\strings\func;

/**
 * Return a pseudo-random number between 0 and 1.
 *
 * This helper wraps the ArangoDB AQL function `RAND()` which returns
 * a pseudo-random number between 0 (inclusive) and 1 (exclusive).
 * The algorithm for random number generation should be treated as opaque.
 *
 * Example AQL usage:
 * ```aql
 * RAND()                        // returns a random number like 0.123456789
 * RAND() * 100                  // returns a random number between 0 and 100
 * FLOOR(RAND() * 6) + 1         // returns a random integer between 1 and 6
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\rand;
 *
 * $expr = rand();
 * // Produces: 'RAND()'
 * ```
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/numeric/#rand
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function rand() : string
{
    return func( NumericFunction::RAND ) ;
}

