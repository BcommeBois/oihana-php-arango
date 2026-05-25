<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Inverted index definition (ArangoDB 3.10+).
 *
 * Modern replacement for the legacy {@see FulltextIndex} (deprecated
 * since 3.10) and the building block of ArangoSearch views. Stores a
 * token-level inverted lookup table on top of one or more document
 * attributes.
 *
 * The `$fields` argument accepts two shapes:
 * - `array<int, string>` — list of attribute paths (analysed with the
 *   top-level `$analyzer`),
 * - `array<int, array<string, mixed>>` — per-field configuration
 *   (each entry can override `analyzer`, `features`,
 *   `includeAllFields`, `searchField`, `trackListPositions`, …).
 *
 * Example:
 * ```php
 * $products->createIndex
 * (
 *     new InvertedIndex
 *     (
 *         fields   : [ 'name' , 'description' ] ,
 *         analyzer : 'text_en' ,
 *         features : [ 'frequency' , 'position' , 'norm' ] ,
 *     )
 * ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/inverted-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class InvertedIndex implements IndexDefinition
{
    /**
     * @param array<int, string|array<string, mixed>> $fields                    List of attribute paths (string form) or per-field configurations (array form).
     * @param string|null                             $name                      Optional human-readable index name.
     * @param string|null                             $analyzer                  Top-level analyzer applied to every field that does not override it.
     * @param array<string, mixed>|null               $primarySort               Primary sort definition (typically `{ fields: [{field, direction}], compression }`).
     * @param array<int, array<string, mixed>>|null   $storedValues              Stored value blocks (each block holds `{fields, compression}`).
     * @param array<int, string>|null                 $features                  Default per-field features (subset of `frequency`, `position`, `offset`, `norm`).
     * @param bool|null                               $includeAllFields          When true, every document attribute is indexed.
     * @param bool|null                               $searchField               When true, the index is search-only (no document lookup).
     * @param bool|null                               $trackListPositions        Track the positions of each token within the source field.
     * @param bool|null                               $cache                     Keep an in-memory cache of frequently accessed entries.
     * @param bool|null                               $primaryKeyCache           Keep a cache of primary-key values.
     * @param int|null                                $parallelism               Number of threads that may build / query the index in parallel.
     * @param int|null                                $cleanupIntervalStep       Frequency at which obsolete segments are cleaned up.
     * @param int|null                                $commitIntervalMsec        Commit interval, in milliseconds.
     * @param int|null                                $consolidationIntervalMsec Consolidation interval, in milliseconds.
     * @param bool|null                               $inBackground              Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public ?string $name                      = null ,
        public ?string $analyzer                  = null ,
        public ?array  $primarySort               = null ,
        public ?array  $storedValues              = null ,
        public ?array  $features                  = null ,
        public ?bool   $includeAllFields          = null ,
        public ?bool   $searchField               = null ,
        public ?bool   $trackListPositions        = null ,
        public ?bool   $cache                     = null ,
        public ?bool   $primaryKeyCache           = null ,
        public ?int    $parallelism               = null ,
        public ?int    $cleanupIntervalStep       = null ,
        public ?int    $commitIntervalMsec        = null ,
        public ?int    $consolidationIntervalMsec = null ,
        public ?bool   $inBackground              = null ,
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
            IndexField::TYPE   => IndexType::INVERTED ,
            IndexField::FIELDS => $this->fields ,
        ] ;

        if ( $this->name                      !== null ) { $data[ IndexField::NAME ]                        = $this->name                      ; }
        if ( $this->analyzer                  !== null ) { $data[ IndexField::ANALYZER ]                    = $this->analyzer                  ; }
        if ( $this->primarySort               !== null ) { $data[ IndexField::PRIMARY_SORT ]                = $this->primarySort               ; }
        if ( $this->storedValues              !== null ) { $data[ IndexField::STORED_VALUES ]               = $this->storedValues              ; }
        if ( $this->features                  !== null ) { $data[ IndexField::FEATURES ]                    = $this->features                  ; }
        if ( $this->includeAllFields          !== null ) { $data[ IndexField::INCLUDE_ALL_FIELDS ]          = $this->includeAllFields          ; }
        if ( $this->searchField               !== null ) { $data[ IndexField::SEARCH_FIELD ]                = $this->searchField               ; }
        if ( $this->trackListPositions        !== null ) { $data[ IndexField::TRACK_LIST_POSITIONS ]        = $this->trackListPositions        ; }
        if ( $this->cache                     !== null ) { $data[ IndexField::CACHE ]                       = $this->cache                     ; }
        if ( $this->primaryKeyCache           !== null ) { $data[ IndexField::PRIMARY_KEY_CACHE ]           = $this->primaryKeyCache           ; }
        if ( $this->parallelism               !== null ) { $data[ IndexField::PARALLELISM ]                 = $this->parallelism               ; }
        if ( $this->cleanupIntervalStep       !== null ) { $data[ IndexField::CLEANUP_INTERVAL_STEP ]       = $this->cleanupIntervalStep       ; }
        if ( $this->commitIntervalMsec        !== null ) { $data[ IndexField::COMMIT_INTERVAL_MSEC ]        = $this->commitIntervalMsec        ; }
        if ( $this->consolidationIntervalMsec !== null ) { $data[ IndexField::CONSOLIDATION_INTERVAL_MSEC ] = $this->consolidationIntervalMsec ; }
        if ( $this->inBackground              !== null ) { $data[ IndexField::IN_BACKGROUND ]               = $this->inBackground              ; }

        return $data ;
    }
}
