<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\Extra;

/**
 * A typed view over the `extra` metadata of a **profiled** query run (a query
 * executed with the `profile` option). Combines the per-phase timings, the
 * execution {@see ExecutionStats}, and any optimizer warnings.
 *
 * Unlike {@see ExplainResult} — which analyses a query without running it — a
 * `ProfileResult` reflects a query that *did* run, so its timings and stats are
 * real measurements.
 *
 * @see \oihana\arango\db\ArangoDB::getProfile()
 *
 * @package oihana\arango\db\results
 * @since   1.1.0
 * @author  Marc Alcaraz
 */
readonly class ProfileResult
{
    /**
     * @param array<string,mixed> $extra The cursor's `getExtra()` payload (`stats`, `warnings`, `profile`, `plan`).
     */
    public function __construct( public array $extra )
    {
    }

    /**
     * The typed execution statistics (scanned, filtered, time, memory, …).
     */
    public function stats() : ExecutionStats
    {
        $stats = $this->extra[ Extra::STATS ] ?? [] ;
        return new ExecutionStats( is_array( $stats ) ? $stats : [] ) ;
    }

    /**
     * The per-phase timings of the run, in seconds, keyed by phase name
     * (`parsing`, `optimizing plan`, `executing`, `finalizing`, …).
     *
     * @return array<string,float>
     */
    public function timings() : array
    {
        $profile = $this->extra[ Extra::PROFILE ] ?? [] ;
        return is_array( $profile ) ? $profile : [] ;
    }

    /**
     * The sum of all per-phase timings, in seconds — a single "how long did it take".
     */
    public function totalTime() : float
    {
        return array_sum( array_map( 'floatval' , $this->timings() ) ) ;
    }

    /**
     * The optimizer warnings raised during the run.
     *
     * @return array<int,mixed>
     */
    public function warnings() : array
    {
        return array_values( (array) ( $this->extra[ Extra::WARNINGS ] ?? [] ) ) ;
    }

    /**
     * The execution plan attached to the profiled run, if any.
     *
     * @return array<string,mixed>
     */
    public function plan() : array
    {
        $plan = $this->extra[ Extra::PLAN ] ?? [] ;
        return is_array( $plan ) ? $plan : [] ;
    }

    /**
     * The raw, unmodified `extra` payload.
     *
     * @return array<string,mixed>
     */
    public function raw() : array
    {
        return $this->extra ;
    }
}
