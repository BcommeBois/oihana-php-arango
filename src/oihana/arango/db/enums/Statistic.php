<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Represents the possible statistics attributes.
 *
 * In ArangoDB, all queries that have successfully run to completion return statistics about the execution.
 *
 * @package oihana\arango\db\enums
 *
 * @see https://docs.arango.ai/arangodb/stable/aql/execution-and-performance/query-statistics
 */
class Statistic
{
    use ConstantsTrait ;

    /**
     * The total number of index entries read from in-memory caches for indexes of type edge or persistent.
     *
     * This value is only non-zero when reading from indexes that have an in-memory cache enabled,
     * and when the query allows using the in-memory cache (i.e. using equality lookups on all index attributes).
     */
    public const string CACHE_HITS = 'cacheHits' ;

    /**
     * The total number of cache read attempts for index entries that could not be served
     * from in-memory caches for indexes of type edge or persistent.
     *
     * This value is only non-zero when reading from indexes that have an in-memory cache enabled,
     * the query allows using the in-memory cache `
     * (i.e. using equality lookups on all index attributes) and the looked up values are not present in the cache.
     */
    public const string CACHE_MISSES = 'cacheMisses' ;

    /**
     * The total number of cursor objects created during query execution.
     * Cursor objects are created for index lookups.
     */
    public const string CURSORS_CREATED = 'cursorsCreated' ;

    /**
     * The total number of times an existing cursor object was repurposed.
     *
     * Repurposing an existing cursor object is normally more efficient compared to destroying
     * an existing cursor object and creating a new one from scratch.
     */
    public const string CURSORS_REARMED = 'cursorsRearmed' ;

    /**
     * The number of real document lookups caused by late materialization as well as IndexNodes
     * that had to load document attributes not covered by the index.
     *
     * This is how many documents had to be fetched from storage after an index scan
     * that initially covered the attribute access for these documents.
     */
    public const string DOCUMENT_LOOKUPS = 'documentLookups' ;

    /**
     * The query execution time (wall-clock time) in seconds.
     */
    public const string EXECUTION_TIME = 'executionTime' ;

    /**
     * The total number of documents removed after executing a filter condition
     * in a FilterNode or another node that post-filters data.
     *
     * Note that nodes of the IndexNode type can also filter documents by selecting only
     * the required index range from a collection, and the filtered value only indicates
     * how much filtering was done by a post-filter in the IndexNode itself or following FilterNode nodes.
     *
     * Nodes of the EnumerateCollectionNode and TraversalNode types can also apply filter conditions
     * and can report the number of filtered documents.
     */
    public const string FILTERED = 'filtered' ;

    /**
     * The optional total number of documents that matched the search condition
     * if the query’s final top-level LIMIT operation were not present.
     *
     * This attribute may only be returned if the fullCount option was set when starting the query
     * and only contains a sensible value if the query contains a LIMIT operation on the top level.
     */
    public const string FULL_COUNT = 'fullCount' ;

    /**
     * The total number of cluster-internal HTTP requests performed.
     */
    public const string HTTP_REQUESTS = 'httpRequests' ;

    /**
     * The total number of intermediate commits the query has performed.
     *
     * This number can only be greater than zero for data-modification queries that perform modifications
     * beyond the --rocksdb.intermediate-commit-count or --rocksdb.intermediate-commit-size thresholds.
     *
     * In a cluster, the intermediate commits are tracked per DB-Server that participates
     * in the query and are summed up in the end.
     */
    public const string INTERMEDIATE_COMMITS = 'intermediateCommits' ;

    /**
     * When the query is executed with the option profile set to at least 2,
     * then this value contains runtime statistics per query execution node.
     *
     * For a human readable output you can execute db._profileQuery(<query>, <bind-vars>) in the arangosh.
     */
    public const string NODES = 'nodes' ;

    /**
     * The maximum memory usage of the query while it was running.
     *
     * In a cluster, the memory accounting is done per shard, and the memory usage reported
     * is the peak memory usage value from the individual shards.
     *
     * Note that to keep things light-weight, the per-query memory usage is tracked on a relatively high level,
     * not including any memory allocator overhead nor any memory used for temporary results calculations
     * (e.g. memory allocated/deallocated inside AQL expressions and function calls).
     */
    public const string PEAK_MEMORY_USAGE = 'peakMemoryUsage' ;

    /**
     * The total number of documents iterated over when scanning a collection using an index.
     *
     * Documents scanned by subqueries are included in the result, but operations triggered by built-in
     * or user-defined AQL functions are not.
     */
    public const string SCANNED_INDEX = 'scannedIndex' ;

    /**
     * The total number of documents iterated over when scanning a collection without an index.
     *
     * Documents scanned by subqueries are included in the result,
     * but operations triggered by built-in or user-defined AQL functions are not.
     */
    public const string SCANNED_FULL = 'scannedFull' ;

    /**
     * The number of seek calls done by RocksDB iterators for merge joins (JoinNode in the execution plan).
     */
    public const string SEEKS = 'seeks' ;

    /**
     * The total number of data-modification operations successfully executed.
     * This is equivalent to the number of documents created, updated,
     * or removed by INSERT, UPDATE, REPLACE, REMOVE, or UPSERT operations.
     */
    public const string WRITE_EXECUTED = 'writesExecuted' ;

    /**
     * The total number of data-modification operations that were unsuccessful,
     * but have been ignored because of the ignoreErrors query option.
     */
    public const string WRITE_IGNORED = 'writesIgnored' ;
}