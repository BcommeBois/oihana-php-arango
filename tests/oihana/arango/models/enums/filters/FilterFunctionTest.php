<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\models\enums\filters\FilterFunction;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\ValidationException;

/**
 * Tests for FilterFunction class.
 */
class FilterFunctionTest extends TestCase
{
    // ========================================
    // CONSTANTS - MISC
    // ========================================

    public function testMiscConstantsAreDefined(): void
    {
        $this->assertSame( 'count'  , FilterFunction::COUNT  ) ;
        $this->assertSame( 'length' , FilterFunction::LENGTH ) ;
    }

    // ========================================
    // CONSTANTS - ARRAY
    // ========================================

    public function testArrayConstantsAreDefined(): void
    {
        $this->assertSame( 'append'        , FilterFunction::APPEND         ) ;
        $this->assertSame( 'countDistinct' , FilterFunction::COUNT_DISTINCT ) ;
        $this->assertSame( 'first'         , FilterFunction::FIRST          ) ;
        $this->assertSame( 'last'          , FilterFunction::LAST           ) ;
        $this->assertSame( 'nth'           , FilterFunction::NTH            ) ;
        $this->assertSame( 'pluck'         , FilterFunction::PLUCK          ) ;
        $this->assertSame( 'pop'           , FilterFunction::POP            ) ;
        $this->assertSame( 'position'      , FilterFunction::POSITION       ) ;
        $this->assertSame( 'push'          , FilterFunction::PUSH           ) ;
        $this->assertSame( 'remove'        , FilterFunction::REMOVE         ) ;
        $this->assertSame( 'removes'       , FilterFunction::REMOVES        ) ;
        $this->assertSame( 'reverse'       , FilterFunction::REVERSE        ) ;
        $this->assertSame( 'shift'         , FilterFunction::SHIFT          ) ;
        $this->assertSame( 'slice'         , FilterFunction::SLICE          ) ;
        $this->assertSame( 'sorted'        , FilterFunction::SORTED         ) ;
        $this->assertSame( 'sortedUnique'  , FilterFunction::SORTED_UNIQUE  ) ;
        $this->assertSame( 'unique'        , FilterFunction::UNIQUE         ) ;
        $this->assertSame( 'unshift'       , FilterFunction::UNSHIFT        ) ;
    }

    // ========================================
    // CONSTANTS - AGGREGATES
    // ========================================

    public function testAggregateConstantsAreDefined(): void
    {
        $this->assertSame( 'avg'        , FilterFunction::AVG        ) ;
        $this->assertSame( 'max'        , FilterFunction::MAX        ) ;
        $this->assertSame( 'median'     , FilterFunction::MEDIAN     ) ;
        $this->assertSame( 'min'        , FilterFunction::MIN        ) ;
        $this->assertSame( 'percentile' , FilterFunction::PERCENTILE ) ;
        $this->assertSame( 'product'    , FilterFunction::PRODUCT    ) ;
        $this->assertSame( 'sum'        , FilterFunction::SUM        ) ;
    }

    // ========================================
    // CONSTANTS - NUMERICS
    // ========================================

    public function testNumericConstantsAreDefined(): void
    {
        $this->assertSame( 'abs'   , FilterFunction::ABS     ) ;
        $this->assertSame( 'acos'  , FilterFunction::ACOS    ) ;
        $this->assertSame( 'asin'  , FilterFunction::ASIN    ) ;
        $this->assertSame( 'atan'  , FilterFunction::ATAN    ) ;
        $this->assertSame( 'atan2' , FilterFunction::ATAN2   ) ;
        $this->assertSame( 'ceil'  , FilterFunction::CEIL    ) ;
        $this->assertSame( 'cos'   , FilterFunction::COS     ) ;
        $this->assertSame( 'deg'   , FilterFunction::DEGREES ) ;
        $this->assertSame( 'exp'   , FilterFunction::EXP     ) ;
        $this->assertSame( 'exp2'  , FilterFunction::EXP2    ) ;
        $this->assertSame( 'floor' , FilterFunction::FLOOR   ) ;
        $this->assertSame( 'log'   , FilterFunction::LOG     ) ;
        $this->assertSame( 'log2'  , FilterFunction::LOG2    ) ;
        $this->assertSame( 'log10' , FilterFunction::LOG10   ) ;
        $this->assertSame( 'pow'   , FilterFunction::POW     ) ;
        $this->assertSame( 'rad'   , FilterFunction::RADIANS ) ;
        $this->assertSame( 'rnd'   , FilterFunction::ROUND   ) ;
        $this->assertSame( 'sin'   , FilterFunction::SIN     ) ;
        $this->assertSame( 'sqrt'  , FilterFunction::SQRT    ) ;
        $this->assertSame( 'tan'   , FilterFunction::TAN     ) ;
    }

    // ========================================
    // CONSTANTS - STRING
    // ========================================

    public function testStringConstantsAreDefined(): void
    {
        $this->assertSame( 'concat'    , FilterFunction::CONCAT    ) ;
        $this->assertSame( 'ltrim'     , FilterFunction::LTRIM     ) ;
        $this->assertSame( 'lower'     , FilterFunction::LOWER     ) ;
        $this->assertSame( 'rtrim'     , FilterFunction::RTRIM     ) ;
        $this->assertSame( 'substring' , FilterFunction::SUBSTRING ) ;
        $this->assertSame( 'trim'      , FilterFunction::TRIM      ) ;
        $this->assertSame( 'upper'     , FilterFunction::UPPER     ) ;
    }

