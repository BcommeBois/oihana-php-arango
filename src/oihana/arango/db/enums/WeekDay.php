<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class WeekDay
{
    use ConstantsTrait ;

    public const int SUNDAY    = 0 ;
    public const int MONDAY    = 1 ;
    public const int TUESDAY   = 2 ;
    public const int WEDNESDAY = 3 ;
    public const int THURSDAY  = 4 ;
    public const int FRIDAY    = 5 ;
    public const int SATURDAY  = 6 ;
}
