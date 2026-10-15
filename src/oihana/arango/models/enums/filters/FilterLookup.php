<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The bounds of the filters written as a lookup of an element of a list
 * (`@v IN doc.orders[*].id`), the spelling an index on that element serves.
 *
 * @package oihana\arango\models\enums\filters
 * @author  Marc Alcaraz
 */
class FilterLookup
{
    use ConstantsTrait ;

    /**
     * The longest list of values (`op: "in"`) written as one lookup per value,
     * `(@a IN doc.orders[*].id OR @b IN doc.orders[*].id …)`. A longer list keeps the
     * existential spelling, which answers the same documents without the index.
     *
     * 🚨 **Measured, not chosen by taste.** On a collection of 20 000 documents with an
     * index on `[ "kind" , "orders[*].id" ]`, ArangoDB looked every value up in the index
     * up to 250 values, dropped the index by itself from 255 — a full read, no error — and
     * refused the query outright towards 500 (« too much nesting or too many objects »).
     * The bound keeps a wide margin under the first threshold, because a real query
     * carries other conditions beside the list, and every condition counts.
     */
    public const int MAX_VALUES = 100 ;
}
