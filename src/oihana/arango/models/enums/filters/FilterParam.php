<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

class FilterParam
{
    use ConstantsTrait ;

    /**
     * The aggregator name of an aggregate facet (`avg`, `sum`, `min`, `max`, `count`).
     */
    public const string AGG = 'agg' ;

    /**
     * The 'all' parameter or value.
     */
    public const string ALL = 'all' ;

    /**
     * Alter the attribute to filter.
     */
    public const string ALT = 'alt' ;

    /**
     * Defines the specific index in an array key to filter.
     */
    public const string AT = 'at' ;

    /**
     * The exponent attribute to filter a key with the POW(base,exp) method.
     */
    public const string EXP = 'exp' ;

    /**
     * The related field aggregated by an aggregate facet.
     */
    public const string FIELD = 'field' ;

    /**
     * The format attribute.
     */
    public const string FORMAT = 'format' ;

    /**
     * The key of the attribute to filter.
     */
    public const string KEY = 'key' ;

    /**
     * The length attribute.
     */
    public const string LENGTH = 'length' ;

    /**
     * The match attribute.
     */
    public const string MATCH = 'match' ;

    /**
     * The upper bound of a `between` range filter.
     */
    public const string MAX = 'max' ;

    /**
     * The method attribute.
     */
    public const string METHOD = 'method' ;

    /**
     * The lower bound of a `between` range filter.
     */
    public const string MIN = 'min' ;

    /**
     * The operator of the condition.
     */
    public const string OP = 'op' ;

    /**
     * The position attribute.
     */
    public const string POS = 'pos' ;

    /**
     * The element-axis quantifier of an array filter: how many elements must
     * satisfy the condition. Values: `any` (default) / `all` / `none` / an
     * integer `n` (= at least `n`). Works on scalar arrays (array comparison
     * operator, e.g. `doc.scores ALL >= @v`) and on object arrays (question-mark
     * operator, e.g. `doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]`).
     */
    public const string QUANT = 'quant' ;

    /**
     * The scope of the attribute.
     */
    public const string SCOPE = 'scope' ;

    /**
     * The start attribute.
     */
    public const string START = 'start' ;

    /**
     * The timezone value to set a date condition.
     */
    public const string TZ = 'tz' ;

    /**
     * The type of the attribute to filter.
     */
    public const string TYPE  = 'type' ;

    /**
     * The unit of the attribute to filter.
     */
    public const string UNIT  = 'unit' ;

    /**
     * The value to evaluates to filter an attribute.
     */
    public const string VAL = 'val' ;
}