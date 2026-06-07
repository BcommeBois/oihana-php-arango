<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Defines the ordering and uniqueness mode of an embedded array field managed by
 * {@see oihana\arango\models\traits\DocumentsArrayTrait}.
 *
 * The mode is declared once per field through the `arrays` model option and drives
 * how mutations behave — whether duplicates are allowed, whether values are kept
 * sorted, and whether positional moves are supported:
 *
 * - `LIST`       : ordered by insertion, duplicates allowed, `arrayMove()` supported.
 * - `SET`        : ordered by insertion, values kept unique, `arrayMove()` supported.
 * - `SORTED_SET` : values kept unique AND sorted by value; `arrayMove()` is meaningless
 *                  (the sort wins) and therefore unsupported.
 *
 * Example:
 * ```php
 * 'arrays' =>
 * [
 *     'tracks' => [ ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ],
 *     'tags'   => ArrayMode::SET ,
 *     'genres' => ArrayMode::SORTED_SET ,
 * ]
 * ```
 *
 * @package oihana\arango\models\enums
 */
class ArrayMode
{
    use ConstantsTrait ;

    /**
     * Ordered by insertion, duplicates allowed. Positional moves are supported.
     */
    public const string LIST = 'list' ;

    /**
     * Unique values, ordered by insertion. Positional moves are supported.
     */
    public const string SET = 'set' ;

    /**
     * Unique values kept sorted by value. Positional moves are unsupported.
     */
    public const string SORTED_SET = 'sortedSet' ;
}
