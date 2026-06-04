<?php

namespace oihana\arango\models\enums\filters;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\traits\ConstantsTrait;

// ---- array

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\functions\arrays\count;
use function oihana\arango\db\functions\arrays\countDistinct;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\arrays\last;
use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\arrays\nth;
use function oihana\arango\db\functions\arrays\pluck;
use function oihana\arango\db\functions\arrays\pop;
use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\arrays\push;
use function oihana\arango\db\functions\arrays\removeValue;
use function oihana\arango\db\functions\arrays\removeValues;
use function oihana\arango\db\functions\arrays\reverse;
use function oihana\arango\db\functions\arrays\shift;
use function oihana\arango\db\functions\arrays\slice;
use function oihana\arango\db\functions\arrays\sorted;
use function oihana\arango\db\functions\arrays\sortedUnique;
use function oihana\arango\db\functions\arrays\unique;
use function oihana\arango\db\functions\arrays\unshift;
use function oihana\arango\db\helpers\aqlArray;

// ---- dates

use function oihana\arango\db\functions\dates\dateAdd;
use function oihana\arango\db\functions\dates\dateCompare;
use function oihana\arango\db\functions\dates\dateDay;
use function oihana\arango\db\functions\dates\dateDayOfWeek;
use function oihana\arango\db\functions\dates\dateDayOfYear;
use function oihana\arango\db\functions\dates\dateDaysInMonth;
use function oihana\arango\db\functions\dates\dateDiff;
use function oihana\arango\db\functions\dates\dateFormat;
use function oihana\arango\db\functions\dates\dateHour;
use function oihana\arango\db\functions\dates\dateISO8601;
use function oihana\arango\db\functions\dates\dateIsoWeek;
use function oihana\arango\db\functions\dates\dateIsoWeekYear;
use function oihana\arango\db\functions\dates\dateLeapYear;
use function oihana\arango\db\functions\dates\dateLocalToUTC;
use function oihana\arango\db\functions\dates\dateMillisecond;
use function oihana\arango\db\functions\dates\dateMinute;
use function oihana\arango\db\functions\dates\dateMonth;
use function oihana\arango\db\functions\dates\dateQuarter;
use function oihana\arango\db\functions\dates\dateSecond;
use function oihana\arango\db\functions\dates\dateSubtract;
use function oihana\arango\db\functions\dates\dateTimeStamp;
use function oihana\arango\db\functions\dates\dateTimezone;
use function oihana\arango\db\functions\dates\dateTrunc;
use function oihana\arango\db\functions\dates\dateUTCToLocal;
use function oihana\arango\db\functions\dates\dateYear;
use function oihana\arango\db\functions\dates\tomorrow;
use function oihana\arango\db\functions\dates\yesterday;

// ---- numerics

use function oihana\arango\db\functions\numerics\abs;
use function oihana\arango\db\functions\numerics\acos;
use function oihana\arango\db\functions\numerics\asin;
use function oihana\arango\db\functions\numerics\atan;
use function oihana\arango\db\functions\numerics\atan2;
use function oihana\arango\db\functions\numerics\average;
use function oihana\arango\db\functions\numerics\ceil;
use function oihana\arango\db\functions\numerics\cos;
use function oihana\arango\db\functions\numerics\cosSimilarity;
use function oihana\arango\db\functions\numerics\degrees;
use function oihana\arango\db\functions\numerics\exp;
use function oihana\arango\db\functions\numerics\exp2;
use function oihana\arango\db\functions\numerics\floor;
use function oihana\arango\db\functions\numerics\log;
use function oihana\arango\db\functions\numerics\log10;
use function oihana\arango\db\functions\numerics\log2;
use function oihana\arango\db\functions\numerics\max;
use function oihana\arango\db\functions\numerics\median;
use function oihana\arango\db\functions\numerics\min;
use function oihana\arango\db\functions\numerics\percentile;
use function oihana\arango\db\functions\numerics\pow;
use function oihana\arango\db\functions\numerics\product;
use function oihana\arango\db\functions\numerics\radians;
use function oihana\arango\db\functions\numerics\round;
use function oihana\arango\db\functions\numerics\sin;
use function oihana\arango\db\functions\numerics\sqrt;
use function oihana\arango\db\functions\numerics\sum;
use function oihana\arango\db\functions\numerics\tan;

