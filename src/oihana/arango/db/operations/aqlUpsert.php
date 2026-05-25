<?php

namespace oihana\arango\db\operations;

use JsonSerializable;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\UpsertType;
use oihana\arango\db\options\QueryOptions;
use oihana\arango\db\options\UpsertOptions;

use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlInsertExpression;
use function oihana\arango\db\helpers\aqlUpdateExpression;
use function oihana\arango\db\helpers\aqlUpsertExpression;
use function oihana\core\strings\compile;

/**
 * Prepare the query to update an existing document, or creates a new document if it does not exist.
 *
 * ```
 * UPSERT [ searchExpression | FILTER filterExpression ]
 * INSERT insertExpression
 * UPDATE updateExpression
 * IN collection
 * ```
 *
 * Options in $init :
 * - collection : The name of the collection
 * - filter : The alternative filterExpression, this syntax for UPSERT operations allows you to use more flexible filter conditions beyond equality matches to look up documents.
 * - search : The 'searchExpression' contains the document to be looked for. It must be an object literal (UPSERT { <key>: <value>, ... } ...) without dynamic attribute names. In case no such document can be found in collection, a new document is inserted into the collection as specified in the insertExpression.
 * - insert : The document to insert in the collection if the document not exist.
 * - update : The document to update in the collection.
 * - options : The optional upsert options definition array or object.
 * - return : optional expression to define the RETURN clause. Default is Clause::NEW.
 *            You can also use Clause::WITH_STATUS to return both the document and the type of operation.
 *
 * @param array{
 *     collection?   : string|null ,
 *     filter?       : array|string|null ,
 *     search?       : array[]|string|null ,
 *     insert?       : array[]|string|null ,
 *     update?       : array[]|string|null ,
 *     options?      : array|QueryOptions|string|JsonSerializable|null ,
 * } $init Configuration options for the UPSERT query.
 *
 * @return string The generated AQL UPSERT query.
 *
 * @throws ReflectionException
 * @throws UnsupportedOperationException
 *
 * @example
 * 1 - Upsert with UPDATE
 * ```php
 * $query = aqlUpsert
 * ([
 *     'search' => [['foo', 'bar']],
 *     'insert' => [['foo', 'bar']],
 *     'update' => [['foo', 'baz']],
 * ]);
 * // Returns: "UPSERT {foo:'bar'} INSERT {foo:'bar'} UPDATE {foo:'baz'} IN @@collection RETURN NEW"
 * ```
 * 3 - Upsert with return including status
 * ```php
 * $query3 = aqlUpsert
 * ([
 *     'search'  => [['foo', 'bar']],
 *     'insert'  => [['foo', 'bar']],
 *     'update'  => [['foo', 'baz']],
 *     'return'  => Clause::WITH_STATUS,
 * ]);
 * // Returns:
 * // "UPSERT {foo:'bar'} INSERT {foo:'bar'} UPDATE {foo:'baz'} IN @@collection RETURN { doc: NEW, type: OLD ? 'update' : 'insert' }"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlUpsert( array $init = [] ) :string
{
    $return = $init[ AQL::RETURN ] ?? Clause::NEW ;
    $return = match( $return )
    {
        Clause::WITH_STATUS => sprintf
        (
            "{ doc: %s , type: %s ? '%s' : '%s' }" ,
            Clause::NEW ,
            Clause::OLD ,
            UpsertType::UPDATE ,
            UpsertType::INSERT
        ),
        default => $return ,
    };

    return compile
    ([
        aqlUpsertExpression ( $init ) ,
        aqlInsertExpression ( $init ) ,
        aqlUpdateExpression ( $init ) ,
        compile( [ Comparator::IN ,  $init[ AQL::COLLECTION ] ?? AQL::VAR_COLLECTION ] ) ,
        aqlOptions ( $init , UpsertOptions::class ) ,
        aqlReturn  ( $return )
    ]) ;
}