<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\EmptyObject;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Operator;
use oihana\arango\db\options\ReplaceOptions;
use oihana\arango\db\options\UpdateOptions;

use function oihana\core\strings\compile;

/**
 * Partially modifies a document with the given attributes, by adding new and updating existing attributes.
 * *Basic Syntax:**
 * ```
 * UPDATE `document` IN `collection`
 * UPDATE `keyExpression` WITH `document` IN `collection`
 * ```
 *
 * @param array{
 * collection? : string,
 * doc?        : array|string|null,
 * operation?  : string|null ,
 * options?    : array|UpdateOptions|ReplaceOptions|null ,
 * with?       : string|null
 * } $init Initial options for the UPDATE or REPLACE statement, with the keys:
 * - 'collection' : The name of the collection in which the document should be updated.
 * - 'doc'        : An object and contain the attributes and values to update.
 * - 'options'    : The default 'options' expression definition
 * - 'rawValues'  : array, keys whose values should be treated as raw AQL expressions - used with the 'with' option)
 * - 'rawKeys'    : array, keys which should be kept raw (their values are not wrapped or converted) - used with the 'with' option)
 * - 'useSpace'   : bool, add spaces around braces and after commas
 * - 'with'       : One or multiple collections for WITH clause -> WITH collection1 [, collection2 [, ... collectionN ] ]
 *
 * @param string $operation The AQL operation to perform.
 * Must be either {@see Operation::UPDATE} (default) or {@see Operation::REPLACE}.
 *
 * If the "REPLACE" operation is used, see the replace method.
 *
 * @return string
 *
 * @throws ReflectionException If options serialization or internal reflection fails.
 *
 *Examples**
 *
 * ```php
 * // 1. Basic UPDATE with default doc variable:
 * echo aqlUpdate
 * ([
 *    'collection' => 'users',
 *    'doc'        => [ 'name' => 'John' ]
 * ]);
 * // UPDATE {name:'John'} IN users
 *
 * // 2. UPDATE using a custom document expression:
 * echo aqlUpdate
 * ([
 *    'collection' => 'products',
 *    'doc'        => '{ price: 99.99 }'
 * ]);
 * // UPDATE {price:99.99} IN products
 *
 * // 3. REPLACE instead of UPDATE:
 * echo aqlUpdate
 * ([
 *    'collection' => 'profiles',
 *    'doc'        => [ 'name' => 'Marc', 'age' => 42 ]
 * ] , Operation::REPLACE ) ;
 * // REPLACE {name:'Marc',age:42} IN profiles
 *
 * // 4. UPDATE with options array (doc):
 * echo aqlUpdate
 * ([
 *    'collection' => 'events',
 *    'doc'        => 'doc',
 *    'options'    =>
 *    [
 *       'ignoreRevs' => true,
 *       'keepNull'   => false
 *    ]
 * ]);
 * // UPDATE doc IN events OPTIONS {"ignoreRevs":true,"keepNull":false}
 *
 * // 5. UPDATE with UpdateOptions object (doc):
 * echo aqlUpdate
 * ([
 *    'collection' => 'products',
 *    'doc'        => [ 'price' => 42 ],
 *    'options'    => new UpdateOptions(['mergeObjects' => true])
 * ]);
 * // UPDATE {price:42} IN products OPTIONS {"mergeObjects":true}
 *
 * // 6. UPDATE using a key and WITH (no doc):
 * echo aqlUpdate
 * ([
 *    'collection' => 'orders',
 *    'key'        => betweenQuotes('my_key'),
 *    'with'       => [ 'name' => 'eka', 'age' => 48 ]
 * ]);
 * // UPDATE 'my_key' WITH {name:'eka',age:48} IN orders
 *
 * // 7. UPDATE using a key in doc and WITH (no doc expression):
 * echo aqlUpdate
 * ([
 *    'collection' => 'orders',
 *    'key'        => key('my_key', 'doc'),
 *    'with'       => [ 'name' => 'eka', 'age' => 48 ]
 * ]);
 * // UPDATE doc.my_key WITH {name:'eka',age:48} IN orders
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/update
 * @see UpdateOptions
 *
 * @package oihana\arango\db\traits\operations
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlUpdate
(
    array  $init      = [] ,
    string $operation = Operation::UPDATE
)
:string
{
    $replace = $operation == Operation::REPLACE  ;

    $expressions = [ $replace ? Operation::REPLACE : Operation::UPDATE ] ;

    if( isset( $init[ AQL::WITH ] ) )
    {
        $expressions[] = $init[ AQL::KEY ] ?? AQL::DOC ;
        $expressions[] = Operator::WITH ;
        $expressions[] = $init[ AQL::WITH ] ?? EmptyObject::COMPACT ;
    }
    else
    {
        $expressions[] = $init[ AQL::DOC ] ?? AQL::DOC  ;
    }

    $collection = trim( $init[ AQL::COLLECTION ] ?? AQL::VAR_COLLECTION ) ;

    if ( empty( $collection ) )
    {
        throw new InvalidArgumentException( 'Collection name is required and cannot be empty for ' . $operation ) ;
    }

    $options = aqlOptions( $init , $replace ? ReplaceOptions::class : UpdateOptions::class ) ;

    $expressions[] = Comparator::IN ;
    $expressions[] = $collection ;
    $expressions[] = $options ;

    return compile( $expressions ) ;
}