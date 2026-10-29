<?php

namespace oihana\arango\db\operations;

use ReflectionException;

use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\ReplaceOptions;

/**
 * The REPLACE statement replaces an existing document with a new one,
 * removing any attributes that are not explicitly set in the provided `doc`
 * while preserving immutable system attributes (`_id`, `_key`, `_rev`).
 *
 * *Basic Syntax:**
 * ```
 * REPLACE `document` IN `collection`
 * REPLACE `keyExpression` WITH `document` IN `collection`
 * ```
 *
 * @param array{
 * collection? : string,
 * doc?        : string|null,
 * key?        : string|null,
 * operation?  : string|null ,
 * options?    : array|ReplaceOptions|null ,
 * with?       : string|null
 * } $init Initial options for the REPLACE statement, with the keys:
 * - 'collection' : The collection in which the document is replaced, written verbatim (default `@@collection`).
 * - 'doc'        : The document expression that replaces (`REPLACE <doc> IN …`), used when 'with' is absent (default `doc`).
 * - 'key'        : The key expression that 'with' applies to (`REPLACE <key> WITH …`), used with 'with' only (default `doc`).
 * - 'options'    : The default 'options' expression definition
 * - 'with'       : The replacement document for 'key' (`REPLACE <key> WITH <with> IN …`).
 *                  It is not the AQL `WITH` collections clause.
 *
 * 'doc', 'key' and 'with' are written verbatim : build a document with {@see \oihana\arango\db\helpers\aqlDocument()},
 * which takes the 'rawKeys' / 'rawValues' / 'useSpace' options. This function reads none of them, and a PHP array
 * passed here is not formatted.
 *
 * @return string
 *
 * @throws ReflectionException If options serialization or internal reflection fails.
 *
 *Examples**
 *
 * ```php
 * // Replace a document by key
 * echo aqlReplace
 * ([
 *    'collection' => 'users',
 *    'doc'        => aqlDocument( [ '_key' => 'u123' , 'name' => 'John' ] )
 * ]);
 * // REPLACE {_key:'u123',name:'John'} IN users
 * // ⚠ a numeric key ('123') would be written _key:123, a number the server refuses as a key :
 * //   declare it with betweenQuotes( '123' ).
 *
 * // Replace a key with a document, with options
 * echo aqlReplace
 * ([
 *     'collection' => 'orders',
 *     'key'        => key( 'my_key' , 'doc' ) ,
 *     'with'       => aqlDocument( [ 'status' => 'shipped' ] ) ,
 *     'options'    => [ 'ignoreRevs' => true ]
 * ]);
 * // REPLACE doc.my_key WITH {status:'shipped'} IN orders OPTIONS {"ignoreRevs":true}
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace
 * @see ReplaceOptions
 *
 * @package oihana\arango\db\traits\operations
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlReplace( array $init = [] ) :string
{
    return aqlUpdate( $init , Operation::REPLACE ) ;
}