<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\EmptyObject;
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
 * - `AQL::DOC` (string) — The document to insert, written verbatim (default `{}`).
 * - `AQL::COLLECTION` (string) — The target collection name.
 * - `AQL::BIND_COLLECTION` (bool) — Whether to bind the collection as a variable.
 * - `AQL::QUERY_ID` (string) — An optional query identifier to prepend the default name of the bind collection variable.
 *
 * The document is not formatted here : build it with {@see \oihana\arango\db\helpers\aqlDocument()},
 * which takes the `AQL::RAW_KEYS` / `AQL::RAW_VALUES` / `AQL::USE_SPACE` options. This function reads none of them.
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
 *     AQL::DOC             => aqlDocument( [ 'name' => 'Eka' , 'age' => 47 ] ) ,
 *     AQL::COLLECTION      => 'users' ,
 *     AQL::BIND_COLLECTION => AQL::COLLECTION ,
 * ] , $binds );
 * // INSERT {name:'Eka',age:47} INTO @@collection RETURN NEW
 * ```
 *
 * Insert a document holding a raw AQL expression
 * ```php
 * echo aqlInsert
 * ([
 *     AQL::DOC => aqlDocument
 *     (
 *         [ '_key' => 'i + 1' , 'name' => 'test' , 'active' => true ] ,
 *         [ AQL::RAW_KEYS => [ '_key' ] ]
 *     ) ,
 *     AQL::COLLECTION      => 'items' ,
 *     AQL::BIND_COLLECTION => AQL::COLLECTION ,
 * ] , $binds );
 * // INSERT {_key:i + 1,name:'test',active:true} INTO @@collection RETURN NEW
 * ```
 *
 * Insert a nested document with arrays
 * ```php
 * echo aqlInsert
 * ([
 *     AQL::DOC => aqlDocument
 *     (
 *         [ 'user' => [ 'name' => 'Eka' , 'roles' => [ 'admin' , 'editor' ] ] , 'active' => true ] ,
 *         [ AQL::USE_SPACE => true ]
 *     ) ,
 *     AQL::COLLECTION      => 'users' ,
 *     AQL::BIND_COLLECTION => AQL::COLLECTION ,
 * ] , $binds );
 * // INSERT { user:{name:'Eka',roles:['admin','editor']}, active:true } INTO @@collection RETURN NEW
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
    $doc        = $init[ AQL::DOC             ] ?? EmptyObject::COMPACT ;
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