    // ========================================
    // CONSTANTS - DATE
    // ========================================

    public function testDateConstantsAreDefined(): void
    {
        $this->assertSame( 'dateYear'        , FilterFunction::DATE_YEAR          ) ;
        $this->assertSame( 'dateMonth'       , FilterFunction::DATE_MONTH         ) ;
        $this->assertSame( 'dateDay'         , FilterFunction::DATE_DAY           ) ;
        $this->assertSame( 'dateHour'        , FilterFunction::DATE_HOUR          ) ;
        $this->assertSame( 'dateMinute'      , FilterFunction::DATE_MINUTE        ) ;
        $this->assertSame( 'dateSecond'      , FilterFunction::DATE_SECOND        ) ;
        $this->assertSame( 'dateMillisecond' , FilterFunction::DATE_MILLISECOND   ) ;
        $this->assertSame( 'dateISO8601'     , FilterFunction::DATE_ISO_8601      ) ;
        $this->assertSame( 'dateLeapYear'    , FilterFunction::DATE_LEAP_YEAR     ) ;
        $this->assertSame( 'dateQuarter'     , FilterFunction::DATE_QUARTER       ) ;
        $this->assertSame( 'dateDayOfWeek'   , FilterFunction::DATE_DAY_OF_WEEK   ) ;
        $this->assertSame( 'dateDayOfYear'   , FilterFunction::DATE_DAY_OF_YEAR   ) ;
        $this->assertSame( 'dateDaysInMonth' , FilterFunction::DATE_DAYS_IN_MONTH ) ;
        $this->assertSame( 'dateIsoWeek'     , FilterFunction::DATE_ISO_WEEK      ) ;
        $this->assertSame( 'dateIsoWeekYear' , FilterFunction::DATE_ISO_WEEK_YEAR ) ;
        $this->assertSame( 'dateTimezone'    , FilterFunction::DATE_TIMEZONE      ) ;
        $this->assertSame( 'dateTimeStamp'   , FilterFunction::DATE_TIMESTAMP     ) ;
        $this->assertSame( 'dateAdd'         , FilterFunction::DATE_ADD           ) ;
        $this->assertSame( 'dateCompare'     , FilterFunction::DATE_COMPARE       ) ;
        $this->assertSame( 'dateSubtract'    , FilterFunction::DATE_SUBTRACT      ) ;
        $this->assertSame( 'dateTrunc'       , FilterFunction::DATE_TRUNC         ) ;
        $this->assertSame( 'dateDiff'        , FilterFunction::DATE_DIFF          ) ;
        $this->assertSame( 'dateFormat'      , FilterFunction::DATE_FORMAT        ) ;
        $this->assertSame( 'dateLocalToUTC'  , FilterFunction::DATE_LOCAL_TO_UTC  ) ;
        $this->assertSame( 'dateUTCToLocal'  , FilterFunction::DATE_UTC_TO_LOCAL  ) ;
        $this->assertSame( 'yesterday'       , FilterFunction::YESTERDAY          ) ;
        $this->assertSame( 'tomorrow'        , FilterFunction::TOMORROW           ) ;
    }

    // ========================================
    // APPLY - MISC FUNCTIONS
    // ========================================

    public function testApplyCount(): void
    {
        $this->assertSame( 'COUNT(doc.tags)' , FilterFunction::apply( FilterFunction::COUNT , 'doc.tags' ) ) ;
    }

    public function testApplyLength(): void
    {
        $this->assertSame( 'LENGTH(doc.name)' , FilterFunction::apply( FilterFunction::LENGTH , 'doc.name' ) ) ;
    }

    // ========================================
    // APPLY - ARRAY FUNCTIONS
    // ========================================

    public function testApplyPluckProjectsSubField(): void
    {
        $this->assertSame
        (
            'doc.items[* RETURN CURRENT.price]' ,
            FilterFunction::apply( FilterFunction::PLUCK , 'doc.items' , [ 'price' ] )
        ) ;
    }

    public function testApplyPluckAcceptsNestedObjectPath(): void
    {
        // a dotted path projects a nested object field, e.g. CURRENT.offer.price
        $this->assertSame
        (
            'doc.items[* RETURN CURRENT.offer.price]' ,
            FilterFunction::apply( FilterFunction::PLUCK , 'doc.items' , [ 'offer.price' ] )
        ) ;
    }

    public function testApplyPluckRejectsUnsafeSubField(): void
    {
        $this->expectException( ValidationException::class ) ;
        FilterFunction::apply( FilterFunction::PLUCK , 'doc.items' , [ 'x) || LENGTH(secrets' ] ) ;
    }

    public function testApplyPluckRejectsNestedArrayExpansionPath(): void
    {
        // nested array expansion (offers[*].price) is not supported yet — the
        // `[*]` is rejected by the attribute-name guard (see backlog).
        $this->expectException( ValidationException::class ) ;
        FilterFunction::apply( FilterFunction::PLUCK , 'doc.items' , [ 'offers[*].price' ] ) ;
    }

    // ========================================
    // CONDITIONAL (coalesce / notNull)
    // ========================================

