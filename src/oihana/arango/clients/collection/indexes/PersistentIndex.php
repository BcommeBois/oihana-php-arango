<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Persistent (B-tree) index definition — the default index type for
 * most use cases.
 *
 * Replaces the legacy `hash` and `skiplist` types since ArangoDB 3.7
 * (both are aliases of `persistent` server-side; this client
 * intentionally exposes only `persistent`).
 *
 * Example:
 * ```php
 * $users->createIndex
 * (
 *     new PersistentIndex
 *     (
 *         fields : [ 'email' ] ,
 *         unique : true ,
 *         sparse : true ,
 *         name   : 'idx_email_unique' ,
 *     )
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class PersistentIndex implements IndexDefinition
{
    /**
     * @param array<int, string>      $fields       Document attribute paths the index applies to (e.g. `['email']`, `['firstName', 'lastName']`).
     * @param bool                    $unique       Enforce uniqueness across indexed tuples.
     * @param bool                    $sparse       Skip documents missing every indexed attribute.
     * @param string|null             $name         Optional human-readable index name (also accepted by {@see \oihana\arango\clients\collection\Collection::dropIndex()}).
     * @param bool|null               $deduplicate  Silently deduplicate identical entries instead of raising on collision.
     * @param bool|null               $estimates    Maintain selectivity estimates for the query optimizer.
     * @param bool|null               $cacheEnabled Keep an in-memory cache of frequently accessed entries.
     * @param array<int, string>|null $storedValues Additional attribute paths kept alongside the index entries so the query can be answered without touching the document.
     * @param bool|null               $inBackground Build the index in the background, without blocking concurrent writes.
     */
    public function __construct
    (
        public array   $fields ,
        public bool    $unique       = false ,
        public bool    $sparse       = false ,
        public ?string $name         = null ,
        public ?bool   $deduplicate  = null ,
        public ?bool   $estimates    = null ,
        public ?bool   $cacheEnabled = null ,
        public ?array  $storedValues = null ,
        public ?bool   $inBackground = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $data =
        [
            IndexField::TYPE   => IndexType::PERSISTENT ,
            IndexField::FIELDS => $this->fields ,
        ] ;

        if ( $this->unique )                { $data[ IndexField::UNIQUE ]         = true ; }
        if ( $this->sparse )                { $data[ IndexField::SPARSE ]         = true ; }
        if ( $this->name         !== null ) { $data[ IndexField::NAME ]           = $this->name         ; }
        if ( $this->deduplicate  !== null ) { $data[ IndexField::DEDUPLICATE ]    = $this->deduplicate  ; }
        if ( $this->estimates    !== null ) { $data[ IndexField::ESTIMATES ]      = $this->estimates    ; }
        if ( $this->cacheEnabled !== null ) { $data[ IndexField::CACHE_ENABLED ]  = $this->cacheEnabled ; }
        if ( $this->storedValues !== null ) { $data[ IndexField::STORED_VALUES ]  = $this->storedValues ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ]  = $this->inBackground ; }

        return $data ;
    }
}
