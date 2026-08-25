<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Declaration keys of a **bound** definition (`AQL::BOUNDS`), consumed by {@see BoundsQueryTrait}.
 *
 * A bound entry is either a bare field name or a keyed definition holding these
 * options. `PROPERTY` targets the aggregated path; the others **exclude** values
 * from the `MIN` / `MAX` / `count` aggregate (a common data-quality need — e.g.
 * a `0` that encodes "not filled" must not drag the observed minimum to zero).
 * An excluded value is mapped to `null`, which `MIN` / `MAX` already ignore, so
 * the exclusion is **per field** — a document dropped from one field's extent
 * still counts for the others. The exclusion options combine with a logical AND.
 *
 * The permission gate is **not** declared here: like facets, a bound inherits or
 * carries its `Field::REQUIRES` on the {@see ield} enum.
 *
 * @package oihana\arango\models\enums
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class Bound
{
    use ConstantsTrait ;

    /**
     * Sentinel value(s) to exclude from the aggregate — a scalar (`0`) or a list
     * (`[ 0, 5, 15 ]`). Emits `NOT IN [ … ]`.
     */
    public const string IGNORE = 'ignore' ;

    /**
     * The upper bound of the **accepted domain**: values above it are excluded
     * (`<value> <= @max`). Note: this is the accepted *input* ceiling, distinct
     * from the observed `max` returned in the output.
     */
    public const string MAX = 'max' ;

    /**
     * The lower bound of the **accepted domain**: values below it are excluded
     * (`<value> >= @min`). Note: this is the accepted *input* floor, distinct
     * from the observed `min` returned in the output.
     */
    public const string MIN = 'min' ;

    /**
     * When `true`, only strictly positive values enter the aggregate
     * (`<value> > 0`) — the shorthand for the frequent "0 means not filled" case.
     */
    public const string POSITIVE = 'positive' ;

    /**
     * The document property targeted by the bound (alias of the bound key, `[*]`
     * array-expansion path accepted).
     */
    public const string PROPERTY = 'property' ;

    /**
     * The permission subject(s) required to bounds the result — a string or a list (OR semantics),
     * mirroring {@see Field::REQUIRES} for projections.
     */
    public const string REQUIRES = 'requires' ;

    /**
     * The **measured field of the related document**, on a bound that reaches
     * through a relation — an edge traversal (`AQL::EDGE`) or a key-join
     * (`AQL::COLLECTION`).
     *
     * It is required on such a bound and has no default: unlike a facet bucket,
     * which falls back on `_key`, there is no field a numeric extent could
     * sensibly be measured on by guessing. `PROPERTY` cannot carry it either —
     * on a join it already names the **main side** of the join predicate, the
     * same meaning it has for facets.
     */
    public const string VALUE = 'value' ;
}
