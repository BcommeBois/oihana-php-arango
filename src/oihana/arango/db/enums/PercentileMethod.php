<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class PercentileMethod
{
    use ConstantsTrait ;

    public const string INTERPOLATION = 'interpolation' ;
    public const string RANK          = 'rank' ;
}