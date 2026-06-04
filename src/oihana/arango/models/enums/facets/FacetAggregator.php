<?php

namespace oihana\arango\models\enums\facets;

use oihana\arango\db\enums\functions\ArrayFunction;
use oihana\arango\db\enums\functions\NumericFunction;
use oihana\reflect\traits\ConstantsTrait;

/**
 * The aggregator catalogue of the {@see \oihana\arango\models\enums\Facet::EDGE_AGGREGATE}
 * and {@see \oihana\arango\models\enums\Facet::JOIN_AGGREGATE} facets.
 *
 * Each short code maps to the AQL aggregation function that wraps the related
 * sub-query, e.g. `avg` => `AVERAGE(FOR … RETURN related.field)`. The `count`
 * aggregator ignores the field and counts matched related documents
 * (`COUNT(FOR … RETURN 1)`), generalizing the existential `LENGTH(…) > 0`.
 *
 * @package oihana\arango\models\enums\facets
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class FacetAggregator
{
    use ConstantsTrait ;

    public const string AVG   = 'avg'   ; // AVERAGE(…)
    public const string COUNT = 'count' ; // COUNT(…)   (field-less, RETURN 1)
    public const string MAX   = 'max'   ; // MAX(…)
    public const string MIN   = 'min'   ; // MIN(…)
    public const string SUM   = 'sum'   ; // SUM(…)

    protected const array __ALIAS__ =
    [
        self::AVG   => NumericFunction::AVERAGE ,
        self::COUNT => ArrayFunction::COUNT ,
        self::MAX   => NumericFunction::MAX ,
        self::MIN   => NumericFunction::MIN ,
        self::SUM   => NumericFunction::SUM ,
    ];

    /**
     * Returns the AQL aggregation function name for a short aggregator code.
     *
     * @param mixed $value   The aggregator code (`avg`, `sum`, `min`, `max`, `count`).
     * @param mixed $default The value returned when the code is unknown (default `null`).
     *
     * @return mixed The AQL function name, or `$default` when unknown.
     */
    public static function getAlias( mixed $value , mixed $default = null ) :mixed
    {
        return self::__ALIAS__[ $value ] ?? $default ;
    }
}
