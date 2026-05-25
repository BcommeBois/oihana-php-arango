<?php

namespace oihana\arango\clients\collection\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Field names exchanged with the ArangoDB server through the collection
 * management API (`/_api/collection`), both on the request side (e.g.
 * the body of `POST /_api/collection`) and on the response side (e.g.
 * the entries of `GET /_api/collection`).
 *
 * Lot 5.1 only declared the `count` field; Lot 5.2 rounds it out with
 * the metadata fields needed by the lifecycle methods (`name`,
 * `isSystem`, `type`) and the list payload field (`result`). Future
 * lots will add `status`, `globallyUniqueId`, `keyOptions`, …
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/collections/
 *
 * @package oihana\arango\clients\collection\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class CollectionField
{
    use ConstantsTrait ;

    /**
     * Field carrying the document count of a collection
     * (response of `GET /_api/collection/{name}/count`).
     */
    public const string COUNT = 'count' ;

    /**
     * Flag indicating that a collection is a server-managed system
     * collection (name starting with `_`). Present in every entry of
     * `GET /_api/collection`.
     */
    public const string IS_SYSTEM = 'isSystem' ;

    /**
     * Collection name. Used in the request body of `POST /_api/collection`
     * and `PUT /_api/collection/{name}/rename`, and on the response side
     * in `GET /_api/collection` entries.
     */
    public const string NAME = 'name' ;

    /**
     * Payload array carrying the listing of collections in the response
     * of `GET /_api/collection`.
     */
    public const string RESULT = 'result' ;

    /**
     * Collection type marker — one of {@see CollectionType}. Present in
     * the request body of `POST /_api/collection` and on the response
     * side in `GET /_api/collection` entries.
     */
    public const string TYPE = 'type' ;
}