    public function testConditionalConstantsAreDefined(): void
    {
        $this->assertSame( 'coalesce' , FilterFunction::COALESCE ) ;
        $this->assertSame( 'notNull'  , FilterFunction::NOT_NULL ) ;
    }

    public function testApplyCoalesceInlinesADefaultLiteral(): void
    {
        $this->assertSame( 'NOT_NULL(doc.discount,0)'      , FilterFunction::apply( FilterFunction::COALESCE , 'doc.discount' , [ 0 ] ) ) ;
        $this->assertSame( 'NOT_NULL(doc.status,"N/A")'    , FilterFunction::apply( FilterFunction::COALESCE , 'doc.status'   , [ 'N/A' ] ) ) ;
        $this->assertSame( 'NOT_NULL(doc.flag,false)'      , FilterFunction::apply( FilterFunction::NOT_NULL , 'doc.flag'     , [ false ] ) ) ;
        $this->assertSame( 'NOT_NULL(doc.x)'               , FilterFunction::apply( FilterFunction::COALESCE , 'doc.x'        , [] ) ) ;
    }

    public function testApplyCoalesceEscapesUntrustedDefaultAsAStringLiteral(): void
    {
        // a hostile default is fully quoted/escaped — it becomes an inert string
        // literal, never raw AQL (json_encode, not the passthrough aqlValue()).
        $result = FilterFunction::apply( FilterFunction::COALESCE , 'doc.x' , [ 'a") || LENGTH(secrets) || ("' ] ) ;
        $this->assertSame( 'NOT_NULL(doc.x,"a\\") || LENGTH(secrets) || (\\"")' , $result ) ;
    }

    public function testApplyFirst(): void
    {
        $this->assertSame( 'FIRST(doc.items)' , FilterFunction::apply( FilterFunction::FIRST , 'doc.items' ) ) ;
    }

    public function testApplyLast(): void
    {
        $this->assertSame( 'LAST(doc.items)' , FilterFunction::apply( FilterFunction::LAST , 'doc.items' ) ) ;
    }

    public function testApplyNth(): void
    {
        $this->assertSame( 'NTH(doc.items,2)' , FilterFunction::apply( FilterFunction::NTH , 'doc.items' , [2] ) ) ;
    }

    public function testApplyReverse(): void
    {
        $this->assertSame( 'REVERSE(doc.items)' , FilterFunction::apply( FilterFunction::REVERSE , 'doc.items' ) ) ;
    }

    public function testApplyUnique(): void
    {
        $this->assertSame( 'UNIQUE(doc.items)' , FilterFunction::apply( FilterFunction::UNIQUE , 'doc.items' ) ) ;
    }

    public function testApplySorted(): void
    {
        $this->assertSame( 'SORTED(doc.items)' , FilterFunction::apply( FilterFunction::SORTED , 'doc.items' ) ) ;
    }

    public function testApplySortedUnique(): void
    {
        $this->assertSame( 'SORTED_UNIQUE(doc.items)' , FilterFunction::apply( FilterFunction::SORTED_UNIQUE , 'doc.items' ) ) ;
    }

    public function testApplySlice(): void
    {
        $this->assertSame( 'SLICE(doc.items,1,3)' , FilterFunction::apply( FilterFunction::SLICE , 'doc.items' , [1,3] ) ) ;
    }

    // ========================================
    // APPLY - AGGREGATE FUNCTIONS
    // ========================================

    public function testApplyAvg(): void
    {
        $this->assertSame( 'AVERAGE(doc.values)' , FilterFunction::apply( FilterFunction::AVG , 'doc.values' ) ) ;
    }

    public function testApplyMax(): void
    {
        $this->assertSame( 'MAX(doc.values)' , FilterFunction::apply( FilterFunction::MAX , 'doc.values' ) ) ;
    }

    public function testApplyMin(): void
    {
        $this->assertSame( 'MIN(doc.values)' , FilterFunction::apply( FilterFunction::MIN , 'doc.values' ) ) ;
    }

    public function testApplyMedian(): void
    {
        $this->assertSame( 'MEDIAN(doc.values)' , FilterFunction::apply( FilterFunction::MEDIAN , 'doc.values' ) ) ;
    }

    public function testApplySum(): void
    {
        $this->assertSame( 'SUM(doc.values)' , FilterFunction::apply( FilterFunction::SUM , 'doc.values' ) ) ;
    }

    public function testApplyProduct(): void
    {
        $this->assertSame( 'PRODUCT(doc.values)' , FilterFunction::apply( FilterFunction::PRODUCT , 'doc.values' ) ) ;
    }

    // ========================================
    // APPLY - NUMERIC FUNCTIONS
    // ========================================

    #[DataProvider('provideSimpleNumericFunctions')]
    public function testApplySimpleNumericFunctions( string $funcName , string $expectedFunc ): void
    {
        $result = FilterFunction::apply( $funcName , 'doc.value' ) ;
        $this->assertSame( "{$expectedFunc}(doc.value)" , $result ) ;
    }

