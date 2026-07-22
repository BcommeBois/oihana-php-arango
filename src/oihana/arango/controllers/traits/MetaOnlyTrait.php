<?php

namespace oihana\arango\controllers\traits;

use oihana\arango\enums\Arango;

/**
 * Holds the durable `metaOnly` default of a controller.
 *
 * This trait exposes the `metaOnly` flag used as the base value when a list
 * query resolves whether the document-fetch step must be skipped (see
 * {@see PrepareMetaOnlyTrait::prepareMetaOnly()}). It mirrors {@see \oihana\controllers\traits\LimitTrait}:
 * the state lives here, the per-request resolution lives in the `Prepare*` trait.
 *
 * The default stays `false` (documents are fetched), so nothing changes unless a
 * host wires `Arango::META_ONLY => true` in the controller `$init` — typically a
 * dedicated "facets / bounds" endpoint that should return the metadata only. A
 * request may still override it with `?metaOnly=false`.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
trait MetaOnlyTrait
{
    /**
     * The durable `metaOnly` default of the controller.
     *
     * When true, `list()` returns the response metadata (exact `total`, and, when
     * requested, the facet counts and numeric bounds) without the documents.
     *
     * @var bool
     */
    public bool $metaOnly = false ;

    /**
     * Initializes the `metaOnly` default from an array.
     *
     * @param array $init Initialization array, read from the `Arango::META_ONLY` key.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function initializeMetaOnly( array $init = [] ):static
    {
        $this->metaOnly = filter_var( $init[ Arango::META_ONLY ] ?? $this->metaOnly , FILTER_VALIDATE_BOOLEAN ) ;
        return $this ;
    }
}
