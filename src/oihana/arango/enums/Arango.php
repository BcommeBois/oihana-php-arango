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
     * The 'aggregatable' parameter — the optional whitelist/mapping of aggregatable
     * fields (`urlKey => fieldPath`) consumed by {@see \oihana\arango\models\traits\aql\GroupTrait::$aggregatable}.
     *
     * It is the `agg` counterpart of {@see Arango::GROUPABLE}, but it keys on the
     * **field token**, not on the output name: in `[ 'total' => 'sum:speed' ]` the
     * output name `total` is chosen freely by the client, while `speed` is what the
     * whitelist resolves (to `speed.value`, say). Accepts the three
     * {@see \oihana\arango\models\helpers\normalizeSortable()} notations.
     *
     * Absent (`null`), every projected path stays aggregatable — the historical
     * behaviour. Declaring it closes the gate; {@see Arango::AGGREGATABLE_POLICY}
     * chooses what happens to an undeclared aggregate.
     */
    public const string AGGREGATABLE = 'aggregatable' ;

    /**
     * The 'aggregatablePolicy' parameter — what happens to an aggregate whose field
     * is absent from {@see Arango::AGGREGATABLE}. One of the
     * {@see \oihana\arango\models\enums\AggregatablePolicy} codes.
     *
     * Defaults to {@see \oihana\arango\models\enums\AggregatablePolicy::DROP} when a
     * whitelist is declared, and to
     * {@see \oihana\arango\models\enums\AggregatablePolicy::OPEN} when none is.
     */
    public const string AGGREGATABLE_POLICY = 'aggregatablePolicy' ;

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
     * The 'binder' parameter — a `callable(mixed $value): string` that registers a
     * value in the query's bind map and returns its `@name` token.
     *
     * Carried through `$init` down to {@see \oihana\arango\models\enums\filters\FilterFunction::apply()},
     * where it turns the parameters of a **request-supplied** `alt` chain into bound
     * values instead of text pasted into the query. {@see \oihana\arango\db\helpers\alterExpression()}
     * only lets it through for a chain marked as request-supplied
     * ({@see \oihana\arango\db\helpers\AltChain}); a model declaration never sees it.
     */
    public const string BINDER = 'binder' ;

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
     * The 'eraseNull' flag of an in-place element edit — when truthy, the nulls a patch carries
     * are read as **erasures** instead of values.
     *
     * AQL `MERGE()` keeps a null: `{ "reason": null }` writes the attribute back as
     * null rather than taking it away, so an element rebuilt in place could never
     * lose an attribute it once carried. Set on {@see DocumentsArrayTrait::arrayUpdate()},
     * this flag wraps the merged element in an `UNSET()` of those keys — top-level
     * attributes only, like `UNSET()` itself.
     *
     * ⚠️ Not to be confused with {@see Arango::KEEP_NULL}, nor with the `keepNull`
     * of {@see Arango::OPTIONS}. The first is a *payload* marker, saying whether a
     * null a client sent survives the compress pass; the second is an ArangoDB
     * *server* option, deciding what the document-level `UPDATE` does with a null
     * attribute. This one operates in between the two, on the elements of an
     * embedded array — a place neither of them reaches.
     *
     * Opt-in: absent, an element keeps every attribute it carries.
     */
    public const string ERASE_NULL = 'eraseNull' ;

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
     * The 'init' parameter.
     */
    public const string INIT = 'init' ;

    /**
     * The 'itemKey' parameter — the attribute carried by each element of an embedded
     * array field, used to target a single element by identity instead of by value.
     *
     * Declared per field in the `AQL::ARRAYS` option
     * (`'tracks' => [ ArrayMode::LIST , Arango::ITEM_KEY => 'id' ]`), it switches the
     * element-level operations of {@see \oihana\arango\models\traits\DocumentsArrayTrait}
     * from structural equality (`REMOVE_VALUE(doc.tracks, @value)`) to a key match
     * (`doc.tracks[* FILTER CURRENT.id != @value]`), and enables `arrayUpdate()`.
     *
     * When absent, every array operation keeps its by-value behaviour.
     *
     * Unlike {@see Arango::KEY} — which identifies the *document* — this identifies an
     * *element inside* one of its array fields.
     */
    public const string ITEM_KEY = 'itemKey' ;

    /**
     * Container ids of the {@see \oihana\interfaces\Invalidable} services whose
     * cached state this collection feeds. They are invalidated on every write.
     */
    public const string INVALIDATES = 'invalidates' ;

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
     * The 'omitWhen' parameter — the predicates deciding which **attributes of the
     * payload are dropped before a write**, consumed by
     * {@see \oihana\arango\models\traits\aql\PrepareDocumentTrait::prepareDocumentClause()}
     * on `insert`, `update`, `replace` and `upsert`.
     *
     * Each entry is a `callable( mixed $value , string $key = ) : bool` answering
     * "do I drop this attribute?". Omitting the key entirely applies the default —
     * `fn( $value ) => is_null( $value )`, which is what keeps a `PATCH` carrying
     * only some fields from overwriting the others with null. Passing `[]`
     * disables the compression, so every attribute is written as submitted.
     *
     * ```php
     * $model->update
     * ([
     *     Arango::VALUE     => 'k1' ,
     *     Arango::DOC       => [ 'name' => 'Marc' , 'nickname' => null ] ,
     *     Arango::OMIT_WHEN => [ fn( $value ) => $value === null || $value === '' ] ,
     * ]) ;
     * ```
     *
     * **It replaces {@see Arango::CONDITIONS} on the write path.** That key now means
     * one thing everywhere — AQL predicate strings appended to the query's `FILTER`,
     * on the reads, on `delete()`, and on `update()` / `replace()` too. Carrying two
     * meanings under one name meant a cross-cutting hook posing a scope on every
     * model call answered `All conditions in the array must be callable` on the
     * writes; posing one now scopes them.
     *
     * `CONDITIONS` is still honoured here when it carries callables, with a
     * deprecation logged, until the next release removes that fallback. A mixed
     * array is split rather than refused: the callables compress the payload, the
     * strings go to the `FILTER`.
     */
    public const string OMIT_WHEN = 'omitWhen' ;

    /**
     * The 'patch' parameter — the partial object merged into the array element
     * targeted by {@see Arango::ITEM_KEY}, consumed by {@see DocumentsArrayTrait::arrayUpdate()}.
     *
     * The merge is shallow (`MERGE(CURRENT, @patch)`): the attributes it carries are
     * overwritten, the others are left untouched.
     */
    public const string PATCH = 'patch' ;

    /**
     * The 'position' parameter.
     */
    public const string POSITION = 'position' ;

    /**
     * The 'positionKey' parameter — the attribute of each element of an embedded array
     * field that carries its **rank**, kept in sync with the element order.
     *
     * Declared per field in the `AQL::ARRAYS` option
     * (`'lines' => [ ArrayMode::LIST , Arango::ITEM_KEY => 'id' , Arango::POSITION_KEY => 'position' ]`),
     * it makes every write of {@see DocumentsArrayTrait}
     * renumber the whole array from its indices — so an element moved by drag and drop
     * never leaves a stale rank behind.
     *
     * The numbering is **zero-based**, and the attribute must be a flat name (a nested
     * path could not be written back). When absent, no element is ever renumbered.
     *
     * Unlike {@see Arango::POSITION} — the target index of a single move — this names an
     * attribute of the elements themselves.
     */
    public const string POSITION_KEY = 'positionKey' ;

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


