<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\models\enums\filters\FilterFunction;
use oihana\arango\models\enums\filters\FilterParam;

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