    public static function provideSimpleNumericFunctions(): array
    {
        return
        [
            'abs'     => [ FilterFunction::ABS     , 'ABS'     ] ,
            'acos'    => [ FilterFunction::ACOS    , 'ACOS'    ] ,
            'asin'    => [ FilterFunction::ASIN    , 'ASIN'    ] ,
            'atan'    => [ FilterFunction::ATAN    , 'ATAN'    ] ,
            'ceil'    => [ FilterFunction::CEIL    , 'CEIL'    ] ,
            'cos'     => [ FilterFunction::COS     , 'COS'     ] ,
            'degrees' => [ FilterFunction::DEGREES , 'DEGREES' ] ,
            'exp'     => [ FilterFunction::EXP     , 'EXP'     ] ,
            'exp2'    => [ FilterFunction::EXP2    , 'EXP2'    ] ,
            'floor'   => [ FilterFunction::FLOOR   , 'FLOOR'   ] ,
            'log'     => [ FilterFunction::LOG     , 'LOG'     ] ,
            'log2'    => [ FilterFunction::LOG2    , 'LOG2'    ] ,
            'log10'   => [ FilterFunction::LOG10   , 'LOG10'   ] ,
            'radians' => [ FilterFunction::RADIANS , 'RADIANS' ] ,
            'sin'     => [ FilterFunction::SIN     , 'SIN'     ] ,
            'sqrt'    => [ FilterFunction::SQRT    , 'SQRT'    ] ,
            'tan'     => [ FilterFunction::TAN     , 'TAN'     ] ,
        ];
    }

    public function testApplyPow(): void
    {
        $this->assertSame( 'POW(doc.value,2)' , FilterFunction::apply( FilterFunction::POW , 'doc.value' ) ) ;
        $this->assertSame( 'POW(doc.value,3)' , FilterFunction::apply( FilterFunction::POW , 'doc.value' , [3] ) ) ;
    }

    public function testApplyAtan2(): void
    {
        $this->assertSame( 'ATAN2(doc.value,1)' , FilterFunction::apply( FilterFunction::ATAN2 , 'doc.value' ) ) ;
        $this->assertSame( 'ATAN2(doc.value,5)' , FilterFunction::apply( FilterFunction::ATAN2 , 'doc.value' , [5] ) ) ;
    }

    public function testApplyRound(): void
    {
        $this->assertSame( 'ROUND(doc.value)' , FilterFunction::apply( FilterFunction::ROUND , 'doc.value' ) ) ;
    }

    // ========================================
    // APPLY - STRING FUNCTIONS
    // ========================================

    public function testApplyLower(): void
    {
        $this->assertSame( 'LOWER(doc.name)' , FilterFunction::apply( FilterFunction::LOWER , 'doc.name' ) ) ;
    }

    public function testApplyUpper(): void
    {
        $this->assertSame( 'UPPER(doc.name)' , FilterFunction::apply( FilterFunction::UPPER , 'doc.name' ) ) ;
    }

    public function testApplyTrim(): void
    {
        $this->assertSame( 'TRIM(doc.name)' , FilterFunction::apply( FilterFunction::TRIM , 'doc.name' ) ) ;
    }

    public function testApplyLtrim(): void
    {
        $this->assertSame( 'LTRIM(doc.name)' , FilterFunction::apply( FilterFunction::LTRIM , 'doc.name' ) ) ;
    }

    public function testApplyRtrim(): void
    {
        $this->assertSame( 'RTRIM(doc.name)' , FilterFunction::apply( FilterFunction::RTRIM , 'doc.name' ) ) ;
    }

    public function testApplySubstring(): void
    {
        $this->assertSame( 'SUBSTRING(doc.name,0,3)' , FilterFunction::apply( FilterFunction::SUBSTRING , 'doc.name' , [0,3] ) ) ;
        $this->assertSame( 'SUBSTRING(doc.name,5)' , FilterFunction::apply( FilterFunction::SUBSTRING , 'doc.name' , [5] ) ) ;
    }

    public function testApplyLeft(): void
    {
        $this->assertSame( 'LEFT(doc.name,5)' , FilterFunction::apply( FilterFunction::LEFT , 'doc.name' , [5] ) ) ;
    }

    public function testApplyRight(): void
    {
        $this->assertSame( 'RIGHT(doc.name,3)' , FilterFunction::apply( FilterFunction::RIGHT , 'doc.name' , [3] ) ) ;
    }

    // ========================================
    // APPLY - DATE FUNCTIONS
    // ========================================

    public function testApplyDateYear(): void
    {
        $this->assertSame( 'DATE_YEAR(doc.created)' , FilterFunction::apply( FilterFunction::DATE_YEAR , 'doc.created' ) ) ;
    }

    public function testApplyDateMonth(): void
    {
        $this->assertSame( 'DATE_MONTH(doc.created)' , FilterFunction::apply( FilterFunction::DATE_MONTH , 'doc.created' ) ) ;
    }

    public function testApplyDateDay(): void
    {
        $this->assertSame( 'DATE_DAY(doc.created)' , FilterFunction::apply( FilterFunction::DATE_DAY , 'doc.created' ) ) ;
    }

    public function testApplyDateDayOfWeek(): void
    {
        $this->assertSame( 'DATE_DAYOFWEEK(doc.created)' , FilterFunction::apply( FilterFunction::DATE_DAY_OF_WEEK , 'doc.created' ) ) ;
    }

