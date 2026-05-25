<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Operator;
use oihana\arango\db\options\InsertOptions;
use oihana\exceptions\BindException;

use function oihana\arango\db\binds\aqlBindCollection;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `INSERT` statement.
 *
 * This method dynamically constructs an ArangoDB AQL query of the form:
 *
 * ```aql
 * INSERT {document} INTO collection OPTIONS {...} RETURN NEW
 * ```
 *
 * It supports binding collection names, attaching additional insert options,
 * and optionally returning the newly inserted document.
 *
 * The `$init` array may contain:
 * - `AQL::DOCUMENT` (array|object|string) — The document to insert.
 * - `AQL::COLLECTION` (string) — The target collection name.
 * - `AQL::BIND_COLLECTION` (bool) — Whether to bind the collection as a variable.
 * - `AQL::QUERY_ID` (string) — An optional query identifier to prepend the default name of the bind collection variable.
 * - `AQL::RAW_VALUES` (array) — Keys in the document whose values should be treated as raw AQL expressions.
 * - `AQL::USE_SPACE` (bool) — Whether to add spaces around braces and commas for readability.
 *
 * @param array      $init An associative array containing the insert parameters.
 * @param array|null $binds A reference array to hold bind variables for the query.
 *
 * @return string The compiled AQL `INSERT` query string, ready to be executed.
 *
 * @throws BindException If binding a collection or variable fails.
 * @throws ReflectionException If there is an issue reflecting InsertOptions.
 *
 * @example
 *
 * **Example 1 — Insert a single document into a collection**
 * ```php
 * echo aqlInsert
 * ([
 *     AQL::DOCUMENT   => ['name' => 'Eka', 'age' => 47],
 *     AQL::COLLECTION => 'users'
 * ]);
 * // INSERT {name:'Eka',age:47} INTO users OPTIONS {} RETURN NEW
 * ```
 *
 * Insert a document with raw AQL expressions
 * ```php
 * echo aqlInsert
 * ([
 *     AQL::DOCUMENT =>
 *     [
 *          '_key' => "CONCAT('test', i)",
 *          'name' => 'test',
 *          'active' => true
 *     ],
 *     AQL::RAW_VALUES => [ '_key' ] ,
 *     AQL::COLLECTION => 'items'
 * ]);
 * // INSERT {_key:CONCAT('test', i),name:'test',active:true} INTO items OPTIONS {} RETURN NEW
 * ```
 *
 * Insert a nested document with arrays
 * ```php
 * echo aqlInsert
 * ([
 *     AQL::DOC =>
 *     [
 *          'user' => ['name' => 'Eka', 'roles' => ['admin','editor']],
 *          'active' => true
 *     ],
 *     AQL::USE_SPACE => true,
 *     AQL::COLLECTION => 'users'
 * ]);
 * // INSERT { user:{name:'Eka',roles:['admin','editor']}, active:true } INTO users OPTIONS {} RETURN NEW
 * ```
 *
 * @see InsertOptions Allows passing advanced options such as overwriteMode, waitForSync, etc.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlInsert
(
    array   $init    = []   ,
    ?array  &$binds  = null ,
    ?string $queryID = null
)
:string
{
    $collection = $init[ AQL::COLLECTION ] ?? null ;
    if ( empty( $collection ) )
    {
        throw new InvalidArgumentException( 'Collection name is required for INSERT' ) ;
    }

    $binds      = $binds ?? [] ;
    $doc        = $init[ AQL::DOC             ] ?? '{}' ;
    $queryID    = $init[ AQL::QUERY_ID        ] ?? $queryID ;
    $to         = $init[ AQL::BIND_COLLECTION ] ?? null ;

    $collection = aqlBindCollection( $collection , $binds , $to , $queryID ) ;

    return compile
    ([
        Operation::INSERT , $doc , Operator::INTO , $collection ,
        aqlOptions ( $init , InsertOptions::class ) ,
        aqlReturn  ( Clause::NEW )
    ]) ;
}