<?php

namespace oihana\arango\commands\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The keys of the `[arango.dump.retention]` config section — the archive
 * rotation policy of the `dump` action.
 *
 * ```toml
 * [arango.dump.retention]
 * keep      = 7          # keep the N most recent archives per bucket
 * max_age   = "P30D"     # ISO 8601 duration: drop archives older than this
 * max_total = "5G"       # global disk cap (size), applied last
 * auto      = true       # prune automatically after each successful dump
 *
 * [arango.dump.retention.buckets]
 * "mydb-partial-pre-migration" = 3
 * ```
 *
 * @package oihana\arango\commands\options
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class RetentionOption
{
    use ConstantsTrait ;

    /**
     * Prune automatically after each successful dump (opt-in, default off).
     */
    public const string AUTO = 'auto' ;

    /**
     * Per-bucket overrides of `keep`, keyed by the archive suffix signature.
     */
    public const string BUCKETS = 'buckets' ;

    /**
     * The number of most recent archives to keep per bucket.
     */
    public const string KEEP = 'keep' ;

    /**
     * The maximum age as an ISO 8601 duration (`P30D`, `P6M`, `P1Y`, …).
     */
    public const string MAX_AGE = 'max_age' ;

    /**
     * The global disk cap as a human size (`5G`, `500M`, …) or a byte count.
     */
    public const string MAX_TOTAL = 'max_total' ;
}