    public function testApplyDateDayOfYear(): void
    {
        $this->assertSame( 'DATE_DAYOFYEAR(doc.created)' , FilterFunction::apply( FilterFunction::DATE_DAY_OF_YEAR , 'doc.created' ) ) ;
    }

    // ========================================
    // APPLY - UNKNOWN FUNCTION
    // ========================================

    public function testApplyUnknownFunctionReturnsKeyUnchanged(): void
    {
        $this->assertSame( 'doc.name' , FilterFunction::apply( 'unknown' , 'doc.name' ) ) ;
        $this->assertSame( 'doc.name' , FilterFunction::apply( ''        , 'doc.name' ) ) ;
    }

    // ========================================
    // APPLY - ARRAY FUNCTIONS (extra arms)
    // ========================================

    public function testApplyAppend(): void
    {
        $this->assertSame( 'APPEND(doc.items,["a","b"],true)' , FilterFunction::apply( FilterFunction::APPEND , 'doc.items' , [ [ 'a' , 'b' ] , true ] ) ) ;
    }

    public function testApplyCountDistinct(): void
    {
        $this->assertSame( 'COUNT_DISTINCT(doc.items)' , FilterFunction::apply( FilterFunction::COUNT_DISTINCT , 'doc.items' ) ) ;
    }

    public function testApplyPop(): void
    {
        $this->assertSame( 'POP(doc.items)' , FilterFunction::apply( FilterFunction::POP , 'doc.items' ) ) ;
    }

    public function testApplyPosition(): void
    {
        $this->assertSame( 'POSITION(doc.items,x,true)' , FilterFunction::apply( FilterFunction::POSITION , 'doc.items' , [ 'x' , true ] ) ) ;
    }

    public function testApplyPush(): void
    {
        $this->assertSame( 'PUSH(doc.items,x,true)' , FilterFunction::apply( FilterFunction::PUSH , 'doc.items' , [ 'x' , true ] ) ) ;
    }

    public function testApplyShift(): void
    {
        $this->assertSame( 'SHIFT(doc.items)' , FilterFunction::apply( FilterFunction::SHIFT , 'doc.items' ) ) ;
    }

    public function testApplyUnshift(): void
    {
        $this->assertSame( 'UNSHIFT(doc.items,x,true)' , FilterFunction::apply( FilterFunction::UNSHIFT , 'doc.items' , [ 'x' , true ] ) ) ;
    }

    /**
     * `remove` (singular) emits the singular AQL `REMOVE_VALUE`, which removes a
     * single value (with an optional occurrence limit as the second parameter).
     * Use `removes` (plural) to remove a list of values via `REMOVE_VALUES`.
     */
    public function testApplyRemove(): void
    {
        $this->assertSame( 'REMOVE_VALUE(doc.items,x)' , FilterFunction::apply( FilterFunction::REMOVE , 'doc.items' , [ 'x' ] ) ) ;
        $this->assertSame( 'REMOVE_VALUE(doc.items,x,1)' , FilterFunction::apply( FilterFunction::REMOVE , 'doc.items' , [ 'x' , 1 ] ) ) ;
        // a non-numeric limit coming from the outside no longer raises a TypeError, it is ignored
        $this->assertSame( 'REMOVE_VALUE(doc.items,x)' , FilterFunction::apply( FilterFunction::REMOVE , 'doc.items' , [ 'x' , 'all' ] ) ) ;
    }

    public function testApplyRemoves(): void
    {
        $this->assertSame( 'REMOVE_VALUES(doc.items,["a","b"])' , FilterFunction::apply( FilterFunction::REMOVES , 'doc.items' , [ [ 'a' , 'b' ] ] ) ) ;
    }

    // ========================================
    // APPLY - AGGREGATE (percentile)
    // ========================================

    public function testApplyPercentile(): void
    {
        $this->assertSame( 'PERCENTILE(doc.v,90)' , FilterFunction::apply( FilterFunction::PERCENTILE , 'doc.v' , [ 90 ] ) ) ;
    }

    // ========================================
    // APPLY - NUMERIC (cosSimilarity)
    // ========================================

    public function testApplyCosSimilarity(): void
    {
        $this->assertSame( 'COSINE_SIMILARITY(doc.vec,doc.vec2)' , FilterFunction::apply( FilterFunction::COS_SIMILARITY , 'doc.vec' , [ 'doc.vec2' ] ) ) ;
    }

    // ========================================
    // APPLY - STRING FUNCTIONS (extra arms)
    // ========================================

    public function testApplyConcat(): void
    {
        $this->assertSame( 'CONCAT(doc.a,doc.b,doc.c)' , FilterFunction::apply( FilterFunction::CONCAT , 'doc.a' , [ 'doc.b' , 'doc.c' ] ) ) ;
    }

    public function testApplyCharLength(): void
    {
        $this->assertSame( 'CHAR_LENGTH(doc.name)' , FilterFunction::apply( FilterFunction::CHAR_LENGTH , 'doc.name' ) ) ;
    }

    public function testApplyConcatSeparator(): void
    {
        $this->assertSame( "CONCAT_SEPARATOR(',',doc.name,' ','Doe')" , FilterFunction::apply( FilterFunction::CONCAT_SEPARATOR , 'doc.name' , [ ',' , ' ' , 'Doe' ] ) ) ;
    }

