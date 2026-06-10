<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Statistics and extra Information attributes.
 *
 * ```
 * cursor.getExtra() → queryInfo
 * ```
 *
 * @package oihana\arango\db\enums
 */
class Extra
{
    use ConstantsTrait ;

    /**
     * Per-phase timings of a profiled run (`parsing`, `optimizing plan`, `executing`, …).
     */
    public const string PROFILE = 'profile' ;

    /**
     * Execution plan attached to a profiled run.
     */
    public const string PLAN = 'plan' ;

    public const string STATS    = 'stats' ;
    public const string WARNINGS = 'warnings' ;
}