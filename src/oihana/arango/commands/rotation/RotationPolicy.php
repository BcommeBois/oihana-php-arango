<?php

namespace oihana\arango\commands\rotation;

use DateTimeImmutable;

/**
 * The resolved archive-rotation policy consumed by the rotation engine.
 *
 * The internal, *resolved* counterpart of the `[arango.dump.retention]` config
 * ({@see RetentionOption}): `max_age` is already
 * turned into a `cutoff` instant and `max_total` into `maxTotalBytes`. Hydrated
 * from an `$init` array keyed by the class constants.
 *
 * @package oihana\arango\commands\rotation
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class RotationPolicy
{
    /**
     * Creates a new RotationPolicy.
     * @param array|object|null $init Initial values keyed by the class constants.
     */
    public function __construct( array|object|null $init = null )
    {
        $init = (array) ( $init ?? [] ) ;

        if( isset( $init[ self::KEEP ] ) )
        {
            $this->keep = (int) $init[ self::KEEP ] ;
        }

        if( isset( $init[ self::BUCKETS ] ) && is_array( $init[ self::BUCKETS ] ) )
        {
            $this->buckets = $init[ self::BUCKETS ] ;
        }

        if( isset( $init[ self::CUTOFF ] ) )
        {
            $this->cutoff = $init[ self::CUTOFF ] ;
        }

        if( isset( $init[ self::MAX_TOTAL_BYTES ] ) )
        {
            $this->maxTotalBytes = (int) $init[ self::MAX_TOTAL_BYTES ] ;
        }
    }

    /**
     * The `buckets` property name — per-bucket `keep` overrides.
     */
    public const string BUCKETS = 'buckets' ;

    /**
     * The `cutoff` property name — the oldest instant kept by `max_age`.
     */
    public const string CUTOFF = 'cutoff' ;

    /**
     * The `keep` property name — the number of recent archives kept per bucket.
     */
    public const string KEEP = 'keep' ;

    /**
     * The `maxTotalBytes` property name — the global disk cap in bytes.
     */
    public const string MAX_TOTAL_BYTES = 'maxTotalBytes' ;

    /**
     * Per-bucket `keep` overrides, keyed by the bucket suffix signature.
     */
    public array $buckets = [] ;

    /**
     * The oldest instant to keep (archives older than this are eligible), or null.
     */
    public ?DateTimeImmutable $cutoff = null ;

    /**
     * The number of most recent archives to keep per bucket, or null.
     */
    public ?int $keep = null ;

    /**
     * The global disk cap in bytes, or null.
     */
    public ?int $maxTotalBytes = null ;
}
