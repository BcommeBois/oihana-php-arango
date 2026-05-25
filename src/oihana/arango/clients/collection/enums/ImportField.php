<?php

namespace oihana\arango\clients\collection\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Field names carried by the response body of the ArangoDB bulk import
 * endpoint (`POST /_api/import?collection={name}`).
 *
 * The server replies with a JSON object summarising the outcome of the
 * import; these constants give it a typed surface so callers never have
 * to read it through magic strings.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/documents/#create-multiple-documents
 *
 * @package oihana\arango\clients\collection\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ImportField
{
    use ConstantsTrait ;

    /**
     * Number of documents successfully created.
     */
    public const string CREATED = 'created' ;

    /**
     * Optional list of per-row error details, populated when the request
     * was issued with the `details: true` option.
     */
    public const string DETAILS = 'details' ;

    /**
     * Number of empty (or otherwise skipped) source rows.
     */
    public const string EMPTY = 'empty' ;

    /**
     * Number of documents that failed to be imported.
     */
    public const string ERRORS = 'errors' ;

    /**
     * Number of duplicate documents that were silently ignored — only
     * meaningful when `onDuplicate` is set to {@see OnDuplicate::IGNORE}.
     */
    public const string IGNORED = 'ignored' ;

    /**
     * Number of existing documents updated — only meaningful when
     * `onDuplicate` is set to {@see OnDuplicate::UPDATE} or
     * {@see OnDuplicate::REPLACE}.
     */
    public const string UPDATED = 'updated' ;
}
