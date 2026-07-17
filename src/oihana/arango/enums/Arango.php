<?php

namespace oihana\arango\enums;

use oihana\arango\db\enums\AQL;
use oihana\controllers\enums\traits\ControllerParamTrait;
use oihana\models\enums\traits\ModelParamTrait;

/**
 * Central enumeration of ArangoDB-related parameters used throughout aql, controllers, models, and pagination contexts.
 *
 * Provides typed constants for common parameters such as
 * 'doc', 'model', 'collection', 'queryFields', 'active', 'insert', 'update', etc.
 *
 * Traits used:
 *   - ControllerParamTrait : adds controller-related parameter utilities.
 *   - ModelParamTrait      : adds model-related parameter utilities.
 *   - PaginationTrait      : adds pagination-related constants.
 *   - ConstantsTrait       : adds helper methods for constants introspection.
 *
 * Example usage:
 * ```php
 * $param = Arango::DOC;
 * ```
 */
class Arango extends AQL
{
    use ControllerParamTrait ,
        ModelParamTrait;

    /**
     * The 'activable' parameter.
     */
    public const string ACTIVABLE = 'activable' ;

    /**
     * The 'alter' parameter.
     */
    public const string ALTER = 'alter' ;

    /**
     * The 'authorizer' parameter.
     *
     * Optional `Closure(string $subject): bool` injected through `$init` so
     * AQL projection helpers can gate fields on permission subjects via
     * `Field::REQUIRES` without introducing a hard dependency on a specific
     * authorization backend (Casbin, opa, custom, ...).
     */
    public const string AUTHORIZER = 'authorizer' ;

    /**
     * The 'cacheable' parameter.
     */
    public const string CACHEABLE = 'cacheable' ;

    /**
     * The 'collect' parameter — holds an AQL `COLLECT` (grouping/aggregation) spec
     * forwarded to {@see aqlCollect()}.
     */
    public const string COLLECT = 'collect' ;

    /**
     * The 'compress' parameter.
     */
    public const string COMPRESS = 'compress' ;

    /**
     * The 'counter' parameter.
     * Name of the sibling field holding the length of an embedded array, kept in
     * sync (`LENGTH(...)`) on every mutation by {@see DocumentsArrayTrait}.
     */
    public const string COUNTER = 'counter' ;

    /**
     * The 'dateField' parameter.
     */
    public const string DATE_FIELD = 'dateField' ;

    /**
     * The 'documents' parameter.
     */
    public const string DOCUMENTS = 'documents' ;

    /**
     * The 'exist' parameter.
     */
    public const string EXIST = 'exist' ;

    /**
     * The 'extraQuery' parameter.
     */
    public const string EXTRA_QUERY = 'extraQuery' ;

    /**
     * The 'facetCounts' parameter — the list of facet keys (from `Arango::FACETS`)
     * for which per-value bucket counts are computed alongside the list, by
     * {@see FacetCountsQueryTrait::buildFacetCountsQuery()}.
     */
    public const string FACET_COUNTS = 'facetCounts' ;

    /**
     * The 'facetsOnly' flag — when truthy (and `Arango::FACET_COUNTS` is
     * requested), the document-fetch query is skipped: the list returns an empty
     * result set while the per-value facet counts (and an exact `total` computed
     * by {@see DocumentsCountTrait::count()})
     * are still returned. Useful for a faceted-search sidebar that only needs the
     * counts, not the documents.
     */
    public const string FACETS_ONLY = 'facetsOnly' ;

    /**
     * The 'group' parameter — holds a high-level grouping spec
     * ({@see Group}) translated into an AQL `COLLECT` by {@see GroupTrait::prepareCollect()}.
     */
    public const string GROUP = 'group' ;

    /**
     * The 'groupable' parameter — the optional whitelist/mapping of groupable
     * dimensions (`urlKey => fieldPath`) consumed by {@see GroupTrait::$groupable}.
     */
    public const string GROUPABLE = 'groupable' ;

    /**
     * The 'init' parameter.
     */
    public const string INIT = 'init' ;

    /**
     * The 'keepNull' payload marker. When a payload field definition carries
     * `Arango::KEEP_NULL => true`, an explicit null the client sent for that
     * field survives the compress pass (see PayloadsTrait::preparePayload),
     * so a PATCH can clear a value with `{ "field": null }`.
     */
    public const string KEEP_NULL = 'keepNull' ;

    /**
     * The 'keyList' parameter.
     */
    public const string KEY_LIST = 'keyList' ;

    /**
     * The 'match' parameter.
     */
    public const string MATCH = 'match' ;

    /**
     * The 'metaOnly' flag — when truthy, the document-fetch query is skipped: the
     * list returns an empty result set while the response *metadata* (an exact
     * `total` from {@see DocumentsCountTrait::count()},
     * plus the requested facet counts and numeric bounds) is still computed.
     * The generic "give me the sidebar, not the documents" mode, spanning facet
     * counts and bounds alike. Supersedes the counts-only {@see Arango::FACETS_ONLY}.
     */
    public const string META_ONLY = 'metaOnly' ;

    /**
     * The 'mode' parameter.
     * Optional per-call override of an embedded array field's {@see ArrayMode}.
     */
    public const string MODE = 'mode' ;

    /**
     * The 'modelID' parameter.
     */
    public const string MODEL_ID = 'modelID' ;

    /**
     * The 'near' parameter — a geospatial anchor for distance sorting.
     *
     * Holds a `{ key, latitude, longitude }` object: the document attribute to
     * measure from (`key`) plus the reference point. It exposes the synthetic
     * `distance` sort key consumed by {@see SortTrait::prepareSort()}.
     */
    public const string NEAR = 'near' ;

    /**
     * The 'num' parameter.
     */
    public const string NUM = 'num' ;

    /**
     * The 'position' parameter.
     */
    public const string POSITION = 'position' ;

    /**
     * The 'profile' parameter — when truthy, `list()` / `get()` run the query in
     * profiled mode (`true` → profile level 2) and the measurements are exposed
     * through {@see ArangoTrait::getProfile()}.
     */
    public const string PROFILE = 'profile' ;

    /**
     * The 'relations' parameter.
     */
    public const string RELATIONS = 'relations' ;

    /**
     * The 'removeKeys' parameter.
     */
    public const string REMOVE_KEYS = 'removeKeys' ;

    /**
     * The 'route' parameter.
     */
    public const string ROUTE = 'route' ;

    /**
     * The 'side' parameter.
     */
    public const string SIDE = 'side' ;

    /**
     * The 'skip' parameter.
     */
    public const string SKIP = 'skip' ;

    /**
     * The 'touch' parameter.
     * Indicates if a document timestamp or date must be updated (modified=now())
     */
    public const string TOUCH = 'touch' ;

    /**
     * The 'variables' parameter.
     */
    public const string VARIABLES = 'variables' ;

    /**
     * The 'varName' parameter.
     */
    public const string VAR_NAME = 'varName' ;
}


