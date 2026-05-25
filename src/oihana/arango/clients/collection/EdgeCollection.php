<?php

namespace oihana\arango\clients\collection ;

use oihana\arango\clients\aql\AqlQuery ;
use oihana\arango\clients\collection\enums\CollectionField ;
use oihana\arango\clients\collection\enums\CollectionType ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\exceptions\ArangoException ;

/**
 * Edge-typed collection.
 *
 * Inherits the full CRUD surface of {@see Collection} and adds three
 * convenience methods to retrieve the edges touching a given vertex.
 *
 * Implementation note: the ArangoDB `/_api/simple/*` endpoints
 * (`byExample`, `inEdges`, `outEdges`, …) have been deprecated since
 * ArangoDB 3.x and removed in 3.12+. This client therefore implements
 * the equivalent operations as plain AQL queries. The default
 * server-side edge index on `_from` / `_to` keeps them as fast as the
 * removed `simple` endpoints used to be.
 *
 * Example:
 * ```php
 * $follows = $db->edgeCollection( 'follows' ) ;
 *
 * foreach ( $follows->outEdges( 'users/alice' ) as $edge )
 * {
 *     // every edge with _from == 'users/alice'
 * }
 * ```
 *
 * @package oihana\arango\clients\collection
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class EdgeCollection extends Collection
{
    /**
     * Returns every edge connected to the given vertex on either side
     * (i.e. `_from == $vertexId` OR `_to == $vertexId`).
     *
     * @param string $vertexId Fully-qualified vertex identifier (e.g. `users/alice`).
     *
     * @return Cursor
     *
     * @throws ArangoException When the underlying AQL query fails.
     */
    public function edges( string $vertexId ) : Cursor
    {
        return $this->database->query
        (
            new AqlQuery
            (
                'FOR e IN @@col FILTER e._from == @vertex OR e._to == @vertex RETURN e' ,
                [ '@col' => $this->name , 'vertex' => $vertexId ] ,
            )
        ) ;
    }

    /**
     * Returns every edge pointing AT the given vertex (target side, i.e. `_to == $vertexId`).
     *
     * @param string $vertexId Fully-qualified vertex identifier (e.g. `users/alice`).
     *
     * @return Cursor
     *
     * @throws ArangoException When the underlying AQL query fails.
     */
    public function inEdges( string $vertexId ) : Cursor
    {
        return $this->database->query
        (
            new AqlQuery
            (
                'FOR e IN @@col FILTER e._to == @vertex RETURN e' ,
                [ '@col' => $this->name , 'vertex' => $vertexId ] ,
            )
        ) ;
    }

    /**
     * Returns every edge originating FROM the given vertex (source side, i.e. `_from == $vertexId`).
     *
     * @param string $vertexId Fully-qualified vertex identifier (e.g. `users/alice`).
     *
     * @return Cursor
     *
     * @throws ArangoException When the underlying AQL query fails.
     */
    public function outEdges( string $vertexId ) : Cursor
    {
        return $this->database->query
        (
            new AqlQuery
            (
                'FOR e IN @@col FILTER e._from == @vertex RETURN e' ,
                [ '@col' => $this->name , 'vertex' => $vertexId ] ,
            )
        ) ;
    }

    /**
     * Overrides {@see Collection::create()} to default the new collection
     * to {@see CollectionType::EDGE} when the caller does not set a
     * type explicitly through `$options`.
     *
     * @param array<string, mixed> $options Extra creation options.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function create( array $options = [] ) : void
    {
        $options[ CollectionField::TYPE ] = $options[ CollectionField::TYPE ] ?? CollectionType::EDGE ;
        parent::create( $options ) ;
    }
}
