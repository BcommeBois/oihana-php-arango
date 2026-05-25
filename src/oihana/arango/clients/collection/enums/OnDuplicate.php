<?php

namespace oihana\arango\clients\collection\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Strategies accepted by the `onDuplicate` query parameter of the
 * ArangoDB bulk import endpoint (`POST /_api/import?collection={name}`).
 *
 * The value tells the server how to handle a row whose `_key` collides
 * with an existing document:
 * - {@see ERROR}   — refuse the row and report it in the `errors` count
 *                    (default behaviour).
 * - {@see UPDATE}  — patch the existing document with the supplied fields.
 * - {@see REPLACE} — overwrite the existing document with the supplied
 *                    payload (PUT semantics — fields absent from the row
 *                    are dropped).
 * - {@see IGNORE}  — silently skip the row and bump the `ignored` count.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/documents/#create-multiple-documents
 *
 * @package oihana\arango\clients\collection\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class OnDuplicate
{
    use ConstantsTrait ;

    /**
     * Refuse the duplicate row and bump the `errors` count.
     */
    public const string ERROR = 'error' ;

    /**
     * Silently skip the duplicate row and bump the `ignored` count.
     */
    public const string IGNORE = 'ignore' ;

    /**
     * Overwrite the existing document with the supplied payload.
     */
    public const string REPLACE = 'replace' ;

    /**
     * Patch the existing document with the supplied fields.
     */
    public const string UPDATE = 'update' ;
}
