<?php

namespace oihana\arango\db\operations;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\UpsertType;
use oihana\arango\db\options\UpsertOptions;

use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlInsertExpression;
use function oihana\arango\db\helpers\aqlReplaceExpression;
use function oihana\arango\db\helpers\aqlUpsertExpression;
use function oihana\core\strings\compile;

/**
 * Prepare a REPSERT query to replace an existing document or insert a new one if it does not exist.
 *
 * ```
 * UPSERT [ searchExpression | FILTER filterExpression ]
 * INSERT insertExpression
 * REPLACE replaceExpression
 * IN collection
 * ```
 *
 * Options in $init:
 * - `collection`: string|null, name of the collection.
 * - `filter`: array|string|null, optional filter expression.
 * - `search`: array|string|null, the search document.
 * - `insert`: array|string|null, the document to insert if no match is found.
 * - `replace`: array|string|null, the document to replace if a match is found.
 * - `options`: array|QueryOptions|string|JsonSerializable|null, optional upsert options.
 * - `return`: optional expression to define the RETURN clause. Default is Clause::NEW.
 * You can also use Clause::WITH_STATUS to return both the document and the type of operation.
 *
 * @param array $init Configuration options for the REPSERT query.
 *
 * @return string The generated AQL UPSERT query.
 *
 * @throws ReflectionException
 * @throws UnsupportedOperationException
 *
 * @example
 *  ```php
 *  $query = aqlRepsert
 *  ([
 *      'search'  => [['foo', 'bar']],
 *      'insert'  => [['foo', 'bar']],
 *      'replace' => [['foo', 'baz']],
 *  ]);
 *  // Returns: "UPSERT {foo:'bar'} INSERT {foo:'bar'} REPLACE {foo:'baz'} IN @@collection RETURN NEW"
 *  ```
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlRepsert( array $init = [] ) :string
{
    $return = $init[ AQL::RETURN ] ?? Clause::NEW ;
    $return = match( $return )
    {
        Clause::WITH_STATUS => sprintf
        (
            "{ doc: %s , type: %s ? '%s' : '%s' }" ,
            Clause::NEW ,
            Clause::OLD ,
            UpsertType::REPLACE ,
            UpsertType::INSERT
        ),
        default => $return ,
    };

    return compile
    ([
        aqlUpsertExpression  ( $init ) ,
        aqlInsertExpression  ( $init ) ,
        aqlReplaceExpression ( $init ) ,
        compile( [ Comparator::IN ,  $init[ AQL::COLLECTION ] ?? AQL::VAR_COLLECTION ] ) ,
        aqlOptions ( $init , UpsertOptions::class ) ,
        aqlReturn  ( $return )
    ]) ;
}