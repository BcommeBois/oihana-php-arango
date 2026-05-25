<?php

namespace oihana\arango\db\functions\dates;

use oihana\arango\db\enums\DateUnit;
use oihana\enums\Char;
use function oihana\core\strings\betweenDoubleQuotes;

/**
 * Returns a valid date/time unit string expression for use in AQL functions.
 *
 * This helper ensures the provided `$unit` is a valid time unit among:
 * `year(s)`, `month(s)`, `day(s)`, `hour(s)`, `minute(s)`, `second(s)`, or `millisecond(s)`.
 * If the value is not recognized, it defaults to `"day"`.
 *
 * Example AQL usage:
 * ```aql
 * DATE_ADD(date, 3, "day")
 * DATE_SUBTRACT(date, 2, "month")
 * ```
 *
 * @param string|null $unit One of the following units (case-insensitive):
 *   - `y`, `year`, `years`
 *   - `m`, `month`, `months` *(default)*
 *   - `d`, `day`, `days`
 *   - `h`, `hour`, `hours`
 *   - `i`, `minute`, `minutes`
 *   - `s`, `second`, `seconds`
 *   - `f`, `millisecond`, `milliseconds`
 *
 * @return string The quoted valid time unit (e.g. `"day"`).
 *
 * @example
 * ```php
 * echo timeUnit('day');      // "day"
 * echo timeUnit('month');    // "month"
 * echo timeUnit('unknown');  // "day"
 * ```
 *
 * @see https://docs.arangodb.com/stable/aql/functions/date/#date_add
 *
 * @package oihana\arango\db\functions\dates
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function timeUnit( ?string $unit = DateUnit::DAY ) :string
{
    $unit = trim( $unit , Char::DOUBLE_QUOTE ) ;
    return DateUnit::includes( $unit ) ? betweenDoubleQuotes( $unit ) : $unit ;
}
