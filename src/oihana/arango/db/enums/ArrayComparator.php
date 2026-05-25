<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * In AQL, most comparison operators also exist as an array variant.
 * @see https://docs.arangodb.com/stable/aql/operators/
 */
class ArrayComparator
{
    use ConstantsTrait ;

    /**
     * The ALL operator.
     * @example
     * ```
     * [ 1, 2, 3 ]  ALL IN  [ 2, 3, 4 ]  // false
     * [ 1, 2, 3 ]  ALL IN  [ 1, 2, 3 ]  // true
     * [ 1, 2, 3 ]  ALL >  2             // false
     * [ 1, 2, 3 ]  ALL >  0             // true
     * ```
     */
    public const string ALL = 'ALL'  ;

    /**
     * The ANY operator.
     * @example
     * ```
     * [ 1, 2, 3 ] ANY ==  2 // true
     * [ 1, 2, 3 ] ANY ==  4 // false
     * [ 1, 2, 3 ] ANY >  0  // true
     * [ 1, 2, 3 ] ANY <=  1 // true
     * ```
     */
    public const string ANY = 'ANY' ;

    /**
     * The NONE operator.
     * @example
     * ```
     * [ 1, 2, 3 ] NONE <  99 // false
     * [ 1, 2, 3 ] NONE >  10 // true
     * ```
     */
    public const string NONE = 'NONE' ;
}
