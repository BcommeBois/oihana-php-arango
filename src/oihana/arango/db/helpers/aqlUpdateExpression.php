<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\compile;

/**
 * Builds the `UPDATE` clause of an AQL operation.
 *
 * Renders the document/expression supplied under the `AQL::UPDATE` key as
 * `UPDATE <expression>` (the expression goes through {@see aqlExpression()},
 * so it accepts an object literal, a `[key, value]` pair list, or a raw
 * string). The `AQL::UPDATE` key is required: an init that omits it throws
 * an `InvalidArgumentException`.
 *
 * @param array $init Associative array with:
 *                    - `AQL::UPDATE` : array|string — the update expression (required).
 *
 * @return string The `UPDATE …` clause.
 *
 * @throws InvalidArgumentException If the `AQL::UPDATE` option is missing.
 * @throws UnsupportedOperationException
 *
 * @example
 * Object literal update:
 * ```php
 * use oihana\arango\db\enums\AQL;
 * use function oihana\arango\db\helpers\aqlUpdateExpression;
 *
 * echo aqlUpdateExpression([ AQL::UPDATE => [ [ 'foo' , 'bar' ] ] ]);
 * // UPDATE {foo:'bar'}
 * ```
 *
 * Invalid — UPDATE key missing:
 * ```php
 * aqlUpdateExpression([]);
 * // InvalidArgumentException: UPDATE option is required
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlUpdateExpression( array $init = [] ):string
{
    $expression = $init[ AQL::UPDATE ] ?? null ;

    if ( !isset( $expression ) )
    {
        throw new InvalidArgumentException( 'UPDATE option is required' ) ;
    }

    return compile( [ Operation::UPDATE , aqlExpression( $expression ) ] ) ;
}
