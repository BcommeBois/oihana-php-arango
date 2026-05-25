# Date functions `db/functions/dates/`

The [`src/oihana/arango/db/functions/dates/`](../../../src/oihana/arango/db/functions/dates/) sub-folder groups **30 functions** that match the native AQL *date functions*. They all manipulate dates in ISO 8601 format (`'2026-05-17T14:30:00.000Z'`) or Unix *timestamps* in milliseconds.

> Format convention: most functions accept `null|string|int` for `$date` — either an ISO 8601 string, a Unix *timestamp* in milliseconds, or `null` (equivalent to `DATE_NOW()`). The `DateUnit` enum provides the unit constants (`YEAR`, `MONTH`, `WEEK`, `DAY`, `HOUR`, `MINUTE`, `SECOND`, `MILLISECOND`).

## Summary

| Category | Functions |
|---|---|
| Component extraction | `dateYear`, `dateMonth`, `dateDay`, `dateHour`, `dateMinute`, `dateSecond`, `dateMillisecond` |
| Derived information | `dateDayOfWeek`, `dateDayOfYear`, `dateDaysInMonth`, `dateIsoWeek`, `dateIsoWeekYear`, `dateLeapYear`, `dateQuarter` |
| Arithmetic | `dateAdd`, `dateSubstract`, `dateDiff`, `dateTrunc`, `dateCompare` |
| Conversion and format | `dateFormat`, `dateISO8601`, `dateTimeStamp`, `dateTimezone`, `dateTimezones`, `dateLocalToUTC`, `dateUTCToLocal` |
| Relative dates | `dateNow`, `tomorrow`, `yesterday` |
| Unit | `timeUnit` |

## Component extraction

All these functions share the signature `(null|string|int $date = null) : string` and produce `DATE_<COMPONENT>(<date>)`. If `$date` is `null`, the function implicitly uses `DATE_NOW()`.

| Function | AQL output | Range |
|---|---|---|
| `dateYear` | `DATE_YEAR(<date>)` | `1970+` |
| `dateMonth` | `DATE_MONTH(<date>)` | `1-12` |
| `dateDay` | `DATE_DAY(<date>)` | `1-31` |
| `dateHour` | `DATE_HOUR(<date>)` | `0-23` |
| `dateMinute` | `DATE_MINUTE(<date>)` | `0-59` |
| `dateSecond` | `DATE_SECOND(<date>)` | `0-59` |
| `dateMillisecond` | `DATE_MILLISECOND(<date>)` | `0-999` |

```php
use function oihana\arango\db\functions\dates\dateYear ;

dateYear( 'doc.created' ) ;     // "DATE_YEAR(doc.created)"
dateYear() ;                    // "DATE_YEAR(DATE_NOW())"
```

## Derived information

Same signature, but return a computed information rather than a raw component.

| Function | AQL output | Return |
|---|---|---|
| `dateDayOfWeek` | `DATE_DAYOFWEEK(<date>)` | `0-6` (0 = Sunday) |
| `dateDayOfYear` | `DATE_DAYOFYEAR(<date>)` | `1-366` |
| `dateDaysInMonth` | `DATE_DAYS_IN_MONTH(<date>)` | `28-31` |
| `dateIsoWeek` | `DATE_ISOWEEK(<date>)` | `1-53` (ISO 8601 week) |
| `dateIsoWeekYear` | `DATE_ISOWEEKYEAR(<date>)` | ISO week year |
| `dateLeapYear` | `DATE_LEAPYEAR(<date>)` | `true` / `false` |
| `dateQuarter` | `DATE_QUARTER(<date>)` | `1-4` |

## Arithmetic

