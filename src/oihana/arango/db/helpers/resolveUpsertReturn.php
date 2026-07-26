<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\UpsertType;

/**
 * Resolves the `RETURN` expression of an upsert-family operation, expanding the
 * {@see Clause::WITH_STATUS} shorthand into the ternary that reports which half
 * of the upsert actually ran.
 *
 * An upsert either inserts or writes over an existing document, and the caller
 * usually cannot tell which from the returned document alone. `WITH_STATUS`
 * answers that by leaning on AQL's own signal: on an insert there is no `OLD`,
 * so the ternary reads
 * ```aql
 * RETURN { doc: NEW , type: OLD ? 'replace' : 'insert' }
 * ```
 * The write half is named by the caller, since it differs between the
 * operations — {@see \oihana\arango\db\operations\aqlRepsert()} overwrites the
 * document ({@see UpsertType::REPLACE}) where
 * {@see \oihana\arango\db\operations\aqlUpsert()} merges into it
 * ({@see UpsertType::UPDATE}). The insert half is always {@see UpsertType::INSERT}.
 *
 * Any other `AQL::RETURN` value is passed through untouched, so a caller can
 * still return a hand-written expression; the default is {@see Clause::NEW}.
 *
 * @param array  $init      The operation options; `AQL::RETURN` holds the requested expression.
 * @param string $writeType The upsert type reported when the document already existed.
 *
 * @return mixed The `RETURN` expression, expanded for `WITH_STATUS` and unchanged otherwise.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\resolveUpsertReturn;
 *
 * resolveUpsertReturn( [] , UpsertType::REPLACE ) ;
 * // 'NEW'
 *
 * resolveUpsertReturn( [ AQL::RETURN => Clause::WITH_STATUS ] , UpsertType::UPDATE ) ;
 * // "{ doc: NEW , type: OLD ? 'update' : 'insert' }"
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function resolveUpsertReturn( array $init , string $writeType ) : mixed
{
    $return = $init[ AQL::RETURN ] ?? Clause::NEW ;

    return match( $return )
    {
        Clause::WITH_STATUS => sprintf
        (
            "{ doc: %s , type: %s ? '%s' : '%s' }" ,
            Clause::NEW ,
            Clause::OLD ,
            $writeType ,
            UpsertType::INSERT
        ),
        default => $return ,
    } ;
}