    public function testApplyContains(): void
    {
        // pass a boolean VAL in $init so the boolean-comparison guard stays quiet
        $this->assertSame
        (
            "CONTAINS(doc.name,'abc',true)" ,
            FilterFunction::apply( FilterFunction::CONTAINS , 'doc.name' , [ 'abc' , true ] , [ FilterParam::VAL => true ] )
        ) ;
    }

    public function testApplyEncodeUriComponent(): void
    {
        $this->assertSame( 'ENCODE_URI_COMPONENT(doc.url)' , FilterFunction::apply( FilterFunction::ENCODE_URI , 'doc.url' ) ) ;
    }

    public function testApplyFindFirst(): void
    {
        $this->assertSame( 'FIND_FIRST(doc.name,x,0,5)' , FilterFunction::apply( FilterFunction::FIND_FIRST , 'doc.name' , [ 'x' , 0 , 5 ] ) ) ;
    }

    // ========================================
    // APPLY - BOOLEAN-FUNCTION COMPARISON GUARD
    // ========================================

    /**
     * A boolean-returning function compared against a non-boolean value emits an
     * E_USER_WARNING (the expression itself is still produced).
     */
    public function testApplyBooleanFunctionWithNonBooleanValueTriggersWarning(): void
    {
        $warning = null ;
        set_error_handler( function( int $no , string $str ) use ( &$warning ) : bool
        {
            $warning = $str ;
            return true ;
        } , E_USER_WARNING ) ;

        try
        {
            $result = FilterFunction::apply
            (
                FilterFunction::STARTS_WITH ,
                'doc.name' ,
                [ 'abc' ] ,
                [ FilterParam::VAL => 'not-a-bool' ]
            ) ;
        }
        finally
        {
            restore_error_handler() ;
        }

        $this->assertNotNull( $warning ) ;
        $this->assertStringContainsString( "Function 'startsWith' returns boolean" , $warning ) ;
        $this->assertStringContainsString( 'STARTS_WITH(doc.name,abc)' , $result ) ;
    }

    // ========================================
    // APPLY - THE ARMS NO TEST HAD EVER TAKEN
    // ========================================

    /**
     * Every entry below had never been exercised: the arm was written, no test had
     * ever asked for it. Covering them is what surfaced the three ipv4 constants
     * sharing one value, and the `toChar` arm that raised a `TypeError` on every
     * call — both fixed in their own commits.
     *
     * ⚠ The expected AQL name is a **literal**, never the `FilterFunction::`
     * constant `apply()` itself reads. Writing the constant on both sides compares
     * a value to itself: the row passes whatever the constant holds, which is
     * exactly how the ipv4 defect survived a green, fully covered suite.
     */
    #[DataProvider('provideSimpleUnaryFunctions')]
    public function testApplySimpleUnaryFunctions( string $funcName , string $expectedFunc ): void
    {
        $this->assertSame( "{$expectedFunc}(doc.value)" , FilterFunction::apply( $funcName , 'doc.value' ) ) ;
    }

    public static function provideSimpleUnaryFunctions(): array
    {
        return
        [
            // Hashes and string encodings
            'fnv64'           => [ FilterFunction::FNV64            , 'FNV64'            ] ,
            'md5'             => [ FilterFunction::MD5              , 'MD5'              ] ,
            'sha1'            => [ FilterFunction::SHA1             , 'SHA1'             ] ,
            'sha256'          => [ FilterFunction::SHA256           , 'SHA256'           ] ,
            'sha512'          => [ FilterFunction::SHA512           , 'SHA512'           ] ,
            'soundex'         => [ FilterFunction::SOUNDEX          , 'SOUNDEX'          ] ,
            'toBase64'        => [ FilterFunction::TO_BASE64        , 'TO_BASE64'        ] ,
            'toChar'          => [ FilterFunction::TO_CHAR          , 'TO_CHAR'          ] ,
            'toHex'           => [ FilterFunction::TO_HEX           , 'TO_HEX'           ] ,

            // JSON
            'jsonParse'       => [ FilterFunction::JSON_PARSE       , 'JSON_PARSE'       ] ,
            'jsonStringify'   => [ FilterFunction::JSON_STRINGIFY   , 'JSON_STRINGIFY'   ] ,

            // IPv4 — the two conversions are inverses of each other, so a shared
            // value between their constants stayed invisible until this row existed.
            'ipv4ToNumber'    => [ FilterFunction::IPV4_TO_NUMBER   , 'IPV4_TO_NUMBER'   ] ,
            'ipv4FromNumber'  => [ FilterFunction::IPV4_FROM_NUMBER , 'IPV4_FROM_NUMBER' ] ,

            // Date parts. Note the AQL names that drop the underscore.
            'dateMinute'      => [ FilterFunction::DATE_MINUTE        , 'DATE_MINUTE'        ] ,
            'dateSecond'      => [ FilterFunction::DATE_SECOND        , 'DATE_SECOND'        ] ,
            'dateMillisecond' => [ FilterFunction::DATE_MILLISECOND   , 'DATE_MILLISECOND'   ] ,
            'dateISO8601'     => [ FilterFunction::DATE_ISO_8601      , 'DATE_ISO8601'       ] ,
            'dateDaysInMonth' => [ FilterFunction::DATE_DAYS_IN_MONTH , 'DATE_DAYS_IN_MONTH' ] ,
            'dateIsoWeekYear' => [ FilterFunction::DATE_ISO_WEEK_YEAR , 'DATE_ISOWEEKYEAR'   ] ,
            'dateTimeStamp'   => [ FilterFunction::DATE_TIMESTAMP     , 'DATE_TIMESTAMP'     ] ,
        ];
    }