| Function | Signature | AQL output |
|---|---|---|
| `dateAdd` | `(null\|string\|int $date, string\|int $amount, string $unit = DateUnit::DAY)` | `DATE_ADD(<date>, <amount>, <unit>)` |
| `dateSubstract` | `(null\|string\|int $date, string\|int $amount, string $unit = DateUnit::DAY)` | `DATE_SUBTRACT(<date>, <amount>, <unit>)` |
| `dateDiff` | `(date1, date2, unit, decimals)` | `DATE_DIFF(<a>, <b>, <unit>[, <decimals>])` |
| `dateTrunc` | `(null\|string\|int $date, ?string $unit = DateUnit::MONTH)` | `DATE_TRUNC(<date>, <unit>)` |
| `dateCompare` | partial comparison of two dates | `DATE_COMPARE(<a>, <b>, <granularity>)` |

> The name `dateSubstract` (with an extra `s`) is a historical typo on the vendor side — the function still produces the correct `DATE_SUBTRACT()` AQL. The signature can later be renamed with a backwards-compatibility alias.

```php
use oihana\arango\db\enums\DateUnit ;
use function oihana\arango\db\functions\dates\dateAdd ;
use function oihana\arango\db\functions\dates\dateDiff ;

dateAdd ( 'doc.created' , 7 , DateUnit::DAY  ) ;     // "DATE_ADD(doc.created, 7, 'day')"
dateDiff( 'doc.startDate' , 'doc.endDate' , DateUnit::HOUR ) ;
// "DATE_DIFF(doc.startDate, doc.endDate, 'hour')"
```

## Conversion and format

| Function | Signature | AQL output |
|---|---|---|
| `dateFormat` | `(date, format)` | `DATE_FORMAT(<date>, <format>)` |
| `dateISO8601` | `(null\|string\|int $date = null)` | `DATE_ISO8601(<date>)` |
| `dateTimeStamp` | `(null\|int\|string $date = null)` | `DATE_TIMESTAMP(<date>)` |
| `dateTimezone` | `()` | `DATE_TIMEZONE()` (server's active timezone) |
| `dateTimezones` | `()` | `DATE_TIMEZONES()` (full list) |
| `dateLocalToUTC` | `(date, tz)` | `DATE_LOCAL_TO_UTC(<date>, <tz>)` |
| `dateUTCToLocal` | `(date, tz)` | `DATE_UTC_TO_LOCAL(<date>, <tz>)` |

`dateFormat` format: `%Y` (year), `%m` (month), `%d` (day), `%H`, `%M`, `%S`, etc. — see the [official docs](https://docs.arangodb.com/stable/aql/functions/date/#date_format).

## Relative dates

| Function | Signature | AQL output |
|---|---|---|
| `dateNow` | `()` | `DATE_NOW()` (Unix timestamp in ms) |
| `tomorrow` | `(null\|string\|int $date = null)` | `DATE_ADD(<date>, 1, 'day')` |
| `yesterday` | `(null\|string\|int $date = null)` | `DATE_SUBTRACT(<date>, 1, 'day')` |

`dateNow` is the most used function to auto-stamp a document on insertion or update. `tomorrow` and `yesterday` are practical shortcuts for date filters.

## Unit

| Function | Signature | AQL output |
|---|---|---|
| `timeUnit` | `(?string $unit = DateUnit::DAY)` | `'day'`, `'hour'`, ... (valid string) |

Utility helper that returns a valid unit string. Prevents typos (`'days'` instead of `'day'` is rejected by ArangoDB).

## Typical composition

Filter documents created in the last 30 days:

```php
use function oihana\arango\db\operators\greaterThan      ;
use function oihana\arango\db\operations\aqlFilter       ;
use function oihana\arango\db\functions\dates\dateNow    ;
use function oihana\arango\db\functions\dates\dateSubstract ;

aqlFilter
(
    greaterThan
    (
        'doc.created' ,
        dateSubstract( dateNow() , 30 , DateUnit::DAY )
    )
) ;
// "FILTER doc.created > DATE_SUBTRACT(DATE_NOW(), 30, 'day')"
```

## See also

- [Building an AQL query step by step](aql-building-queries.md).
- [Operators `db/operators/`](aql-operators.md) — date comparators.
- [Glossary — bind variable](../glossary.md#bind-variable) — for dynamic filters.
- [Official AQL documentation — Date functions](https://docs.arangodb.com/stable/aql/functions/date/).
