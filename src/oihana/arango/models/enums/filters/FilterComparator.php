<?php

namespace oihana\arango\models\enums\filters;

use oihana\arango\db\enums\Comparator;
use oihana\reflect\traits\ConstantsTrait;

class FilterComparator
{
    use ConstantsTrait ;

    public const string BETWEEN   = 'between'   ; // range: key >= min && key <= max (no single AQL alias)
    public const string CONTAINS  = 'contains'  ; // contains substring — CONTAINS(key, value) (function form, not in __ALIAS__)
    public const string DISTANCE  = 'distance'  ; // geo radius — DISTANCE(key.latitude, key.longitude, @lat, @lng) <= @max (FilterType::GEO, not in __ALIAS__)
    public const string EQ        = 'eq'        ; // equals (default)
    public const string EW        = 'ew'        ; // ends with — RIGHT(key, CHAR_LENGTH(value)) == value (special form, not in __ALIAS__)
    public const string GE        = 'ge'        ; // greater than or equals
    public const string GT        = 'gt'        ; // greater than
    public const string IN        = 'in'        ; // in
    public const string LE        = 'le'        ; // less than or equals
    public const string LT        = 'lt'        ; // less than
    public const string LIKE      = 'like'      ; // like
    public const string MATCH     = 'match'     ; // not like
    public const string NCONTAINS = 'ncontains' ; // not contains — !(CONTAINS(key, value)) (function form, not in __ALIAS__)
    public const string NE        = 'ne'        ; // not equals
    public const string NEW       = 'new'       ; // not ends with — !(RIGHT(key, CHAR_LENGTH(value)) == value) (special form, not in __ALIAS__)
    public const string NIN       = 'nin'       ; // not in
    public const string NLIKE     = 'nlike'     ; // not like
    public const string NMATCH    = 'nmatch'    ; // not like
    public const string NREGEX    = 'nregex'    ; // not regex — !(REGEX_TEST(key, value)) (function form, not in __ALIAS__)
    public const string NSW       = 'nsw'       ; // not starts with — !(STARTS_WITH(key, value)) (function form, not in __ALIAS__)
    public const string REGEX     = 'regex'     ; // regular expression test — REGEX_TEST(key, value) (function form, not in __ALIAS__)
    public const string SW        = 'sw'        ; // starts with — STARTS_WITH(key, value) (function form, not in __ALIAS__)

    protected const array __ALIAS__ =
    [
        self::EQ     => Comparator::EQUAL ,
        self::GE     => Comparator::GREATER_THAN_OR_EQUAL ,
        self::GT     => Comparator::GREATER_THAN ,
        self::IN     => Comparator::IN ,
        self::LE     => Comparator::LESS_THAN_OR_EQUAL ,
        self::LT     => Comparator::LESS_THAN ,
        self::LIKE   => Comparator::LIKE ,
        self::MATCH  => Comparator::MATCH ,
        self::NMATCH => Comparator::NOT_MATCH ,
        self::NE     => Comparator::NOT_EQUAL ,
        self::NIN    => Comparator::NOT_IN ,
        self::NLIKE  => Comparator::NOT_LIKE ,
    ];

    /**
     * Returns a valid filter operator alias or the default alias.
     * @param mixed $value
     * @param mixed|null $default
     * @return mixed
     */
    public static function getAlias( mixed $value , mixed $default = Comparator::EQUAL ): mixed
    {
        return self::__ALIAS__[ $value ] ?? $default ;
    }
}