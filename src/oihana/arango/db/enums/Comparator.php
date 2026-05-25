<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class Comparator
{
    use ConstantsTrait ;
    
    /**
     * The EQUAL operator '=='.
     */
    public const string EQUAL = '==' ;

    /**
     * The GREATER_THAN operator '>'.
     */
    public const string GREATER_THAN = '>' ;

    /**
     * The GREATER_THAN_OR_EQUAL operator '>='.
     */
    public const string GREATER_THAN_OR_EQUAL = '>=' ;

    /**
     * The IN operator 'IN', test if a value is contained in an array.
     */
    public const string IN = 'IN' ;

    /**
     * The LESS_THAN operator '<'.
     */
    public const string LESS_THAN = '<' ;

    /**
     * The LESS_THAN_OR_EQUAL operator '<='.
     */
    public const string LESS_THAN_OR_EQUAL = '<=' ;

    /**
     * The LIKE operator 'LIKE', tests if a string value matches a pattern.
     */
    public const string LIKE = 'LIKE' ;

    /**
     * The MATCH comparator '=~', tests if a string value matches a regular expression.
     */
    public const string MATCH = '=~' ;

    /**
     * The NOT_EQUAL operator '!='.
     */
    public const string NOT_EQUAL = '!=' ;

    /**
     * The NOT_IN operator 'NOT IN', test if a value is not contained in an array.
     */
    public const string NOT_IN = 'NOT IN' ;

    /**
     * The NOT_LIKE operator 'NOT LIKE', tests if a string value does not match a pattern.
     */
    public const string NOT_LIKE = 'NOT LIKE' ;

    /**
     * The NOT_MATCH comparator '!~', tests if a string value dos not match a regular expression.
     */
    public const string NOT_MATCH = '!~' ;
}
