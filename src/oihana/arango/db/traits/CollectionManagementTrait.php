<?php

namespace oihana\arango\db\traits ;

use oihana\arango\clients\exceptions\ArangoException ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\collection\Collection ;
use oihana\arango\clients\collection\enums\CollectionField ;

use oihana\arango\db\enums\DiffKind ;
use oihana\arango\db\enums\DiffStatus ;
use oihana\arango\db\results\DiffReport ;

/**
 * Collection-management surface of the {@see \oihana\arango\db\ArangoDB}
 * façade. Methods preserve their legacy signatures; their internals
 * were switched in Lot 6.1 from the legacy `client/CollectionHandler`
 * to the new `clients/Collection` (and `clients/Database`).
 *
 * Every method returning `bool` maps server-side exceptions to `false`
 * silently; `collectionRename()` lets the
 * `oihana\arango\clients\exceptions\ArangoException` propagate directly.
 * The index surface lives in {@see IndexManagementTrait}, which composes
 * this trait.
 *
 * @package oihana\arango\db\traits
 */
trait CollectionManagementTrait
{
    /**
     * @var Database Database scope shared with the parent façade.
     */
    protected Database $database ;

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
     * Compares a declared collection with the server state and reports the
     * difference — the collection half of the `doctor` diagnosis.
     *
     * The check is existence first ({@see DiffStatus::MISSING} when the
     * collection is absent), then — when `$type` is given — the collection
     * type (`2` document / `3` edge): a mismatch is reported as
     * {@see DiffStatus::DRIFTED} (a collection type cannot be changed, the
     * repair is manual by design).
     *
     * @param string   $name The name of the collection.
     * @param int|null $type The declared collection type (`2` document, `3` edge), or null to skip the type check.
     *
     * @return DiffReport The typed report ({@see DiffKind::COLLECTION}).
     */
    public function collectionDiff( string $name , ?int $type = null ) : DiffReport
    {
        try
        {
            $collection = $this->database->collection( $name ) ;

            if ( !$collection->exists() )
            {
                return new DiffReport( $name , DiffStatus::MISSING , kind : DiffKind::COLLECTION ) ;
            }

            if ( $type !== null )
            {
                $serverType = $collection->properties()[ CollectionField::TYPE ] ?? null ;
                if ( $serverType !== $type )
                {
                    return new DiffReport( $name , DiffStatus::DRIFTED ,
                    [
                        sprintf( 'type : server %s ≠ declared %s (2 = document, 3 = edge)' , json_encode( $serverType ) , $type )
                    ] , kind : DiffKind::COLLECTION ) ;
                }
            }

            return new DiffReport( $name , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ;
        }
        catch ( ArangoException $exception )
        {
            return new DiffReport( $name , DiffStatus::UNREACHABLE , [ $exception->getMessage() ] , kind : DiffKind::COLLECTION ) ;
        }
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

    /**
     * Resolves a collection reference (string name or {@see Collection}
     * client handle) to its string name.
     *
     * @param string|Collection $collection
     *
     * @return string
     */
    protected function resolveCollectionName( string|Collection $collection ) : string
    {
        return $collection instanceof Collection ? $collection->getName() : $collection ;
    }
}
