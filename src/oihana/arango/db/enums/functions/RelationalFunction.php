<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

class RelationalFunction
{
    use FunctionCallTrait ;
    
    /**
     * The EQUAL function.
     */
    public const string EQUAL = 'equal' ;

    /**
     * The GREATER_THAN function.
     */
    public const string GREATER_THAN = 'greaterThan' ;

    /**
     * The GREATER_THAN_OR_EQUAL function.
     */
    public const string GREATER_THAN_OR_EQUAL = 'greaterThanOrEqual' ;

    /**
     * The IN function.
     */
    public const string IN = 'in' ;

    /**
     * The LESS_THAN function.
     */
    public const string LESS_THAN = 'lessThan' ;

    /**
     * The LESS_THAN_OR_EQUAL function.
     */
    public const string LESS_THAN_OR_EQUAL = 'lessThanOrEqual' ;

    /**
     * The LIKE function.
     */
    public const string LIKE = 'isLike' ;

    /**
     * The MATCH function.
     */
    public const string MATCH = 'match' ;

    /**
     * The NOT_EQUAL function.
     */
    public const string NOT_EQUAL = 'notEqual' ;

    /**
     * The NOT_IN function.
     */
    public const string NOT_IN = 'notIn' ;

    /**
     * The NOT_LIKE function.
     */
    public const string NOT_LIKE = 'notLike' ;

    /**
     * The NOT_MATCH function.
     */
    public const string NOT_MATCH = 'notMatch' ;
}
