<?php

namespace oihana\arango\models\enums\filters;

use oihana\arango\db\enums\Logic;
use oihana\reflect\traits\ConstantsTrait;

class FilterLogic
{
    use ConstantsTrait ;

    public const string AND = 'and' ;
    public const string NOT = 'not' ;
    public const string OR  = 'or' ;

    protected const array __ALIAS__ =
    [
        self::AND => Logic::AND ,
        self::NOT => Logic::NOT ,
        self::OR  => Logic::OR  ,
    ];

    /**
     * Returns a valid filter logic operator alias or the default alias.
     * @param mixed $value
     * @param mixed|null $default
     * @return mixed
     */
    public static function getAlias( mixed $value , mixed $default = Logic::AND ): mixed
    {
        return self::__ALIAS__[ $value ] ?? $default ;
    }
}