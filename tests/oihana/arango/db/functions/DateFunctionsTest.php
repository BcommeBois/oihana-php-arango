<?php

namespace tests\oihana\arango\db\functions;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;

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
use function oihana\arango\db\functions\dates\dateNow;
use function oihana\arango\db\functions\dates\dateQuarter;
use function oihana\arango\db\functions\dates\dateSecond;
use function oihana\arango\db\functions\dates\dateSubtract;
use function oihana\arango\db\functions\dates\dateTimeStamp;
use function oihana\arango\db\functions\dates\dateTimezone;
use function oihana\arango\db\functions\dates\dateTimezones;
use function oihana\arango\db\functions\dates\dateTrunc;
use function oihana\arango\db\functions\dates\dateUTCToLocal;
use function oihana\arango\db\functions\dates\dateYear;
use function oihana\arango\db\functions\dates\timeUnit;
use function oihana\arango\db\functions\dates\tomorrow;
use function oihana\arango\db\functions\dates\yesterday;

use function oihana\core\strings\betweenDoubleQuotes;

class DateFunctionsTest extends TestCase
{
    public function testDateAdd(): void
    {
        $this->assertEquals('DATE_ADD(DATE_NOW(),1,"day")', dateAdd(null, 1, 'day'));
        $this->assertEquals('DATE_ADD("2025-01-01",2,"month")', dateAdd('"2025-01-01"', 2, 'month'));
    }

    public function testDateCompare(): void
    {
        $this->assertEquals('DATE_COMPARE("date1","date2","d","m")', dateCompare('"date1"', '"date2"', 'd', 'm'));
    }

    public function testDateCompareDefaultsBothDatesToNow(): void
    {
        $this->assertEquals('DATE_COMPARE(DATE_NOW(),DATE_NOW(),"y")', dateCompare());
    }

    public function testDateDay(): void
    {
        $this->assertEquals('DATE_DAY(DATE_NOW())', dateDay());
    }

    public function testDateFormat(): void
    {
        // Note: The trait now calls the global betweenDoubleQuotes, so we don't mock it.
        // The mock for func() will receive the already quoted string.
        $this->assertEquals('DATE_FORMAT(DATE_NOW(),"%Y-%m-%d")', dateFormat(null, '%Y-%m-%d'));
    }

    public function testDateHour(): void
    {
        $this->assertEquals('DATE_HOUR(DATE_NOW())', dateHour());
    }

    public function testDateIsoWeek(): void
    {
        $this->assertEquals('DATE_ISOWEEK(DATE_NOW())', dateIsoWeek());
    }

    public function testDateLeapYear(): void
    {
        $this->assertEquals('DATE_LEAPYEAR(DATE_NOW())', dateLeapYear());
    }

    public function testDateMinute(): void
    {
        $this->assertEquals('DATE_MINUTE(DATE_NOW())', dateMinute());
    }

    public function testDateNow(): void
    {
        $this->assertEquals('DATE_NOW()', dateNow());
    }

    public function testDateIso8601(): void
    {
        $this->assertEquals('DATE_ISO8601(DATE_NOW())', dateISO8601());
    }

    public function testDateMillisecond(): void
    {
        $this->assertEquals('DATE_MILLISECOND(DATE_NOW())', dateMillisecond());
    }

    public function testDateMonth(): void
    {
        $this->assertEquals('DATE_MONTH(DATE_NOW())', dateMonth());
    }

    public function testDateQuarter(): void
    {
        $this->assertEquals('DATE_QUARTER(DATE_NOW())', dateQuarter());
    }

    public function testDateSecond(): void
    {
        $this->assertEquals('DATE_SECOND(DATE_NOW())', dateSecond());
    }

    public function testDateSubtract(): void
    {
        $this->assertEquals('DATE_SUBTRACT(DATE_NOW(),1,"day")', dateSubtract(null, 1, 'day'));
    }

    public function testDateTimeStamp(): void
    {
        $this->assertEquals('DATE_TIMESTAMP(DATE_NOW())', dateTimeStamp() );
    }

