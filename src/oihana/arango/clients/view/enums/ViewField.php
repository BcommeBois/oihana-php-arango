<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * JSON field names exchanged with the ArangoDB view API (`/_api/view`),
 * on both the request side (body of `POST /_api/view`,
 * `PATCH/PUT /_api/view/{name}/properties`) and the response side
 * (`GET /_api/view`, `GET /_api/view/{name}`,
 * `GET /_api/view/{name}/properties`).
 *
 * Three families coexist:
 * - **Top-level fields** (`name`, `type`, `id`, `globallyUniqueId`,
 *   `result`) that frame every view payload,
 * - **arangosearch-specific properties** (`links`, `cleanupIntervalStep`,
 *   `consolidationIntervalMsec`, `commitIntervalMsec`,
 *   `consolidationPolicy`, `writebufferIdle`, `writebufferActive`,
 *   `writebufferSizeMax`, `primarySort`, `storedValues`),
 * - **per-link fields** (`analyzers`, `fields`, `includeAllFields`,
 *   `trackListPositions`, `storeValues`, `inBackground`) used by the
 *   {@see \oihana\arango\clients\view\ArangoSearchLink} value object.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/views/arangosearch-views/
 *
 * @package oihana\arango\clients\view\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ViewField
{
    use ConstantsTrait ;

    /**
     * Per-link / per-field list of analyzer names applied to the
     * indexed value.
     */
    public const string ANALYZERS = 'analyzers' ;

    /**
     * Commit interval in milliseconds between two index updates
     * (arangosearch property).
     */
    public const string COMMIT_INTERVAL_MSEC = 'commitIntervalMsec' ;

    /**
     * Cleanup interval (in commits) between two obsolete-segment
     * cleanup passes (arangosearch property).
     */
    public const string CLEANUP_INTERVAL_STEP = 'cleanupIntervalStep' ;

    /**
     * Consolidation interval in milliseconds between two segment
     * merges (arangosearch property).
     */
    public const string CONSOLIDATION_INTERVAL_MSEC = 'consolidationIntervalMsec' ;

    /**
     * Consolidation policy object (arangosearch property) — carries
     * `type` plus tier / bytes_accum-specific knobs.
     */
    public const string CONSOLIDATION_POLICY = 'consolidationPolicy' ;

    /**
     * Per-link / per-field nested object describing the indexed
     * sub-fields. Recursive — each value follows the same shape as
     * the parent link.
     */
    public const string FIELDS = 'fields' ;

    /**
     * Server-side globally-unique identifier of the view. Present on
     * every description / properties response.
     */
    public const string GLOBALLY_UNIQUE_ID = 'globallyUniqueId' ;

    /**
     * Server-side numeric identifier of the view. Present on every
     * description / properties response.
     */
    public const string ID = 'id' ;

    /**
     * Per-link / per-field flag: when true, every attribute of the
     * document is indexed regardless of the `fields` whitelist.
     */
    public const string INCLUDE_ALL_FIELDS = 'includeAllFields' ;

    /**
     * Per-link flag: when true, the index is built in the
     * background without blocking concurrent writes.
     */
    public const string IN_BACKGROUND = 'inBackground' ;

    /**
     * Wrapper field carrying the per-collection link map of an
     * arangosearch view.
     */
    public const string LINKS = 'links' ;

    /**
     * Top-level view name. Local to the database — views are not
     * db-prefixed server-side (unlike analyzers).
     */
    public const string NAME = 'name' ;

    /**
     * Primary-sort definition of an arangosearch view (array of
     * `{field, direction}` entries).
     */
    public const string PRIMARY_SORT = 'primarySort' ;

    /**
     * arangosearch property wrapping every other property under a
     * single key — only used in the `properties()` GET response on
     * some server versions.
     */
    public const string PROPERTIES = 'properties' ;

    /**
     * Wrapper field carrying the list of views on
     * `GET /_api/view`.
     */
    public const string RESULT = 'result' ;

    /**
     * Per-link / per-field `storeValues` strategy — entries of
     * {@see StoreValues}.
     */
    public const string STORE_VALUES = 'storeValues' ;

    /**
     * arangosearch property: array of additional attribute paths
     * kept alongside the index entries to answer covering queries
     * without touching the document.
     */
    public const string STORED_VALUES = 'storedValues' ;

    /**
     * Per-link / per-field flag: when true, the view records the
     * ordinal position of each value in array attributes.
     */
    public const string TRACK_LIST_POSITIONS = 'trackListPositions' ;

    /**
     * View type discriminator — entries of {@see ViewType}.
     */
    public const string TYPE = 'type' ;

    /**
     * arangosearch property: max number of pending writes the
     * indexer holds in memory before flushing to disk.
     */
    public const string WRITEBUFFER_ACTIVE = 'writebufferActive' ;

    /**
     * arangosearch property: max number of idle writers the
     * indexer keeps pooled.
     */
    public const string WRITEBUFFER_IDLE = 'writebufferIdle' ;

    /**
     * arangosearch property: max in-memory size (bytes) of a
     * single writer.
     */
    public const string WRITEBUFFER_SIZE_MAX = 'writebufferSizeMax' ;
}
