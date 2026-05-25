<?php

namespace oihana\arango\db\helpers;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\arango\db\enums\functions\CastingFunction;
use oihana\arango\db\enums\functions\CheckFunction;
use oihana\arango\db\enums\functions\DateFunction;
use oihana\arango\db\enums\functions\DocumentFunction;
use oihana\arango\db\enums\functions\GeoFunction;
use oihana\arango\db\enums\functions\MiscFunction;
use oihana\arango\db\enums\functions\NumericFunction;
use oihana\arango\db\enums\functions\RelationalFunction;
use oihana\arango\db\enums\functions\StringFunction;

/**
 * Check if a string is a valid AQL function call expression.
 *
 * This function checks if the provided expression starts with a known AQL
 * function name (case-insensitively) followed by parentheses.
 *
 * @param string $expression The expression to check, e.g., 'COUNT(doc)'.
 *
 * @return bool True if it's a valid and known AQL function call.
 *
 * @example
 * ```php
 * isAQLFunction('COUNT(doc)'); // true
 * isAQLFunction('CONCAT("a", "b")'); // true
 * isAQLFunction('concat("a", "b")'); // false
 * isAQLFunction('UNKNOWN_FUNCTION(1)'); // false
 * isAQLFunction('NOT_A_FUNCTION'); // false
 * ```
 */
function isAQLFunction( string $expression ): bool
{
    static $cache = [] ;

    $expression = trim( $expression ) ;

    if ( isset( $cache[ $expression ] ) )
    {
        return $cache[ $expression ] ;
    }

    $functionClasses =
    [
        ArrayFunction::class,
        CastingFunction::class,
        CheckFunction::class,
        DateFunction::class,
        DocumentFunction::class,
        GeoFunction::class,
        MiscFunction::class,
        NumericFunction::class,
        RelationalFunction::class,
        StringFunction::class,
    ];

    if ( array_any( $functionClasses , fn( $class ) => $class::isFunctionCall( $expression ) ) )
    {
        return $cache[ $expression ] = true ;
    }

    return $cache[$expression] = false;
}