// ---- strings

use function oihana\arango\db\functions\strings\charLength;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\functions\strings\concatSeparator;
use function oihana\arango\db\functions\strings\contains;
use function oihana\arango\db\functions\strings\encodeURIComponent;
use function oihana\arango\db\functions\strings\findFirst;
use function oihana\arango\db\functions\strings\findLast;
use function oihana\arango\db\functions\strings\fnv64;
use function oihana\arango\db\functions\strings\isIPV4;
use function oihana\arango\db\functions\strings\ipv4FromNumber;
use function oihana\arango\db\functions\strings\ipv4ToNumber;
use function oihana\arango\db\functions\strings\jsonParse;
use function oihana\arango\db\functions\strings\jsonStringify;
use function oihana\arango\db\functions\strings\left;
use function oihana\arango\db\functions\strings\levenshtein;
use function oihana\arango\db\functions\strings\like;
use function oihana\arango\db\functions\strings\lower;
use function oihana\arango\db\functions\strings\ltrim;
use function oihana\arango\db\functions\strings\md5;
use function oihana\arango\db\functions\strings\randomToken;
use function oihana\arango\db\functions\strings\right;
use function oihana\arango\db\functions\strings\rtrim;
use function oihana\arango\db\functions\strings\sha1;
use function oihana\arango\db\functions\strings\sha256;
use function oihana\arango\db\functions\strings\sha512;
use function oihana\arango\db\functions\strings\soundex;
use function oihana\arango\db\functions\strings\split;
use function oihana\arango\db\functions\strings\startsWith;
use function oihana\arango\db\functions\strings\subString;
use function oihana\arango\db\functions\strings\tokens;
use function oihana\arango\db\functions\strings\toBase64;
use function oihana\arango\db\functions\strings\toChar;
use function oihana\arango\db\functions\strings\toHex;
use function oihana\arango\db\functions\strings\trim;
use function oihana\arango\db\functions\strings\upper;
use function oihana\arango\db\functions\strings\uuid;

class FilterFunction
{
    use ConstantsTrait ;

    // ------- misc

    public const string COUNT  = 'count'  ;
    public const string LENGTH = 'length' ;

    // ------- array

    public const string APPEND         = 'append'        ;
    public const string COUNT_DISTINCT = 'countDistinct' ;
    public const string FIRST          = 'first'         ;
    public const string LAST           = 'last'          ;
    public const string NTH            = 'nth'           ;
    public const string PLUCK          = 'pluck'         ;
    public const string POP            = 'pop'           ;
    public const string POSITION       = 'position'      ;
    public const string PUSH           = 'push'          ;
    public const string REMOVE         = 'remove'        ; // removeValue
    public const string REMOVES        = 'removes'       ; // removeValues
    public const string REVERSE        = 'reverse'       ;
    public const string SHIFT          = 'shift'         ;
    public const string SLICE          = 'slice'         ;
    public const string SORTED         = 'sorted'        ;
    public const string SORTED_UNIQUE  = 'sortedUnique'  ;
    public const string UNIQUE         = 'unique'        ;
    public const string UNSHIFT        = 'unshift'       ;

    // ------- misc numerics (aggregates)

    public const string AVG        = "avg" ;
    public const string MAX        = "max" ;
    public const string MEDIAN     = "median" ;
    public const string MIN        = "min" ;
    public const string PERCENTILE = "percentile" ;
    public const string PRODUCT    = "product" ;
    public const string SUM        = "sum" ;

    // ------- numbers

    public const string ABS     = 'abs' ;
    public const string ACOS    = 'acos' ;
    public const string ASIN    = 'asin' ;
    public const string ATAN    = 'atan' ;
    public const string ATAN2   = 'atan2' ;
    public const string CEIL    = 'ceil' ;
    public const string COS     = 'cos' ;
    public const string DEGREES = 'deg' ;
    public const string EXP     = 'exp' ;
    public const string EXP2    = 'exp2' ;
    public const string FLOOR   = 'floor' ;
    public const string LOG     = 'log' ;
    public const string LOG2    = 'log2' ;
    public const string LOG10   = 'log10' ;
    public const string POW     = 'pow' ;
    public const string RADIANS = 'rad' ;
    public const string ROUND   = 'rnd' ;
    public const string SIN     = 'sin' ;
    public const string SQRT    = 'sqrt' ;
    public const string TAN     = 'tan' ;

