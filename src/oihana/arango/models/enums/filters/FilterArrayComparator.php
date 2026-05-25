<?php

namespace oihana\arango\models\enums\filters;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;
use oihana\reflect\traits\ConstantsTrait;

final class FilterArrayComparator
{
    use ConstantsTrait ;

    // ALL
    public const string ALL_EQ  = 'all.eq'  ; // all equals (default)
    public const string ALL_GE  = 'all.ge'  ; // all greater than or equals
    public const string ALL_GT  = 'all.gt'  ; // all greater than
    public const string ALL_IN  = 'all.in'  ; // all in
    public const string ALL_LE  = 'all.le'  ; // all less than or equals
    public const string ALL_LT  = 'all.lt'  ; // all less than
    public const string ALL_NE  = 'all.ne'  ; // all not equals
    public const string ALL_NIN = 'all.nin' ; // all not in

    // ANY
    public const string ANY_EQ  = 'any.eq'  ; // any equals (default)
    public const string ANY_GE  = 'any.ge'  ; // any greater than or equals
    public const string ANY_GT  = 'any.gt'  ; // any greater than
    public const string ANY_IN  = 'any.in'  ; // any in
    public const string ANY_LE  = 'any.le'  ; // any less than or equals
    public const string ANY_LT  = 'any.lt'  ; // any less than
    public const string ANY_NE  = 'any.ne'  ; // any not equals
    public const string ANY_NIN = 'any.nin' ; // any not in

    // NONE
    public const string NONE_EQ  = 'none.eq'  ; // none equals (default)
    public const string NONE_GE  = 'none.ge'  ; // none greater than or equals
    public const string NONE_GT  = 'none.gt'  ; // none greater than
    public const string NONE_IN  = 'none.in'  ; // none in
    public const string NONE_LE  = 'none.le'  ; // none less than or equals
    public const string NONE_LT  = 'none.lt'  ; // none less than
    public const string NONE_NE  = 'none.ne'  ; // none not equals
    public const string NONE_NIN = 'none.nin' ; // none not in

    protected const array __ALIAS__ =
    [
        self::ALL_EQ  => ArrayComparator::ALL . Char::SPACE . Comparator::EQUAL ,
        self::ALL_GE  => ArrayComparator::ALL . Char::SPACE . Comparator::GREATER_THAN_OR_EQUAL ,
        self::ALL_GT  => ArrayComparator::ALL . Char::SPACE . Comparator::GREATER_THAN ,
        self::ALL_IN  => ArrayComparator::ALL . Char::SPACE . Comparator::IN ,
        self::ALL_LE  => ArrayComparator::ALL . Char::SPACE . Comparator::LESS_THAN_OR_EQUAL ,
        self::ALL_LT  => ArrayComparator::ALL . Char::SPACE . Comparator::LESS_THAN ,
        self::ALL_NE  => ArrayComparator::ALL . Char::SPACE . Comparator::NOT_EQUAL ,
        self::ALL_NIN => ArrayComparator::ALL . Char::SPACE . Comparator::NOT_IN ,

        self::ANY_EQ  => ArrayComparator::ANY . Char::SPACE . Comparator::EQUAL ,
        self::ANY_GE  => ArrayComparator::ANY . Char::SPACE . Comparator::GREATER_THAN_OR_EQUAL ,
        self::ANY_GT  => ArrayComparator::ANY . Char::SPACE . Comparator::GREATER_THAN ,
        self::ANY_IN  => ArrayComparator::ANY . Char::SPACE . Comparator::IN ,
        self::ANY_LE  => ArrayComparator::ANY . Char::SPACE . Comparator::LESS_THAN_OR_EQUAL ,
        self::ANY_LT  => ArrayComparator::ANY . Char::SPACE . Comparator::LESS_THAN ,
        self::ANY_NE  => ArrayComparator::ANY . Char::SPACE . Comparator::NOT_EQUAL ,
        self::ANY_NIN => ArrayComparator::ANY . Char::SPACE . Comparator::NOT_IN ,

        self::NONE_EQ  => ArrayComparator::NONE . Char::SPACE . Comparator::EQUAL ,
        self::NONE_GE  => ArrayComparator::NONE . Char::SPACE . Comparator::GREATER_THAN_OR_EQUAL ,
        self::NONE_GT  => ArrayComparator::NONE . Char::SPACE . Comparator::GREATER_THAN ,
        self::NONE_IN  => ArrayComparator::NONE . Char::SPACE . Comparator::IN ,
        self::NONE_LE  => ArrayComparator::NONE . Char::SPACE . Comparator::LESS_THAN_OR_EQUAL ,
        self::NONE_LT  => ArrayComparator::NONE . Char::SPACE . Comparator::LESS_THAN ,
        self::NONE_NE  => ArrayComparator::NONE . Char::SPACE . Comparator::NOT_EQUAL ,
        self::NONE_NIN => ArrayComparator::NONE . Char::SPACE . Comparator::NOT_IN ,
    ];

    /**
     * Returns a valid filter operator alias or the default alias.
     * @param mixed $value
     * @param mixed|null $default
     * @return mixed
     */
    public static function getAlias( mixed $value , mixed $default = null ): mixed
    {
        return self::__ALIAS__[ $value ] ?? $default ;
    }
}