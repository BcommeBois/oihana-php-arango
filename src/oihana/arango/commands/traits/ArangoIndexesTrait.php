<?php

namespace oihana\arango\commands\traits;

use oihana\arango\clients\collection\indexes\IndexDefinition;
use oihana\arango\db\options\indexes\IndexOptions;

/**
 * The collection→indexes registry consumed by the `doctor` action of
 * `command:arangodb`, declared independently of the models: a map of collection
 * name to its declared indexes ({@see IndexDefinition} or {@see IndexOptions}
 * value objects, or raw definitions).
 *
 * It complements the model-level `AQL::INDEXES`: because {@see \oihana\arango\models\traits\DoctorTrait::diagnose()}
 * only reconciles a model's indexes when that model declares some, a collection
 * backed by several models (or by an index-less one) can keep its indexes in a
 * single authoritative place here instead of being split across — or duplicated
 * over — every model. `doctor` reconciles each entry once, by collection.
 *
 * Supplied via the `collectionIndexes` init key
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam::COLLECTION_INDEXES}).
 *
 * Each value is the same `AQL::INDEXES` shape: a list of indexes (each an
 * {@see IndexDefinition} value object — e.g. an
 * {@see \oihana\arango\clients\collection\indexes\InvertedIndex} — an
 * {@see IndexOptions} value object, or a raw `POST /_api/index` body). As a
 * convenience a **single** value object is accepted in place of a one-element
 * list (a raw array always stays the list).
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
trait ArangoIndexesTrait
{
    /**
     * The collection→indexes registry — `[ collectionName => IndexOptions[] ]`
     * (a single {@see IndexDefinition} / {@see IndexOptions} value object is
     * tolerated in place of a one-element list).
     *
     * @var array<string, IndexDefinition|IndexOptions|array<int, mixed>>
     */
    public array $collectionIndexes = [] ;
}
