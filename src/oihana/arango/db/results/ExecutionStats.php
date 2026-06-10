<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\Statistic;

/**
 * A typed view over the execution statistics returned in a cursor's `extra.stats`
 * (populated for a profiled run, i.e. when the query ran with the `profile` option).
 *
 * Surfaces the numbers you actually look at when a query is slow — how much was
 * scanned from indexes vs. full collections, how much was filtered, how long it
 * took, and the peak memory used.
 *
 * @see ProfileResult
 * @see Statistic
 *
 * @package oihana\arango\db\results
 * @since   1.1.0
 * @author  Marc Alcaraz
 */
readonly class ExecutionStats
{
    /**
     * @param array<string,mixed> $data The raw `extra.stats` array.
     */
    public function __construct( public array $data )
    {
    }

    /** Total query execution time, in seconds. */
    public function executionTime() : float
    {
        return (float) ( $this->data[ Statistic::EXECUTION_TIME ] ?? 0.0 ) ;
    }

    /** Number of documents read by scanning collections in full (no index). */
    public function scannedFull() : int
    {
        return (int) ( $this->data[ Statistic::SCANNED_FULL ] ?? 0 ) ;
    }

    /** Number of documents read by scanning index ranges. */
    public function scannedIndex() : int
    {
        return (int) ( $this->data[ Statistic::SCANNED_INDEX ] ?? 0 ) ;
    }

    /** Number of documents removed by `FILTER` conditions after being read. */
    public function filtered() : int
    {
        return (int) ( $this->data[ Statistic::FILTERED ] ?? 0 ) ;
    }

    /** The total result count ignoring `LIMIT`, or null when `fullCount` was not requested. */
    public function fullCount() : ?int
    {
        return isset( $this->data[ Statistic::FULL_COUNT ] ) ? (int) $this->data[ Statistic::FULL_COUNT ] : null ;
    }

    /** Peak memory usage of the query, in bytes. */
    public function peakMemoryUsage() : int
    {
        return (int) ( $this->data[ Statistic::PEAK_MEMORY_USAGE ] ?? 0 ) ;
    }

    /** Number of documents inserted/updated/replaced/removed. */
    public function writesExecuted() : int
    {
        return (int) ( $this->data[ Statistic::WRITE_EXECUTED ] ?? 0 ) ;
    }

    /** Number of write operations ignored (e.g. `ignoreErrors`). */
    public function writesIgnored() : int
    {
        return (int) ( $this->data[ Statistic::WRITE_IGNORED ] ?? 0 ) ;
    }

    /** Number of documents looked up by their `_id` / `_key` after an index scan. */
    public function documentLookups() : int
    {
        return (int) ( $this->data[ Statistic::DOCUMENT_LOOKUPS ] ?? 0 ) ;
    }

    /** Number of HTTP requests the coordinator made to DB-servers (cluster). */
    public function httpRequests() : int
    {
        return (int) ( $this->data[ Statistic::HTTP_REQUESTS ] ?? 0 ) ;
    }

    /** Number of index entries served from an in-memory index cache. */
    public function cacheHits() : int
    {
        return (int) ( $this->data[ Statistic::CACHE_HITS ] ?? 0 ) ;
    }

    /** Number of index lookups that missed the in-memory index cache. */
    public function cacheMisses() : int
    {
        return (int) ( $this->data[ Statistic::CACHE_MISSES ] ?? 0 ) ;
    }

    /**
     * A raw statistic by key (see {@see Statistic}), with a fallback default.
     */
    public function get( string $key , mixed $default = null ) : mixed
    {
        return $this->data[ $key ] ?? $default ;
    }

    /**
     * The raw, unmodified `extra.stats` array.
     *
     * @return array<string,mixed>
     */
    public function raw() : array
    {
        return $this->data ;
    }
}
