<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Vector index definition (ArangoDB 3.13+, Faiss-backed).
 *
 * Indexes embedding vectors so that approximate nearest-neighbour
 * searches can be answered efficiently via the AQL `APPROX_NEAR_*`
 * family of functions.
 *
 * The `$params` array is forwarded verbatim to the server and
 * typically carries the Faiss configuration:
 * - `dimensions` (int, required): vector size,
 * - `metric` (string, required): `"l2"` or `"cosine"`,
 * - `nLists` (int, required): number of inverted lists at training time,
 * - `defaultNProbe` (int, optional),
 * - `factory` (string, optional): Faiss factory string for advanced use.
 *
 * Example:
 * ```php
 * $docs->createIndex
 * (
 *     new VectorIndex
 *     (
 *         fields : [ 'embedding' ] ,
 *         params :
 *         [
 *             'dimensions' => 768 ,
 *             'metric'     => 'cosine' ,
 *             'nLists'     => 100 ,
 *         ] ,
 *     )
 * ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/vector-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class VectorIndex implements IndexDefinition
{
    /**
     * @param array<int, string>      $fields       Document attribute paths holding the vector (typically a single field).
     * @param array<string, mixed>    $params       Faiss configuration (see class doc).
     * @param int|null                $parallelism  Parallelism level — number of threads that may build / query the index in parallel.
     * @param string|null             $name         Optional human-readable index name.
     * @param array<int, string>|null $storedValues Additional attribute paths kept alongside the index entries.
     * @param bool|null               $inBackground Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public array   $params ,
        public ?int    $parallelism  = null ,
        public ?string $name         = null ,
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
            IndexField::TYPE   => IndexType::VECTOR ,
            IndexField::FIELDS => $this->fields ,
            IndexField::PARAMS => $this->params ,
        ] ;

        if ( $this->parallelism  !== null ) { $data[ IndexField::PARALLELISM ]   = $this->parallelism  ; }
        if ( $this->name         !== null ) { $data[ IndexField::NAME ]          = $this->name         ; }
        if ( $this->storedValues !== null ) { $data[ IndexField::STORED_VALUES ] = $this->storedValues ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ] = $this->inBackground ; }

        return $data ;
    }
}