    // ------- extra numerics

    public const string COS_SIMILARITY = 'cosSimilarity' ;

    // ------- string

    public const string CONCAT    = 'concat'    ;
    public const string LTRIM     = 'ltrim'     ;
    public const string LOWER     = 'lower'     ;
    public const string RTRIM     = 'rtrim'     ;
    public const string SUBSTRING = 'substring' ;
    public const string TRIM      = 'trim'      ;
    public const string UPPER     = 'upper'     ;

    // ------- extra strings

    public const string CHAR_LENGTH         = 'charLength'         ;
    public const string CONCAT_SEPARATOR    = 'concatSeparator'    ;
    public const string CONTAINS            = 'contains'           ;
    public const string ENCODE_URI          = 'encodeURIComponent' ;
    public const string FIND_FIRST          = 'findFirst'          ;
    public const string FIND_LAST           = 'findLast'           ;
    public const string FNV64               = 'fnv64'              ;
    public const string IS_IPV4             = 'isIPV4'             ;
    public const string IPV4_TO_NUMBER      = 'ipv4ToNumber'       ;
    public const string IPV4_FROM_NUMBER    = 'ipv4FromNumber'     ;
    public const string JSON_PARSE          = 'jsonParse'          ;
    public const string JSON_STRINGIFY      = 'jsonStringify'      ;
    public const string LEFT                = 'left'               ;
    public const string LEVENSHTEIN         = 'levenshtein'        ;
    public const string LIKE                = 'like'               ;
    public const string MD5                 = 'md5'                ;
    public const string RANDOM_TOKEN        = 'randomToken'        ;
    public const string RIGHT               = 'right'              ;
    public const string SHA1                = 'sha1'               ;
    public const string SHA256              = 'sha256'             ;
    public const string SHA512              = 'sha512'             ;
    public const string SOUNDEX             = 'soundex'            ;
    public const string SPLIT               = 'split'              ;
    public const string STARTS_WITH         = 'startsWith'         ;
    public const string TOKENS              = 'tokens'             ;
    public const string TO_BASE64           = 'toBase64'           ;
    public const string TO_CHAR             = 'toChar'             ;
    public const string TO_HEX              = 'toHex'              ;
    public const string UUID                = 'uuid'               ;

    // ------- dates

    public const string DATE_YEAR          = 'dateYear'         ;
    public const string DATE_MONTH         = 'dateMonth'        ;
    public const string DATE_DAY           = 'dateDay'          ;
    public const string DATE_HOUR          = 'dateHour'         ;
    public const string DATE_MINUTE        = 'dateMinute'       ;
    public const string DATE_SECOND        = 'dateSecond'       ;
    public const string DATE_MILLISECOND   = 'dateMillisecond'  ;
    public const string DATE_ISO_8601      = 'dateISO8601'      ;
    public const string DATE_LEAP_YEAR     = 'dateLeapYear'     ;
    public const string DATE_QUARTER       = 'dateQuarter'      ;
    public const string DATE_DAY_OF_WEEK   = 'dateDayOfWeek'    ;
    public const string DATE_DAY_OF_YEAR   = 'dateDayOfYear'    ;
    public const string DATE_DAYS_IN_MONTH = 'dateDaysInMonth'  ;
    public const string DATE_ISO_WEEK      = 'dateIsoWeek'      ;
    public const string DATE_ISO_WEEK_YEAR = 'dateIsoWeekYear'  ;
    public const string DATE_TIMEZONE      = 'dateTimezone'     ;
    public const string DATE_TIMESTAMP     = 'dateTimeStamp'    ;

