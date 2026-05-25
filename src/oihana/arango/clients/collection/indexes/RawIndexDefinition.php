<?php

namespace oihana\arango\clients\collection\indexes ;

/**
 * Escape hatch for callers that hold the raw `POST /_api/index` body shape
 * (typically built dynamically, deserialised from configuration, or
 * produced by a legacy DTO).
 *
 * Wraps an associative array and exposes it through the
 * {@see IndexDefinition} contract so it can be passed to
 * {@see \oihana\arango\clients\collection\Collection::createIndex()}.
 *
 * Prefer the typed DTOs (PersistentIndex, GeoIndex, TtlIndex, MDIIndex,
 * VectorIndex, InvertedIndex, FulltextIndex) whenever the configuration
 * is known at compile time — this class is intended for the edge cases
 * where the body must remain a plain array.
 *
 * Example:
 * ```php
 * $col->createIndex
 * (
 *     new RawIndexDefinition
 *     ([
 *         'type'   => 'persistent' ,
 *         'fields' => [ 'email' ] ,
 *         'unique' => true ,
 *     ])
 * ) ;
 * ```
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class RawIndexDefinition implements IndexDefinition
{
    /**
     * @param array<string, mixed> $body Raw request body as expected by `POST /_api/index`.
     */
    public function __construct
    (
        public array $body ,
    )
    {
    }

    /**
     * Returns the wrapped body unchanged.
     *
     * @return array<string, mixed>
     */
    public function toArray() : array
    {
        return $this->body ;
    }
}
