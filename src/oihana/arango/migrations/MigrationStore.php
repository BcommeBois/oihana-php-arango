<?php

namespace oihana\arango\migrations ;

use ReflectionException;

use oihana\arango\clients\Database ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\migrations\enums\MigrationKind ;

/**
 * The persistence of the tracking collection — the bookkeeping half of the
 * migration engine.
 *
 * One collection per database (default `migrations`) holds two families of
 * {@see MigrationAction} rows, told apart by their `additionalType`
 * ({@see MigrationKind}): the versioned migrations applied by the `migrate`
 * command ({@see MigrationKind::MIGRATE}), and the `doctor --apply` audit
 * entries ({@see MigrationKind::DOCTOR}). Only the former drive the pending
 * computation — the latter are an append-only audit trail.
 *
 * @package oihana\arango\migrations
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
readonly class MigrationStore
{
    /**
     * @param Database $database   The low-level client (from `ArangoDB::database()`).
     * @param string   $collection The tracking collection name.
     */
    public function __construct
    (
        public Database $database ,
        public string   $collection = 'migrations' ,
    )
    {
    }

    /**
     * Appends a `doctor --apply` audit row — a {@see MigrationKind::DOCTOR}
     * event with a server-generated key, never replayed.
     *
     * @param MigrationAction $action The audit document (no `_key` — the server assigns one).
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     * @throws ReflectionException
     */
    public function append( MigrationAction $action ) : void
    {
        $this->ensureCollection() ;

        $this->database->collection( $this->collection )->insert( $action->jsonSerialize() ) ;
    }

    /**
     * Returns the versions of the migrations already applied on this
     * database — the {@see MigrationKind::MIGRATE} rows keyed by version,
     * used to compute what is pending. An absent collection means "nothing
     * applied yet" (empty map), not an error.
     *
     * @return array<string, MigrationAction> The applied migrations, keyed by version (`_key`).
     *
     * @throws ArangoException When the request fails for a reason other than a missing collection.
     * @throws ReflectionException
     */
    public function applied() : array
    {
        if ( !$this->database->collection( $this->collection )->exists() )
        {
            return [] ;
        }

        $cursor = $this->database->query
        (
            'FOR m IN @@c FILTER m.additionalType == @kind SORT m._key ASC RETURN m' ,
            [ '@c' => $this->collection , 'kind' => MigrationKind::MIGRATE ] ,
        ) ;

        $applied = [] ;
        foreach ( $cursor as $document )
        {
            $action = new MigrationAction( $document ) ;
            $applied[ (string) $action->_key ] = $action ;
        }

        return $applied ;
    }

    /**
     * Creates the tracking collection if it does not exist yet — called
     * before the first write of a run.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function ensureCollection() : void
    {
        $collection = $this->database->collection( $this->collection ) ;

        if ( !$collection->exists() )
        {
            $collection->create() ;
        }
    }

    /**
     * Removes a versioned migration row by version — used by the rollback
     * (`--down`, after running `down()`) and the rescue (`--forget`).
     *
     * @param string $version The migration version (`_key`).
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function remove( string $version ) : void
    {
        $this->database->query
        (
            'REMOVE @key IN @@c OPTIONS { ignoreErrors: true }' ,
            [ '@c' => $this->collection , 'key' => $version ] ,
        ) ;
    }

    /**
     * Inserts or updates a versioned migration row (`UPSERT` on `_key`) —
     * used to record a run as `active`, then `completed` / `failed`.
     *
     * @param MigrationAction $action The tracking document (its `_key` is the version).
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     * @throws ReflectionException
     */
    public function save( MigrationAction $action ) : void
    {
        $this->ensureCollection() ;

        $document = $action->jsonSerialize() ;

        $this->database->query
        (
            'UPSERT { _key: @key } INSERT @doc UPDATE @doc IN @@c' ,
            [ '@c' => $this->collection , 'key' => (string) $action->_key , 'doc' => $document ] ,
        ) ;
    }


}
