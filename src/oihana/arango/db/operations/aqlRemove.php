<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\RemoveOptions;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Prop;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Remove one or multiple documents from a collection using an AQL `REMOVE` operation.
 *
 * This helper builds a valid AQL query string for removing documents based on either:
 * - a custom **key expression**, or
 * - a document key (`_key`) and an optional **document prefix** (defaults to `doc`).
 *
 * **AQL syntax**:
 * ```aql
 * REMOVE <keyExpression> IN <collection> [OPTIONS {...}]
 * ```
 *
 * @param array{
 *      collection? : ?string ,
 *      expression? : ?string ,
 *      key?        : ?string ,
 *      options?    : ?array{
 *          exclusive?         :bool,
 *          ignoreErrors?      :bool,
 *          ignoreRevs?        :bool,
 *          refillIndexCaches? :bool,
 *          waitForSync?       :bool
 *     }
 * } $init Initial options array.
 * - 'collection' : The name of the collection in which the document should be updated.
 *                  By default, if the argument is null, use `@@collection` bindVars definition.
 * - 'expression' : The key expression that contains the document identification.
 *                  If the expression is null or an empty string, the 'key' and 'prefix' definitions are used.
 * - 'key'        : The unique identifier of the document to remove. By default `_key` -> REMOVE doc._key IN ...
 * - 'options'    : Build a {@see RemoveOptions} definition to inject at the end of the query.
 * - 'prefix'     : Optional The name of the document reference. By default `doc` -> REMOVE doc._key IN ...
 *
 * @return string The compiled AQL `REMOVE` statement.
 *
 * @throws InvalidArgumentException      If the `collection` name is missing.
 * @throws ReflectionException           If a reflection error occurs while building options.
 * @throws UnsupportedOperationException If the operation is not supported.
 *
 * @example Example usage:
 *
 * Basic removal by document key:
 * ```php
 * echo $this->remove([ 'collection' => 'users', 'key' => '_key' ]);
 * // "REMOVE {_key:doc._key} IN users"
 * ```
 *
 * Using a custom key expression:
 * ```php
 * echo $this->remove
 * ([
 *     'collection' => 'products',
 *     'expression' => 'item._key'
 * ]);
 * // "REMOVE item._key IN products"
 * ```
 *
 * With options
 * ```php
 * echo $this->remove
 * ([
 *     'collection' => 'logs',
 *     'options'    =>
 *     [
 *         'ignoreErrors'      => true ,
 *         'waitForSync'       => false ,
 *         'refillIndexCaches' => true ,
 *     ]
 * ]);
 * // "REMOVE {_key:doc._key} IN logs OPTIONS { ignoreErrors: true, waitForSync: false, refillIndexCaches: true }"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/remove
 *
 * @package oihana\arango\db\traits\operations
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
function aqlRemove( array $init = [] ) :string
{
    // Validate collection is provided
    if ( empty( $init[ AQL::COLLECTION ] ) )
    {
        throw new InvalidArgumentException('Collection name is required for REMOVE' ) ;
    }

    // Ex: `<collection name>`
    $collection = $init[ AQL::COLLECTION ] ;

    $key    = $init[ AQL::KEY    ] ?? Prop::_KEY ;
    $prefix = $init[ AQL::PREFIX ] ?? AQL::DOC   ;

    // Ex: `<keyExpression>`  | `{ _key : doc._key }` (default)
    $keyExpression = $init[ AQL::EXPRESSION ] ?? aqlDocument( [ $key => key( $key , $prefix ) ] ) ;

    // Ex: `OPTIONS { ignoreRevs: false, refillIndexCaches: true , ... }`
    $options = aqlOptions( $init , RemoveOptions::class ) ;

    return compile
    ([
        Operation::REMOVE ,
        $keyExpression ,
        Comparator::IN ,
        $collection ,
        $options
    ]) ;
}