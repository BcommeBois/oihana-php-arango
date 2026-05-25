<?php

namespace oihana\arango\models\helpers;

use oihana\arango\models\Documents;

use oihana\enums\Char;
use function oihana\files\path\joinPaths;

/**
 * Resolves a fully-qualified vertex ID for an ArangoDB edge.
 *
 * Converts a vertex key into a complete ArangoDB vertex ID by optionally
 * prefixing it with a collection name.
 *
 * This is useful when preparing
 * `_from` or `_to` fields in edge queries, ensuring the correct
 * `collection/key` format.
 *
 * ### Behavior
 * - If `$vertexKey` is `null`, the function returns `null`.
 * - If `$collection` is a `Documents` instance with a `collection` property, the returned ID will be prefixed with this collection.
 * - If `$collection` is a string, it will be used directly as the collection prefix.
 * - If `$collection` is `null`, the function returns the raw `$vertexKey`.
 *
 * @param string|null           $vertexKey  The vertex key (document _key) or null.
 * @param Documents|string|null $collection Optional collection prefix, either a string or a Documents instance.
 *
 * @return string|null Returns the prefixed vertex ID if a collection is provided, otherwise the original key, or null if `$vertexKey` is null.
 *
 * @example
 * ```php
 * use oihana\arango\models\Documents;
 * use function oihana\arango\models\helpers\vertexID;
 *
 * $docs = new Documents();
 * $doc->collection = 'users';
 *
 * $fullID = vertexID('123', $docs);
 * // → 'users/123'
 *
 * $rawID = vertexID('456');
 * // → '456'
 *
 * $stringPrefix = vertexID('789', 'accounts');
 * // → 'accounts/789'
 *
 * $stringPrefix = vertexID('users/789', 'accounts');
 * // → 'users/789'
 *
 * $stringPrefix = vertexID('accounts/789', $docs);
 * // → 'accounts/789'
 *
 * $nullID = vertexID( null , $docs );
 * // → null
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function vertexID( ?string $vertexKey , null|string|Documents $collection = null ) :?string
{
    if ( $vertexKey === null )
    {
        return null ;
    }

    if ( str_contains( $vertexKey , Char::SLASH ) )
    {
        return $vertexKey ;
    }

    if( $collection instanceof Documents )
    {
        $collection = $collection->collection ;
    }

    if( is_string( $collection ) && !empty( $collection ) )
    {
        return joinPaths( $collection , $vertexKey ) ;
    }

    return $vertexKey ;
}