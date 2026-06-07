<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Indicates on which side a value is inserted into an embedded array field by
 * {@see oihana\arango\models\traits\DocumentsArrayTrait::arrayInsert()}.
 *
 * - `LEFT`  : prepend — the value(s) are added at the beginning of the array (AQL `UNSHIFT`/`APPEND`).
 * - `RIGHT` : append  — the value(s) are added at the end of the array (default).
 *
 * @package oihana\arango\models\enums
 */
class Side
{
    use ConstantsTrait ;

    /**
     * Prepend — insert at the beginning of the array.
     */
    public const string LEFT = 'left' ;

    /**
     * Append — insert at the end of the array (default).
     */
    public const string RIGHT = 'right' ;
}
