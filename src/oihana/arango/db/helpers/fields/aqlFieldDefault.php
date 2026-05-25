<?php

namespace oihana\arango\db\helpers\fields;

use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates a simple AQL key/value expression referencing a document field.
 *
 * This helper builds a snippet suitable for inclusion in a `RETURN { ... }` block.
 * It maps a document property to an object key in the AQL result:
 * - `$key` becomes the key in the resulting AQL object.
 * - `$doc` and optional `$keyName` define the field to reference.
 *
 * If `$keyName` is omitted, the same name as `$key` is used.
 *
 * Example usage:
 * ```aql
 * aqlFieldDefault( 'name'   , 'doc' ) ; // "name: doc.name"
 * aqlFieldDefault( 'userId' , 'doc' , 'id'); // "userId: doc.id"
 * ```
 *
 * @param string      $key     The key to use in the resulting AQL object (e.g. `"name"`).
 * @param string      $doc     The document alias or variable name (e.g. `"doc"`).
 * @param string|null $keyName Optional property name in the document; if omitted, `$key` is used.
 *
 * @return string AQL key/value expression string, e.g. `"name: doc.name"`.
 *
 * @package oihana\arango\db\helpers\fields
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlFieldDefault( string $key , string $doc , ?string $keyName = null ): string
{
    return keyValue( $key , key( $keyName ?? $key , $doc ) ) ;
}