    public const string DATE_ADD           = 'dateAdd'          ;
    public const string DATE_COMPARE       = 'dateCompare'      ;
    public const string DATE_SUBTRACT      = 'dateSubtract'     ;
    public const string DATE_TRUNC         = 'dateTrunc'        ;
    public const string DATE_DIFF          = 'dateDiff'         ;
    public const string DATE_FORMAT        = 'dateFormat'       ;
    public const string DATE_LOCAL_TO_UTC  = 'dateLocalToUTC'   ;
    public const string DATE_UTC_TO_LOCAL  = 'dateUTCToLocal'   ;

    public const string YESTERDAY          = 'yesterday'        ;
    public const string TOMORROW           = 'tomorrow'         ;

    /**
     * Functions that return boolean values.
     */
    private const array BOOLEAN_FUNCTIONS =
    [
        self::CONTAINS       ,
        self::DATE_LEAP_YEAR ,
        self::IS_IPV4        ,
        self::LIKE           ,
        self::STARTS_WITH    ,
    ];

    /**
     * Apply a function to a key with optional parameters.
     *
     * This method acts as a dispatcher that calls the appropriate AQL function
     * wrapper based on the function name. It supports string, number, and array functions.
     *
     * @param string $funcName Function name (e.g., "trim", "lower", "abs", "avg")
     * @param string $key      Current key expression (e.g., "doc.name")
     * @param array  $params   Additional parameters for the function
     *
     * @return string The key wrapped in the AQL function
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException When a `pluck` sub-field name is unsafe.
     *
     * @example
     * ```php
     * FilterFunction::apply('lower', 'doc.name', []);
     * // Returns: "LOWER(doc.name)"
     *
     * FilterFunction::apply('trim', 'doc.name', [1]);
     * // Returns: "TRIM(doc.name, 1)"
     *
     * FilterFunction::apply('substring', 'doc.code', [0, 3]);
     * // Returns: "SUBSTRING(doc.code, 0, 3)"
     * ```
     */
    public static function apply
    (
        string $funcName ,
        string $key      ,
        array  $params   = [] ,
        array  $init     = []
    )
    : string
    {
        if ( $init !== null && in_array( $funcName , self::BOOLEAN_FUNCTIONS , true ) )
        {
            $expectedValue = $init[ FilterParam::VAL ] ?? null ;
            if ( !is_bool( $expectedValue ) )
            {
                trigger_error
                (
                    "Function '$funcName' returns boolean but compared with non-boolean value: " .
                    json_encode( $expectedValue ) ,
                    E_USER_WARNING
                );
            }
        }

        return match ( $funcName )
        {
            // Misc functions

            self::AVG        => average   ( $key ) ,
            self::COUNT      => count     ( $key ) ,
            self::LENGTH     => length    ( $key ) ,
            self::MAX        => max       ( $key ) ,
            self::MEDIAN     => median    ( $key ) ,
            self::MIN        => min       ( $key ) ,
            self::PERCENTILE => percentile( $key , $params[0] ?? 50 , $params[1] ?? null ) ,
            self::PRODUCT    => product   ( $key ) ,
            self::SUM        => sum       ( $key ) ,

            // Array functions

            self::APPEND         => append        ( $key , aqlArray( $params[0] ?? [] ) , (bool) ( $params[1] ?? false ) ) ,
            self::COUNT_DISTINCT => countDistinct ( $key ) ,
            self::FIRST          => first         ( $key ) ,
            self::LAST           => last          ( $key ) ,
            self::NTH            => nth           ( $key , (int) ( $params[0] ?? 0 ) ) ,
            self::PLUCK          => pluck         ( $key , (string) ( $params[0] ?? '' ) ) , // doc.items[* RETURN CURRENT.<field>]
            self::POP            => pop           ( $key ) ,
            self::POSITION       => position      ( $key , $params[0] ?? null , (bool) ( $params[1] ?? false ) ) ,
            self::PUSH           => push          ( $key , $params[0] ?? null , (bool) ( $params[1] ?? false ) ) ,
            self::REMOVE         => removeValue   ( $key , $params[0] ?? null , $params[1] ?? null ) ,
            self::REMOVES        => removeValues  ( $key , aqlArray( $params[0] ?? [] ) ) ,
            self::REVERSE        => reverse       ( $key ) ,
            self::SHIFT          => shift         ( $key ) ,
            self::SLICE          => slice         ( $key ,  (int) ( $params[0] ?? 0 ) , $params[1] ?? null ) ,
            self::SORTED         => sorted        ( $key ) ,
            self::SORTED_UNIQUE  => sortedUnique  ( $key ) ,
            self::UNIQUE         => unique        ( $key ) ,
            self::UNSHIFT        => unshift       ( $key , $params[0] ?? null , (bool) ( $params[1] ?? false ) ) ,

            // Numeric functions (scalar)

            self::ABS            => abs           ( $key ) ,
            self::ACOS           => acos          ( $key ) ,
            self::ASIN           => asin          ( $key ) ,
            self::ATAN           => atan          ( $key ) ,
            self::ATAN2          => atan2         ( $key , $params[0] ?? 1 ) ,
            self::CEIL           => ceil          ( $key ) ,
            self::COS            => cos           ( $key ) ,
            self::COS_SIMILARITY => cosSimilarity ( $key , $params[0] ?? null ) ,
            self::DEGREES        => degrees       ( $key ) ,
            self::EXP            => exp           ( $key ) ,
            self::EXP2           => exp2          ( $key ) ,
            self::FLOOR          => floor         ( $key ) ,
            self::LOG            => log           ( $key ) ,
            self::LOG2           => log2          ( $key ) ,
            self::LOG10          => log10         ( $key ) ,
            self::POW            => pow           ( $key , $params[0] ?? 2 ) ,
            self::RADIANS        => radians       ( $key ) ,
            self::ROUND          => round         ( $key ) ,
            self::SIN            => sin           ( $key ) ,
            self::SQRT           => sqrt          ( $key ) ,
            self::TAN            => tan           ( $key ) ,

            // String functions

            self::CONCAT    => concat    ( [ $key , ...$params ] ) ,
            self::LTRIM     => ltrim     ( $key , $params[0] ?? null ) ,
            self::LOWER     => lower     ( $key ) ,
            self::RTRIM     => rtrim     ( $key , $params[0] ?? null ) ,
            self::SUBSTRING => subString ( $key , (int) ( $params[0] ?? 0 ) , isset( $params[1] ) ? (int) $params[1] : null ) ,
            self::TRIM      => trim      ( $key , $params[0] ?? null ) ,
            self::UPPER     => upper     ( $key ) ,

            // Additional string helpers (via constants)

            self::CHAR_LENGTH      => charLength        ( $key ) ,
            self::CONCAT_SEPARATOR => concatSeparator   ( $params[0] ?? '' , [ $key , ...array_slice( $params , 1 ) ] ) , // ?filter={"key":"name","alt":[["concatSeparator",","," ","Doe"]]}
            self::CONTAINS         => contains          ( $key , (string) ( $params[0] ?? '' ) , (bool) ( $params[1] ?? false ) ) ,
            self::ENCODE_URI       => encodeURIComponent( $key ) ,
            self::FIND_FIRST       => findFirst         ( $key , (string) ( $params[0] ?? '' ) , (int) ( $params[1] ?? null ) , (int) ( $params[2] ?? null ) ) ,
            self::FIND_LAST        => findLast          ( $key , (string) ( $params[0] ?? '' ) , (int) ( $params[1] ?? null ) , (int) ( $params[2] ?? null ) ) ,
            self::FNV64            => fnv64             ( $key ) ,
            self::IS_IPV4          => isIPV4            ( $key ) , // ?filter={"key":"ipAddress","val":true,"alt":"isIPV4"}
            self::IPV4_TO_NUMBER   => ipv4ToNumber      ( $key ) ,
            self::IPV4_FROM_NUMBER => ipv4FromNumber    ( $key ) ,
            self::JSON_PARSE       => jsonParse         ( $key ) ,
            self::JSON_STRINGIFY   => jsonStringify     ( $key ) ,
            self::LEFT             => left              ( $key , (int) ( $params[0] ?? 0 ) ) ,
            self::LEVENSHTEIN      => isset( $params[0]) ? levenshtein( $key , (string) $params[0] ) : $key ,
            self::LIKE             => like              ( $key , (string) ( $params[0] ?? '' ) , (bool) ( $params[1] ?? false ) ) ,
            self::MD5              => md5               ( $key ) ,
            self::RANDOM_TOKEN     => randomToken       ( (int) ( $params[0] ?? 16 ) ) ,
            self::RIGHT            => right             ( $key , (int) ( $params[0] ?? 0 ) ) ,
            self::SHA1             => sha1              ( $key ) ,
            self::SHA256           => sha256            ( $key ) ,
            self::SHA512           => sha512            ( $key ) ,
            self::SOUNDEX          => soundex           ( $key ) ,
            self::SPLIT            => split             ( $key , (string) ( $params[0] ?? '' ) , (int) ( $params[1] ?? 0 ) ) ,
            self::STARTS_WITH      => startsWith        ( $key , (string) ( $params[0] ?? '' ) ) ,
            self::TOKENS           => tokens            ( $key , (string) ( $params[0] ?? '' ) ) ,
            self::TO_BASE64        => toBase64          ( $key ) ,
            self::TO_CHAR          => toChar            ( $key ) ,
            self::TO_HEX           => toHex             ( $key ) ,
            self::UUID             => uuid              ( ) ,

            // Date functions: we always assume the current key represents a date/time value

            self::DATE_YEAR          => dateYear        ( $key ) ,
            self::DATE_MONTH         => dateMonth       ( $key ) ,
            self::DATE_DAY           => dateDay         ( $key ) ,
            self::DATE_HOUR          => dateHour        ( $key ) ,
            self::DATE_MINUTE        => dateMinute      ( $key ) ,
            self::DATE_SECOND        => dateSecond      ( $key ) ,
            self::DATE_MILLISECOND   => dateMillisecond ( $key ) ,
            self::DATE_ISO_8601      => dateISO8601     ( $key ) ,
            self::DATE_LEAP_YEAR     => dateLeapYear    ( $key ) ,
            self::DATE_QUARTER       => dateQuarter     ( $key ) ,
            self::DATE_DAY_OF_WEEK   => dateDayOfWeek   ( $key ) ,
            self::DATE_DAY_OF_YEAR   => dateDayOfYear   ( $key ) ,
            self::DATE_DAYS_IN_MONTH => dateDaysInMonth ( $key ) ,
            self::DATE_ISO_WEEK      => dateIsoWeek     ( $key ) ,
            self::DATE_ISO_WEEK_YEAR => dateIsoWeekYear ( $key ) ,
            self::DATE_TIMEZONE      => dateTimezone    () ,
            self::DATE_TIMESTAMP     => dateTimeStamp   ( $key ) ,

            // Date arithmetic helpers (current key used as base date when applicable)

            self::DATE_ADD           => dateAdd        ( $key , $params[0] ?? 0 , $params[1] ?? 'day' ) ,
            self::DATE_COMPARE       => dateCompare    ( $key , $params[0] ?? null , $params[1] ?? null ) ,
            self::DATE_SUBTRACT      => dateSubtract   ( $key , $params[0] ?? 0 , $params[1] ?? 'day' ) ,
            self::DATE_TRUNC         => dateTrunc      ( $key , $params[0] ?? 'day' ) ,
            self::DATE_DIFF          => dateDiff       ( $key , $params[0] ?? null , $params[1] ?? 'day' , $params[2] ?? null , $params[3] ?? null , $params[4] ?? null ) ,
            self::DATE_FORMAT        => dateFormat     ( $key , $params[0] ?? null , (bool) ( $params[1] ?? true ) ) ,
            self::DATE_LOCAL_TO_UTC  => dateLocalToUTC ( $key , $params[0] ?? "UTC" ) ,
            self::DATE_UTC_TO_LOCAL  => dateUTCToLocal ( $key , $params[0] ?? null ) ,

            // Relative date helpers: if a base date is provided in params[0], use it, otherwise use the current key

            self::YESTERDAY => yesterday ( $params[0] ?? $key ) ,
            self::TOMORROW  => tomorrow  ( $params[0] ?? $key ) ,

            // Unknown function = no-op

            default => $key ,
        };
    }
}