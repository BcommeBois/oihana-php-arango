<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Vocabulary of the high-level grouping spec consumed by
 * {@see \oihana\arango\models\traits\aql\GroupTrait::prepareCollect()}.
 *
 * It is the `COLLECT` counterpart of {@see Facet}: a short, request-friendly
 * spec — `by` / `agg` / `count` / `alt` / `sort` — translated into the raw
 * {@see \oihana\arango\db\operations\aqlCollect()} spec. It reuses the existing
 * engines: {@see \oihana\arango\models\enums\facets\FacetAggregator} for the
 * aggregate functions and the `alt` engine
 * ({@see \oihana\arango\db\helpers\alterExpression()}) for grouping-key transforms.
 *
 * Held by the {@see \oihana\arango\enums\Arango::GROUP} key of a list query.
 *
 * @package oihana\arango\models\enums
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class Group
{
    use ConstantsTrait ;

    /**
     * The aggregates: `[ outName => 'func:field' ]` or `[ outName => [ func, field ] ]`,
     * where `func` is a {@see \oihana\arango\models\enums\facets\FacetAggregator} code
     * (`sum`, `avg`, `min`, `max`). E.g. `[ 'total' => 'sum:amount' ]` → `total = SUM(doc.amount)`.
     */
    public const string AGG = 'group_agg' ;

    /**
     * The `alt` transformation chains applied to grouping keys, keyed by group
     * variable name: `[ 'year' => 'dateYear' ]` → `year = DATE_YEAR(doc.created)`.
     * Same engine as the filter/facet `alt`.
     */
    public const string ALT = 'group_alt' ;

    /**
     * The grouping field(s). Accepts a CSV string (`'category'`, `'category,status'`),
     * a list (`['category','status']`), or an explicit `[ varName => field ]` map
     * (`['year' => 'created']`). Dotted fields become underscore variable names
     * (`address.city` → `address_city` reading `doc.address.city`).
     */
    public const string BY = 'group_by' ;

    /**
     * The per-group count. `true` adds `WITH COUNT INTO count`; a string sets the
     * variable name. When aggregates are present it is emitted as `name = LENGTH(1)`
     * instead (AGGREGATE and WITH COUNT being mutually exclusive in AQL).
     */
    public const string COUNT = 'group_count' ;

    /**
     * Default variable name used when {@see Group::COUNT} is `true`.
     */
    public const string COUNT_NAME = 'count' ;

    /**
     * The aggregate function code inside an array aggregate definition
     * (`[ Group::FUNC => 'sum', Group::FIELD => 'amount' ]`).
     */
    public const string FIELD = 'field' ;

    /**
     * @see Group::FIELD
     */
    public const string FUNC = 'func' ;

    /**
     * The sort applied to the grouped result, on group/aggregate variable names
     * (never on `doc`, which is out of scope after COLLECT). CSV with a leading
     * `-` for descending: `'-count'`, `'category,-total'`.
     */
    public const string SORT = 'group_sort' ;
}