    public function testTomorrow(): void
    {
        $this->assertEquals('DATE_ADD(DATE_NOW(),1,"day")', tomorrow());
    }

    public function testYesterday(): void
    {
        $this->assertEquals('DATE_SUBTRACT(DATE_NOW(),1,"day")', yesterday() );
    }

    public function testDateTrunc(): void
    {
        $this->assertEquals('DATE_TRUNC(DATE_NOW(),"year")', dateTrunc(null, 'year'));
    }

    public function testDateYear(): void
    {
        $this->assertEquals('DATE_YEAR(DATE_NOW())', dateYear());
    }

    public function testTimeUnit(): void
    {
        $this->assertEquals('"day"'    , timeUnit('day'));
        $this->assertEquals('"year"'   , timeUnit('year'));
        $this->assertEquals('"day"'    , timeUnit());
        $this->assertEquals('doc.unit' , timeUnit('doc.unit'));
        $this->assertEquals('@bindVar' , timeUnit('@bindVar'));
    }

    public function testDateDayOfWeek(): void
    {
        $this->assertEquals('DATE_DAYOFWEEK(DATE_NOW())', dateDayOfWeek());
    }

    public function testDateDaysInMonth(): void
    {
        $this->assertEquals('DATE_DAYS_IN_MONTH(DATE_NOW())', dateDaysInMonth());
    }

    public function testDateDayOfYear(): void
    {
        $this->assertEquals('DATE_DAYOFYEAR(DATE_NOW())', dateDayOfYear());
    }

    public function testDateDiff(): void
    {
        $this->assertEquals
        (
            'DATE_DIFF(DATE_NOW(),DATE_NOW(),"day",false)',
            dateDiff(null, null, 'day' , false )
        );

        $this->assertEquals
        (
            'DATE_DIFF(DATE_NOW(),DATE_NOW(),@unit,false)',
            dateDiff(null, null, '@unit' , false )
        );

        $this->assertEquals
        (
            'DATE_DIFF(DATE_NOW(),DATE_NOW(),doc.unit,false)',
            dateDiff(null, null, 'doc.unit' , false )
        );
    }

    public function testDateDiffWithTimezones(): void
    {
        $this->assertEquals
        (
            'DATE_DIFF(DATE_NOW(),DATE_NOW(),"day",false,"Europe/Paris")',
            dateDiff(null, null, 'day', false, betweenDoubleQuotes('Europe/Paris'))
        );

        $this->assertEquals
        (
            'DATE_DIFF(DATE_NOW(),DATE_NOW(),"day",false,"Europe/Paris","UTC")',
            dateDiff(null, null, 'day', false, betweenDoubleQuotes('Europe/Paris'), betweenDoubleQuotes('UTC'))
        );
    }

    public function testDateIsoWeekYear(): void
    {
        $this->assertEquals('DATE_ISOWEEKYEAR(DATE_NOW())', dateIsoWeekYear());
    }

    public function testDateLocalToUTC(): void
    {
        $this->assertEquals
        (
            'DATE_LOCALTOUTC(DATE_NOW(),"Europe/Berlin")' ,
            dateLocalToUTC(null, betweenDoubleQuotes( "Europe/Berlin" ) )
        );
    }

    public function testDateLocalToUTCThrowsOnInvalidTimezone(): void
    {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid timezone Not/AZone.' );
        dateLocalToUTC( null, 'Not/AZone' );
    }

    public function testDateUtcToLocal(): void
    {
        $this->assertEquals
        (
            'DATE_UTCTOLOCAL(DATE_NOW(),"Europe/Berlin")',
            dateUTCToLocal(null, betweenDoubleQuotes( 'Europe/Berlin' ) )
        );
    }

    public function testDateUtcToLocalThrowsOnInvalidTimezone(): void
    {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid timezone Not/AZone.' );
        dateUTCToLocal( null, 'Not/AZone', true );
    }

    public function testDateTimezone(): void
    {
        $this->assertEquals('DATE_TIMEZONE()', dateTimezone() );
    }

    public function testDateTimezones(): void
    {
        $this->assertEquals('DATE_TIMEZONES()', dateTimezones() );
    }
}