    /**
     * Two unary arms return a boolean, so they pass through the comparison guard.
     * A boolean `FilterParam::VAL` keeps it quiet — the guard itself is covered by
     * {@see testApplyBooleanFunctionWithNonBooleanValueTriggersWarning()}.
     */
    public function testApplyBooleanUnaryFunctions(): void
    {
        $init = [ FilterParam::VAL => true ] ;

        $this->assertSame( 'IS_IPV4(doc.value)'      , FilterFunction::apply( FilterFunction::IS_IPV4        , 'doc.value' , [] , $init ) ) ;
        $this->assertSame( 'DATE_LEAPYEAR(doc.value)', FilterFunction::apply( FilterFunction::DATE_LEAP_YEAR , 'doc.value' , [] , $init ) ) ;
    }

    /**
     * Three arms ignore the key entirely — they fabricate a value rather than
     * transform a field. `randomToken` reads its length from the params.
     */
    public function testApplyFunctionsThatIgnoreTheKey(): void
    {
        $this->assertSame( 'UUID()'           , FilterFunction::apply( FilterFunction::UUID          , 'doc.value'   ) ) ;
        $this->assertSame( 'DATE_TIMEZONE()'  , FilterFunction::apply( FilterFunction::DATE_TIMEZONE , 'doc.created' ) ) ;
        $this->assertSame( 'RANDOM_TOKEN(32)' , FilterFunction::apply( FilterFunction::RANDOM_TOKEN  , 'doc.value' , [ 32 ] ) ) ;
        $this->assertSame( 'RANDOM_TOKEN(16)' , FilterFunction::apply( FilterFunction::RANDOM_TOKEN  , 'doc.value' ) ) ; // default length
    }

    /**
     * A parameter is emitted **as written**: quoted by the caller when it is text,
     * left raw when it names another field. Quoting inside the helper would make
     * the second form impossible, so the choice belongs to whoever calls.
     */
    public function testApplyKeepsAParameterAsWritten(): void
    {
        // Text — the caller quotes.
        $this->assertSame( 'LIKE(doc.name,"%doe%",true)' , FilterFunction::apply( FilterFunction::LIKE , 'doc.name' , [ '"%doe%"' , true ] , [ FilterParam::VAL => true ] ) ) ;

        // Another field — left raw, and that is the point.
        $this->assertSame( 'LIKE(doc.name,doc.pattern)'  , FilterFunction::apply( FilterFunction::LIKE , 'doc.name' , [ 'doc.pattern' ]    , [ FilterParam::VAL => true ] ) ) ;
    }

    public function testApplyStringFunctionsWithParams(): void
    {
        $this->assertSame( 'FIND_LAST(doc.name,"o",0,5)'          , FilterFunction::apply( FilterFunction::FIND_LAST , 'doc.name' , [ '"o"' , 0 , 5 ] ) ) ;
        $this->assertSame( 'SPLIT(doc.name,",",3)'                , FilterFunction::apply( FilterFunction::SPLIT     , 'doc.name' , [ '","' , 3 ]     ) ) ;
        $this->assertSame( 'TOKENS(doc.name,"text_en")'           , FilterFunction::apply( FilterFunction::TOKENS    , 'doc.name' , [ '"text_en"' ]   ) ) ;
        $this->assertSame( 'LEVENSHTEIN_DISTANCE(doc.name,"Doe")' , FilterFunction::apply( FilterFunction::LEVENSHTEIN , 'doc.name' , [ '"Doe"' ]     ) ) ;
    }

    /**
     * `levenshtein` is the one arm that needs a second operand to mean anything:
     * without it the key is returned untouched rather than wrapped.
     */
    public function testApplyLevenshteinWithoutOperandReturnsTheKey(): void
    {
        $this->assertSame( 'doc.name' , FilterFunction::apply( FilterFunction::LEVENSHTEIN , 'doc.name' ) ) ;
    }

    public function testApplyDateArithmetic(): void
    {
        $this->assertSame( 'DATE_ADD(doc.created,3,"month")'      , FilterFunction::apply( FilterFunction::DATE_ADD      , 'doc.created' , [ 3 , 'month' ] ) ) ;
        $this->assertSame( 'DATE_SUBTRACT(doc.created,7,"day")'   , FilterFunction::apply( FilterFunction::DATE_SUBTRACT , 'doc.created' , [ 7 , 'day'   ] ) ) ;
        $this->assertSame( 'DATE_TRUNC(doc.created,"month")'      , FilterFunction::apply( FilterFunction::DATE_TRUNC    , 'doc.created' , [ 'month'     ] ) ) ;

        // Both take a second date, so another field is the natural operand.
        $this->assertSame( 'DATE_COMPARE(doc.created,doc.other,"days")'    , FilterFunction::apply( FilterFunction::DATE_COMPARE , 'doc.created' , [ 'doc.other' , 'days' ] ) ) ;
        $this->assertSame( 'DATE_DIFF(doc.created,doc.other,"day",true)'   , FilterFunction::apply( FilterFunction::DATE_DIFF    , 'doc.created' , [ 'doc.other' , 'day' , true ] ) ) ;
    }

