<?php

namespace oihana\arango\db\helpers;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\core\strings\compile;

/**
 * Builds the leading clause of an AQL `UPSERT` operation.
 *
 * The ArangoDB syntax accepts the lookup as **either** a search expression
 * **or** a filter expression — never both:
 * ```aql
 * UPSERT [ searchExpression | FILTER filterExpression ]
 * ```
 * Accordingly, exactly one of the two `$init` keys must be supplied:
 * - `AQL::SEARCH` — an object literal matched by equality, rendered as
 *   `UPSERT { … }` (see {@see aqlExpression()}).
 * - `AQL::FILTER` — a more flexible filter expression, rendered as
 *   `UPSERT FILTER …` (see {@see \oihana\arango\db\operations\aqlFilter()}).
 *
 * Supplying neither, or both at the same time, throws an
 * `InvalidArgumentException`.
 *
 * @param array $init Associative array with **exactly one** of:
 *                    - `AQL::SEARCH` : array|string — the search document.
 *                    - `AQL::FILTER` : array|string — the filter expression.
 *
 * @return string The `UPSERT …` clause.
 *
 * @throws InvalidArgumentException If neither FILTER nor SEARCH is defined, or if both are defined at the same time.
 * @throws UnsupportedOperationException
 *
 * @example
 * Search form (object literal matched by equality):
 * ```php
 * use oihana\arango\db\enums\AQL;
 * use function oihana\arango\db\helpers\aqlUpsertExpression;
 *
 * echo aqlUpsertExpression([ AQL::SEARCH => [ [ 'foo' , 'bar' ] ] ]);
 * // UPSERT {foo:'bar'}
 * ```
 *
 * Filter form (flexible lookup condition):
 * ```php
 * echo aqlUpsertExpression([ AQL::FILTER => [ [ 'foo' , 'bar' ] ] ]);
 * // UPSERT FILTER foo && bar
 * ```
 *
 * Invalid — neither key supplied:
 * ```php
 * aqlUpsertExpression([]);
 * // InvalidArgumentException: Either FILTER or SEARCH option is required.
 * ```
 *
 * Invalid — both keys supplied (mutually exclusive):
 * ```php
 * aqlUpsertExpression([ AQL::FILTER => …, AQL::SEARCH => … ]);
 * // InvalidArgumentException: FILTER and SEARCH cannot be defined at the same time.
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlUpsertExpression( array $init = [] ) :string
{
    $filter = aqlFilter( $init[ AQL::FILTER ] ?? null ) ;
    $search = aqlExpression( $init[ AQL::SEARCH ] ?? null ) ;

    if( !isset( $filter ) && !isset( $search ) )
    {
        throw new InvalidArgumentException( 'Either FILTER or SEARCH option is required.' ) ;
    }

    if( isset( $filter ) && isset( $search ) )
    {
        throw new InvalidArgumentException( 'FILTER and SEARCH cannot be defined at the same time.' ) ;
    }

    return compile( [ Operation::UPSERT , $search ?? $filter ] ) ;
}