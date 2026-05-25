<?php

namespace oihana\arango\clients\collection\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * URI suffixes appended to `/_api/collection/{name}` for the various
 * sub-resources of a collection on the ArangoDB server.
 *
 * Lot 5.2 exposes the suffixes consumed by the current
 * {@see \oihana\arango\clients\collection\Collection} surface (count,
 * truncate, properties, rename). Future lots will add `/figures`,
 * `/revision`, `/checksum`, …
 *
 * Each constant starts with a `/` so it can be concatenated directly
 * to a built `/_api/collection/{name}` base.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/collections/
 *
 * @package oihana\arango\clients\collection\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class CollectionRoute
{
    use ConstantsTrait ;

    /**
     * Sub-route returning the document count of a collection
     * (`GET /_api/collection/{name}/count`).
     */
    public const string COUNT = '/count' ;

    /**
     * Sub-route returning the full metadata of a collection
     * (`GET /_api/collection/{name}/properties`).
     */
    public const string PROPERTIES = '/properties' ;

    /**
     * Sub-route renaming a collection
     * (`PUT /_api/collection/{name}/rename`, body `{ name: <newName> }`).
     */
    public const string RENAME = '/rename' ;

    /**
     * Sub-route truncating a collection
     * (`PUT /_api/collection/{name}/truncate`).
     */
    public const string TRUNCATE = '/truncate' ;
}
