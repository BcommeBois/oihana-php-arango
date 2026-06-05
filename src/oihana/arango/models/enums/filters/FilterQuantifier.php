<?php

namespace oihana\arango\models\enums\filters;

use oihana\arango\db\enums\ArrayComparator;
use oihana\reflect\traits\ConstantsTrait;

/**
 * The element-axis quantifier catalogue of array filters (the `quant` key).
 *
 * Each named code maps to the AQL quantifier keyword that answers « how many
 * elements of the array must satisfy the condition ». The numeric quantifier
 * `AT LEAST (n)` is parameterized (it carries a threshold), so it is built
 * dynamically by {@see \oihana\arango\db\helpers\resolveQuantifier()} from a bare
 * integer rather than being listed here.
 *
 * The same vocabulary drives both array surfaces:
 * - scalar arrays via the array comparison operator (`doc.scores ALL >= @v`);
 * - object arrays via the question-mark operator (`doc.reviews[? ALL FILTER …]`).
 *
 * @package oihana\arango\models\enums\filters
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
final class FilterQuantifier
{
    use ConstantsTrait ;

    public const string ALL  = 'all'  ; // ALL
    public const string ANY  = 'any'  ; // ANY  (default — at least one)
    public const string NONE = 'none' ; // NONE

    /**
     * The numeric quantifier prefix. The threshold `n` is supplied as a bare
     * integer in the `quant` value (`"quant": 3`) and resolved to
     * `AT LEAST (n)` by {@see \oihana\arango\db\helpers\resolveQuantifier()}.
     */
    public const string AT_LEAST = 'atLeast' ;

    protected const array __ALIAS__ =
    [
        self::ALL  => ArrayComparator::ALL  ,
        self::ANY  => ArrayComparator::ANY  ,
        self::NONE => ArrayComparator::NONE ,
    ];

    /**
     * Returns the AQL quantifier keyword for a named quantifier code.
     *
     * @param mixed $value   The quantifier code (`any`, `all`, `none`).
     * @param mixed $default The value returned when the code is unknown (default `null`).
     *
     * @return mixed The AQL keyword (`ANY`, `ALL`, `NONE`), or `$default` when unknown.
     */
    public static function getAlias( mixed $value , mixed $default = null ) :mixed
    {
        return self::__ALIAS__[ $value ] ?? $default ;
    }
}