    /**
     * Omitted parameters fall back to the arm's own defaults, so a bare
     * `alt:"dateAdd"` still produces valid AQL rather than a broken call.
     */
    public function testApplyDateArithmeticDefaults(): void
    {
        $this->assertSame( 'DATE_ADD(doc.created,0,"day")'      , FilterFunction::apply( FilterFunction::DATE_ADD      , 'doc.created' ) ) ;
        $this->assertSame( 'DATE_SUBTRACT(doc.created,0,"day")' , FilterFunction::apply( FilterFunction::DATE_SUBTRACT , 'doc.created' ) ) ;
        $this->assertSame( 'DATE_TRUNC(doc.created,"day")'      , FilterFunction::apply( FilterFunction::DATE_TRUNC    , 'doc.created' ) ) ;
        $this->assertSame( 'DATE_COMPARE(doc.created,DATE_NOW())'          , FilterFunction::apply( FilterFunction::DATE_COMPARE , 'doc.created' ) ) ;
        $this->assertSame( 'DATE_DIFF(doc.created,DATE_NOW(),"day",false)' , FilterFunction::apply( FilterFunction::DATE_DIFF    , 'doc.created' ) ) ;
    }

    /**
     * `yesterday` / `tomorrow` are shorthands over the same arithmetic. With no
     * param they shift the key; with one, they shift that date instead — which is
     * why they read the base from `$params[0] ?? $key`.
     */
    public function testApplyRelativeDateShorthands(): void
    {
        $this->assertSame( 'DATE_SUBTRACT(doc.created,1,"day")' , FilterFunction::apply( FilterFunction::YESTERDAY , 'doc.created' ) ) ;
        $this->assertSame( 'DATE_ADD(doc.created,1,"day")'      , FilterFunction::apply( FilterFunction::TOMORROW  , 'doc.created' ) ) ;

        $this->assertSame( 'DATE_SUBTRACT(doc.other,1,"day")'   , FilterFunction::apply( FilterFunction::YESTERDAY , 'doc.created' , [ 'doc.other' ] ) ) ;
        $this->assertSame( 'DATE_ADD(doc.other,1,"day")'        , FilterFunction::apply( FilterFunction::TOMORROW  , 'doc.created' , [ 'doc.other' ] ) ) ;
    }

    /**
     * `dateFormat` does not follow the caller-quotes convention: its third
     * parameter is a `useQuotes` switch, so the format is handed raw and the arm
     * wraps it — or does not, when the format names a field.
     */
    public function testApplyDateFormat(): void
    {
        $this->assertSame( 'DATE_FORMAT(doc.created,"%yyyy-%mm")' , FilterFunction::apply( FilterFunction::DATE_FORMAT , 'doc.created' , [ '%yyyy-%mm' ] ) ) ;
        $this->assertSame( 'DATE_FORMAT(doc.created,doc.fmt)'     , FilterFunction::apply( FilterFunction::DATE_FORMAT , 'doc.created' , [ 'doc.fmt' , false ] ) ) ;
    }

    public function testApplyTimezoneConversions(): void
    {
        $this->assertSame( 'DATE_LOCALTOUTC(doc.created,"Europe/Paris")' , FilterFunction::apply( FilterFunction::DATE_LOCAL_TO_UTC , 'doc.created' , [ '"Europe/Paris"' ] ) ) ;
        $this->assertSame( 'DATE_UTCTOLOCAL(doc.created,"Europe/Paris")' , FilterFunction::apply( FilterFunction::DATE_UTC_TO_LOCAL , 'doc.created' , [ '"Europe/Paris"' ] ) ) ;

        // Its own default is emitted unquoted — frozen as it stands today.
        $this->assertSame( 'DATE_LOCALTOUTC(doc.created,UTC)' , FilterFunction::apply( FilterFunction::DATE_LOCAL_TO_UTC , 'doc.created' ) ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        $this->assertTrue( FilterFunction::includes( FilterFunction::COUNT     ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::LENGTH    ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::LOWER     ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::UPPER     ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::TRIM      ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::ABS       ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::AVG       ) ) ;
        $this->assertTrue( FilterFunction::includes( FilterFunction::DATE_YEAR ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterFunction::includes( 'invalid' ) ) ;
        $this->assertFalse( FilterFunction::includes( 'COUNT'   ) ) ; // uppercase
        $this->assertFalse( FilterFunction::includes( 'LOWER'   ) ) ; // uppercase
        $this->assertFalse( FilterFunction::includes( ''        ) ) ;
        $this->assertFalse( FilterFunction::includes( null      ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterFunction::enums() ;

        $this->assertIsArray( $enums ) ;
        $this->assertContains( FilterFunction::COUNT     , $enums ) ;
        $this->assertContains( FilterFunction::LENGTH    , $enums ) ;
        $this->assertContains( FilterFunction::LOWER     , $enums ) ;
        $this->assertContains( FilterFunction::UPPER     , $enums ) ;
        $this->assertContains( FilterFunction::ABS       , $enums ) ;
        $this->assertContains( FilterFunction::AVG       , $enums ) ;
        $this->assertContains( FilterFunction::DATE_YEAR , $enums ) ;
    }
}
