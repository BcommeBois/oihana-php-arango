<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Keys of the `POST /_api/explain` response (and of the nested execution plan).
 *
 * @see \oihana\arango\db\results\ExplainResult
 * @see https://docs.arangodb.com/stable/aql/execution-and-performance/explaining-queries/
 *
 * @package oihana\arango\db\enums
 */
class ExplainField
{
    use ConstantsTrait ;

    // --- Top-level response keys ---

    /** The execution plan object (present when `allPlans` is not set). */
    public const string PLAN = 'plan' ;

    /** The list of execution plans (present when `allPlans: true`). */
    public const string PLANS = 'plans' ;

    /** Whether the query result could be served from the query cache. */
    public const string CACHEABLE = 'cacheable' ;

    /** The optimizer warnings raised while planning the query. */
    public const string WARNINGS = 'warnings' ;

    /** Optimizer statistics (rulesExecuted, plansCreated, peakMemoryUsage, …). */
    public const string STATS = 'stats' ;

    // --- Plan keys ---

    /** The ordered execution nodes of the plan. */
    public const string NODES = 'nodes' ;

    /** The names of the optimizer rules that were applied. */
    public const string RULES = 'rules' ;

    /** The collections accessed by the query (`{ name, type }`). */
    public const string COLLECTIONS = 'collections' ;

    /** The variables used in the plan. */
    public const string VARIABLES = 'variables' ;

    /** The optimizer's estimated total cost of the plan. */
    public const string ESTIMATED_COST = 'estimatedCost' ;

    /** The optimizer's estimated number of result items. */
    public const string ESTIMATED_NR_ITEMS = 'estimatedNrItems' ;

    /** Whether the query writes data. */
    public const string IS_MODIFICATION_QUERY = 'isModificationQuery' ;

    // --- Node / index keys ---

    /** A plan node's type discriminator (e.g. `"IndexNode"`). */
    public const string TYPE = 'type' ;

    /** The collection a node operates on. */
    public const string COLLECTION = 'collection' ;

    /** The indexes used by an {@see self::INDEX_NODE}. */
    public const string INDEXES = 'indexes' ;

    /** An index/collection name. */
    public const string NAME = 'name' ;

    /** An index's covered fields. */
    public const string FIELDS = 'fields' ;

    /** Whether an index is unique. */
    public const string UNIQUE = 'unique' ;

    /** Whether an index is sparse. */
    public const string SPARSE = 'sparse' ;

    /** An index's selectivity estimate (0 … 1). */
    public const string SELECTIVITY_ESTIMATE = 'selectivityEstimate' ;

    /** The node type that carries the indexes actually used by the query. */
    public const string INDEX_NODE = 'IndexNode' ;
}
