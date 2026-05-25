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
 * doc?        : array|string|null,
 * operation?  : string|null ,
 * options?    : array|ReplaceOptions|null ,
 * with?       : string|null
 * } $init Initial options for the REPLACE statement, with the keys:
 * - 'collection' : The name of the collection in which the document should be replaced.
 * - 'doc'        : An object and contain the attributes and values to replace.
 * - 'options'    : The default 'options' expression definition
 * - 'rawValues'  : array, keys whose values should be treated as raw AQL expressions - used with the 'with' option)
 * - 'rawKeys'    : array, keys which should be kept raw (their values are not wrapped or converted) - used with the 'with' option)
 * - 'useSpace'   : bool, add spaces around braces and after commas
 * - 'with'       : One or multiple collections for WITH clause -> WITH collection1 [, collection2 [, ... collectionN ] ]
 *
 * @return string
 *
 * @throws ReflectionException If options serialization or internal reflection fails.
 *
 *Examples**
 *
 * ```php
 * // Replace a document by key
 * $this->replace
 * ([
 *    'collection' => 'users',
 *    'doc'        => [ '_key' => 123 , 'name' => "John" ]
 * ]);
 * // Produces: REPLACE {_key:"123",name: "John"} IN users
 *
 * // Replace with additional collections and options
 * $this->replace
 * ([
 *     'collection' => 'orders',
 *     'key'        => key( 'my_key' , 'doc' ) ,
 *     'with'       => [ 'status' => 'shipped' ] ,
 *     'options'    => [ 'ignoreRevs' => true ]
 * ]);
 * // Produces: REPLACE doc.my_key WITH {'status':'shipped'} IN orders {"ignoreRevs":true}
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