<?php

namespace oihana\arango\clients\collection\indexes ;

/**
 * Common contract for every index definition consumable by
 * {@see \oihana\arango\clients\collection\Collection::createIndex()}.
 *
 * Implementations are expected to be immutable value objects whose
 * single responsibility is to serialise themselves into the body shape
 * expected by `POST /_api/index`. The HTTP layer takes the resulting
 * array and forwards it as JSON.
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
interface IndexDefinition
{
    /**
     * Returns the request body for `POST /_api/index` corresponding to
     * this index definition.
     *
     * Implementations MUST set the `type` field (typically through
     * {@see \oihana\arango\clients\collection\indexes\enums\IndexType})
     * and the `fields` array. Every other key is optional and must be
     * omitted from the output when the underlying property is null,
     * so the server applies its default.
     *
     * @return array<string, mixed>
     */
    public function toArray() : array ;
}
