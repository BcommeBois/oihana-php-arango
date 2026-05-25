<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class Logic
{
    use ConstantsTrait ;

    public const string AND = '&&' ;
    public const string NOT = '!'  ;
    public const string OR  = '||' ;

    /**
     * Normalize a logical operator.
     *
     * Returns the operator if it's supported (AND, OR), otherwise defaults to AND.
     *
     * @param string|null $operator
     * @return string
     */
    public static function normalize( ?string $operator ): string
    {
        return $operator === self::OR ? self::OR : self::AND;
    }
}