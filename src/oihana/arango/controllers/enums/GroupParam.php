<?php

namespace oihana\arango\controllers\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The short HTTP keys of the grouping surface, mapped by
 * {@see \oihana\arango\controllers\traits\PrepareGroupTrait::prepareGroup()} onto
 * the model-side {@see \oihana\arango\models\enums\Group} vocabulary.
 *
 * - `?group={...}` carries the full spec using these short keys
 *   (`by` / `agg` / `count` / `sort` / `alt`).
 * - `?groupBy=` (the vendor {@see \oihana\controllers\enums\ControllerParam::GROUP_BY})
 *   is the CSV shortcut for `by`, implying a per-group count.
 *
 * @package oihana\arango\controllers\enums
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class GroupParam
{
    use ConstantsTrait ;

    /**
     * The aggregates map (`{"total":"sum:amount"}`). Maps to {@see \oihana\arango\models\enums\Group::AGG}.
     */
    public const string AGG = 'agg' ;

    /**
     * The grouping-key `alt` transform chains. Maps to {@see \oihana\arango\models\enums\Group::ALT}.
     */
    public const string ALT = 'alt' ;

    /**
     * The grouping field(s). Maps to {@see \oihana\arango\models\enums\Group::BY}.
     */
    public const string BY = 'by' ;

    /**
     * The per-group count flag/name. Maps to {@see \oihana\arango\models\enums\Group::COUNT}.
     */
    public const string COUNT = 'count' ;

    /**
     * The `?group=` query parameter holding the full JSON spec.
     */
    public const string GROUP = 'group' ;

    /**
     * The sort on group/aggregate variables. Maps to {@see \oihana\arango\models\enums\Group::SORT}.
     */
    public const string SORT = 'sort' ;
}
