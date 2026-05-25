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

    public const string STATS    = 'stats' ;
    public const string WARNINGS = 'warnings' ;
}