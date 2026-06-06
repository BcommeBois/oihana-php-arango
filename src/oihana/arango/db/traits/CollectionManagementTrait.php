<?php

namespace oihana\arango\db\traits ;

use ReflectionException ;

use oihana\arango\clients\exceptions\ArangoException ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\collection\indexes\RawIndexDefinition ;

use oihana\arango\db\options\indexes\IndexOptions ;

/**
 * Collection-management surface of the {@see \oihana\arango\db\ArangoDB}
 * façade. Methods preserve their legacy signatures; their internals
 * were switched in Lot 6.1 from the legacy `client/CollectionHandler`
 * to the new `clients/Collection` (and `clients/Database`).
 *
 * Every method returning `bool` maps server-side exceptions to `false`
 * silently; `createIndex()` / `dropIndex()` log a warning and return
 * `null` / `false`. The throwing methods (`collectionRename` / `getIndex`
 * / `getIndexes`) let the `oihana\arango\clients\exceptions\ArangoException`
 * propagate directly.
 *
 * @package oihana\arango\db\traits
 */
trait CollectionManagementTrait
{
    /**
     * @var Database Database scope shared with the parent façade.
     */
    protected Database $database ;

    // =========================================================================
    // Collections (alphabetical)
    // =========================================================================

    /**
     * Creates a new collection if it does not already exist.
     *
     * @param string               $name    The name of the new collection.
     * @param array<string, mixed> $options Forwarded to `POST /_api/collection` (`type`, `waitForSync`, `keyOptions`, `numberOfShards`, `replicationFactor`, `writeConcern`, `shardKeys`, `shardingStrategy`, `schema`, …).
     *
     * @return bool TRUE when the collection has been created, FALSE when it already existed or the request failed.
     */
    public function collectionCreate( string $name , array $options = [] ) : bool
    {
        try
        {
            $collection = $this->database->collection( $name ) ;
            if ( !$collection->exists() )
            {
                $collection->create( $options ) ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    /**
     * Drops a collection if it exists.
     *
     * @param string $name The name of the collection.
     *
     * @return bool TRUE when the collection has been dropped, FALSE otherwise.
     */
    public function collectionDrop( string $name ) : bool
    {
        try
        {
            $collection = $this->database->collection( $name ) ;
            if ( $collection->exists() )
            {
                $collection->drop() ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    /**
     * Checks if a collection exists.
     *
     * @param string $name The name of the collection.
     *
     * @return bool
     */
    public function collectionExists( string $name ) : bool
    {
        try
        {
            return $this->database->collection( $name )->exists() ;
        }
        catch ( ArangoException )
        {
            return false ;
        }
    }

    /**
     * Renames a collection if it exists.
     *
     * @param string $oldName The current name of the collection.
     * @param string $name    The new name of the collection.
     *
     * @return bool TRUE when the collection has been renamed.
     *
     * @throws ArangoException When the rename request fails on a collection that does exist (e.g. cluster restriction, duplicate target name).
     */
    public function collectionRename( string $oldName , string $name ) : bool
    {
        $collection = $this->database->collection( $oldName ) ;

        if ( !$collection->exists() )
        {
            return false ;
        }

        $collection->rename( $name ) ;

        return true ;
    }

    /**
     * Truncates a collection if it exists.
     *
     * @param string $name The name of the collection.
     *
     * @return bool TRUE when the collection has been truncated.
     */
    public function collectionTruncate( string $name ) : bool
    {
        try
        {
            $collection = $this->database->collection( $name ) ;
            if ( $collection->exists() )
            {
                $collection->truncate() ;
                return true ;
            }
        }
        catch ( ArangoException ) {}

        return false ;
    }

    // =========================================================================
    // Indexes
    // =========================================================================

    /**
     * Creates an index on a collection.
     *
     * @param mixed              $collection   Collection as string (name) or object exposing `getName()`.
     * @param array|IndexOptions $indexOptions An {@see IndexOptions} value object or a raw associative array matching the `POST /_api/index` body.
     *
     * @return array|null The server response of the created index, or null on failure.
     *
     * @throws ReflectionException When the {@see IndexOptions} cannot be serialised.
     */
    public function createIndex( mixed $collection , array|IndexOptions $indexOptions ) : ?array
    {
        try
        {
            $body = $indexOptions instanceof IndexOptions
                ? $indexOptions->jsonSerialize()
                : $indexOptions ;

            return $this->database
                        ->collection( $this->resolveCollectionName( $collection ) )
                        ->createIndex( new RawIndexDefinition( $body ) ) ;
        }
        catch ( ArangoException $exception )
        {
            $this->logger?->warning( $exception->getMessage() ) ;
        }

        return null ;
    }

    /**
     * Drops an index from a collection.
     *
     * @param mixed      $collection  Collection name or full index handle (`collection/indexId`) when `$indexHandle` is null.
     * @param mixed|null $indexHandle Index id / name suffix; when null, `$collection` is treated as the full handle.
     *
     * @return bool TRUE when the index has been dropped.
     */
    public function dropIndex( mixed $collection , mixed $indexHandle = null ) : bool
    {
        try
        {
            if ( $indexHandle === null )
            {
                $parts = explode( '/' , (string) $collection , 2 ) ;
                if ( count( $parts ) !== 2 )
                {
                    return false ;
                }
                [ $collectionName , $indexKey ] = $parts ;
            }
            else
            {
                $collectionName = $this->resolveCollectionName( $collection ) ;
                $indexKey       = (string) $indexHandle ;
            }

            $this->database->collection( $collectionName )->dropIndex( $indexKey ) ;
            return true ;
        }
        catch ( ArangoException $exception )
        {
            $this->logger?->warning( $exception->getMessage() ) ;
        }

        return false ;
    }

    /**
     * Returns a specific index of a collection by its ID.
     *
     * @param string $collection The name of the collection.
     * @param string $indexId    The index ID (full handle or bare key — bare keys are auto-prefixed with the collection name).
     *
     * @return array The index definition from the server.
     *
     * @throws ArangoException When the index is missing or the request fails.
     */
    public function getIndex( string $collection , string $indexId ) : array
    {
        return $this->database->collection( $collection )->index( $indexId ) ;
    }

    /**
     * Returns all indexes of a collection.
     *
     * @param string $name The name of the collection.
     *
     * @return array The indexes array from the server response.
     *
     * @throws ArangoException When the request fails.
     */
    public function getIndexes( string $name ) : array
    {
        return $this->database->collection( $name )->indexes() ;
    }

    /**
     * Resolves a collection reference (string name or object exposing
     * `getName()`) to its string name.
     *
     * @param mixed $collection
     *
     * @return string
     */
    private function resolveCollectionName( mixed $collection ) : string
    {
        if ( is_object( $collection ) && method_exists( $collection , 'getName' ) )
        {
            return (string) $collection->getName() ;
        }

        return (string) $collection ;
    }
}
