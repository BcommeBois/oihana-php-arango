<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\filters\FilterDate;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\functions\dates\dateISO8601;
use function oihana\arango\db\functions\dates\dateLocalToUTC;
use function oihana\arango\db\functions\dates\dateNow;
use function oihana\arango\db\functions\dates\tomorrow;
use function oihana\arango\db\functions\dates\yesterday;
use function oihana\core\date\isValidTimezone;
use function oihana\core\strings\predicate;

/**
 * This trait defines the date filter helpers.
 *
 * ### Configure
 *
 * Defines the 'filters' property in the model (Documents) definition.
 *
 * ```
 * Models::PLACES => fn( ContainerInterface $container ) => new Documents
 * (
 *     $container ,
 *     Collections::PLACES ,
 *     [
 *         ...
 *         AQL::FILTERS =>
 *         [
 *              Prop::CREATED => FilterType::DATE ,
 *         ]
 *         ...
 * ```
 *
 * ### Usage
 *
 * Use a date time strings in ISO 8601 format value to filter the query result.
 *
 * ```
 * ?filter={ "key":"created" , "val":"2024-12-21T10:00:00" }
 * // FILTER doc.created == "2024-12-21T10:00:00"
 * ```
 *
 * Use the **"now"** attribute to use the current date value.
 * ```
 * ?filter={ "key":"created" , "op":"le" , "val":"now" }
 * // FILTER doc.created <= DATE_ISO8601(DATE_NOW())
 * ```
 *
 * Use the "cts" attribute to use the current timestamp value.
 * ```
 * ?filter={ "key":"created" , "val":"cts" }
 * // FILTER doc.created == DATE_NOW() (current timestamp)
 * ```
 *
 * Use the **"tz"** attribute to specify the local timezone of the value and convert it in the Zulu time (UTC).
 * ```
 * ?filter={ "key":"created" , "op":"gt" , "val":"2024-12-21T10:00:00" , "tz":"Europe/Paris" }
 * // FILTER doc.created <= DATE_LOCALTOUTC("2024-12-21T10:00:00","Europe/Paris")
 * ```
 **Functions**
 *
 * Alters the document attribute with the AQL DATE_DAY() method and use the day part of date as number.
 * ```
 * ?filter={ "key":"date" , "val":12 , "alt":"d" }
 * // FILTER DATE_DAY(doc.date) == 12
 * ```
 *
 * Use the "dw" alter attribute to transform the document property with the DATE_DAYOFWEEK() method and use the weekday number of date :
 * 0 – Sunday / 1 – Monday / 2 – Tuesday / 3 – Wednesday / 4 – Thursday / 5 – Friday / 6 – Saturday
 * ```
 * ?filter={ "key":"date" , "val":2 , "alt":"dw" }
 * // FILTER DATE_DAYOFWEEK(doc.date) == 2
 * ```
 *
 * Use the "dy" alter attribute to transform the document property with the DATE_DAYOFYEAR() method and use the day of year number of date :
 * The return values range from 1 to 365, or 366 in a leap year respectively.
 * ```
 * ?filter={ "key":"date" , "val":242 , "alt":"dy" }
 * // FILTER DATE_DAYOFYEAR(doc.date) == 242
 * ```
 *
 *
 * TODO ?filter={ "key":"created" , "from":"2024-12-21T10:00:00" , "to":"2024-12-24T10:00:00" , "tz":"Europe/Paris" }
 * ```
 */
trait HasFilterDate
{
    /**
     * Prepares the filter clause with a Date attribute.
     *
     * @param array $init
     * @param array|null $binds
     * @param string $doc
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    protected function prepareFilterDate( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ):string
    {
        return predicate
        (
            $this->prepareFilterKey        ( $init , $doc )  ,
            $this->prepareFilterComparator ( $init ) ,
            $this->prepareFilterDateValue  ( $init , $binds )
        ) ;
    }

    /**
     * Prepares the value of a date attribute in a filter clause.
     *
     * @throws BindException
     */
    protected function prepareFilterDateValue( string|array|null $init = [] , ?array &$binds = null ):string
    {
        $value = $init[ FilterParam::VAL ] ?? null ;

        $result = match( $value )
        {
            FilterDate::CURRENT_TIMESTAMP => dateNow(),
            FilterDate::TOMORROW          => tomorrow(),
            FilterDate::YESTERDAY         => yesterday(),
            FilterDate::NOW , null        => dateISO8601(),
            default                       => null,
        };

        if ( $result !== null )
        {
            return $result ;
        }

        $timezone = $init[ FilterParam::TZ ] ?? null ;
        return isValidTimezone( $timezone )
               ? dateLocalToUTC( $this->bind( $value , $binds ) , $this->bind( $timezone , $binds ) )
               : $this->bind( $value , $binds ) ;
    }
}