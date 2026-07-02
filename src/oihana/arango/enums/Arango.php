<?php

namespace oihana\arango\enums;

use oihana\controllers\enums\traits\ControllerParamTrait;
use oihana\models\enums\traits\ModelParamTrait;
use oihana\reflect\traits\ConstantsTrait;

use xyz\oihana\schema\constants\traits\PaginationTrait;

/**
 * Central enumeration of ArangoDB-related parameters used throughout controllers, models, and pagination contexts.
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
class Arango
{
    use ConstantsTrait ,
        ControllerParamTrait ,
        PaginationTrait ,
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
     * The 'collection' parameter.
     */
    public const string COLLECTION = 'collection' ;

    /**
     * The 'compress' parameter.
     */
    public const string COMPRESS = 'compress' ;

    /**
     * The 'conditions' parameter.
     */
    public const string CONDITIONS = 'conditions' ;

    /**
     * The 'context' parameter.
     */
    public const string CONTEXT = 'context' ;

    /**
     * The 'controller' parameter.
     */
    public const string CONTROLLER = 'controller' ;

    /**
     * The 'count' parameter.
     * When true, a bulk array operation returns the number of affected documents
     * (server-side `COLLECT WITH COUNT`) instead of the list of modified documents.
     */
    public const string COUNT = 'count' ;

    /**
     * The 'counter' parameter.
     * Name of the sibling field holding the length of an embedded array, kept in
     * sync (`LENGTH(...)`) on every mutation by {@see oihana\arango\models\traits\DocumentsArrayTrait}.
     */
    public const string COUNTER = 'counter' ;

    /**
     * The 'database' parameter.
     */
    public const string DATABASE = 'database' ;

    /**
     * The 'dateField' parameter.
     */
    public const string DATE_FIELD = 'dateField' ;

    /**
     * The 'default' parameter.
     */
    public const string DEFAULT = 'default' ;

    /**
     * The 'direction' parameter.
     */
    public const string DIRECTION = 'direction' ;

    /**
     * The 'doc' parameter.
     */
    public const string DOC = 'doc' ;

    /**
     * The 'docRef' parameter.
     */
    public const string DOC_REF = 'docRef' ;

    /**
     * The 'document' parameter.
     */
    public const string DOCUMENT = 'document' ;

    /**
     * The 'documents' parameter.
     */
    public const string DOCUMENTS = 'documents' ;

    /**
     * The 'edge' parameter.
     */
    public const string EDGE = 'edge' ;

    /**
     * The 'edges' parameter.
     */
    public const string EDGES = 'edges' ;

    /**
     * The 'excludes' parameter.
     */
    public const string EXCLUDES = 'excludes' ;

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
     * {@see \oihana\arango\models\traits\queries\FacetCountsQueryTrait::buildFacetCountsQuery()}.
     */
    public const string FACET_COUNTS = 'facetCounts' ;

    /**
     * The 'facetsOnly' flag — when truthy (and `Arango::FACET_COUNTS` is
     * requested), the document-fetch query is skipped: the list returns an empty
     * result set while the per-value facet counts (and an exact `total` computed
     * by {@see \oihana\arango\models\traits\documents\DocumentsCountTrait::count()})
     * are still returned. Useful for a faceted-search sidebar that only needs the
     * counts, not the documents.
     */
    public const string FACETS_ONLY = 'facetsOnly' ;

    /**
     * The 'field' parameter.
     */
    public const string FIELD = 'field' ;

    /**
     * The 'from' parameter.
     */
    public const string FROM = 'from' ;

    /**
     * The 'group' parameter — holds a high-level grouping spec
     * ({@see \oihana\arango\models\enums\Group}) translated into an AQL `COLLECT`
     * by {@see \oihana\arango\models\traits\aql\GroupTrait::prepareCollect()}.
     */
    public const string GROUP = 'group' ;

    /**
     * The 'groupable' parameter — the optional whitelist/mapping of groupable
     * dimensions (`urlKey => fieldPath`) consumed by
     * {@see \oihana\arango\models\traits\aql\GroupTrait::$groupable}.
     */
    public const string GROUPABLE = 'groupable' ;

    /**
     * The 'in' parameter.
     */
    public const string IN = 'in' ;

    /**
     * The 'indexes' parameter.
     */
    public const string INDEXES = 'indexes' ;

    /**
     * The 'init' parameter.
     */
    public const string INIT = 'init' ;

    /**
     * The 'insert' parameter.
     */
    public const string INSERT = 'insert' ;

    /**
     * The 'joins' parameter.
     */
    public const string JOINS = 'joins' ;

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
     * The 'lazy' parameter.
     */
    public const string LAZY = 'lazy' ;

    /**
     * The 'match' parameter.
     */
    public const string MATCH = 'match' ;

    /**
     * The 'mode' parameter.
     * Optional per-call override of an embedded array field's {@see oihana\arango\models\enums\ArrayMode}.
     */
    public const string MODE = 'mode' ;

    /**
     * The 'modelID' parameter.
     */
    public const string MODEL_ID = 'modelID' ;

    /**
     * The 'name' parameter.
     */
    public const string NAME = 'name' ;

    /**
     * The 'near' parameter — a geospatial anchor for distance sorting.
     *
     * Holds a `{ key, latitude, longitude }` object: the document attribute to
     * measure from (`key`) plus the reference point. It exposes the synthetic
     * `distance` sort key consumed by {@see \oihana\arango\models\traits\aql\SortTrait::prepareSort()}.
     */
    public const string NEAR = 'near' ;

    /**
     * The 'num' parameter.
     */
    public const string NUM = 'num' ;

    /**
     * The 'options' parameter.
     */
    public const string OPTIONS = 'options' ;

    /**
     * The 'position' parameter.
     */
    public const string POSITION = 'position' ;

    /**
     * The 'prefix' parameter.
     */
    public const string PREFIX = 'prefix' ;

    /**
     * The 'profile' parameter — when truthy, `list()` / `get()` run the query in
     * profiled mode (`true` → profile level 2) and the measurements are exposed
     * through {@see \oihana\arango\models\traits\ArangoTrait::getProfile()}.
     */
    public const string PROFILE = 'profile' ;

    /**
     * The 'property' parameter.
     */
    public const string PROPERTY = 'property' ;

    /**
     * The 'raw' parameter.
     */
    public const string RAW = 'raw' ;

    /**
     * The 'relations' parameter.
     */
    public const string RELATIONS = 'relations' ;

    /**
     * The 'removeKeys' parameter.
     */
    public const string REMOVE_KEYS = 'removeKeys' ;

    /**
     * The 'replace' parameter.
     */
    public const string REPLACE = 'replace' ;

    /**
     * The 'return' parameter.
     */
    public const string RETURN = 'return' ;

    /**
     * The 'route' parameter.
     */
    public const string ROUTE = 'route' ;

    /**
     * The 'side' parameter.
     */
    public const string SIDE = 'side' ;

    /**
     * The 'sortable' parameter.
     */
    public const string SORTABLE = 'sortable' ;

    /**
     * The 'skip' parameter.
     */
    public const string SKIP = 'skip' ;

    /**
     * The 'to' parameter.
     */
    public const string TO = 'to' ;

    /**
     * The 'touch' parameter.
     * Indicates if a document timestamp or date must be updated (modified=now())
     */
    public const string TOUCH = 'touch' ;

    /**
     * The 'unique' parameter.
     */
    public const string UNIQUE = 'unique' ;

    /**
     * The 'update' parameter.
     */
    public const string UPDATE = 'update' ;

    /**
     * The 'variables' parameter.
     */
    public const string VARIABLES = 'variables' ;

    /**
     * The 'varName' parameter.
     */
    public const string VAR_NAME = 'varName' ;
}


