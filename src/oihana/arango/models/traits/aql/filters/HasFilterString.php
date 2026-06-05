<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\functions\StringFunction;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\strings\charLength;
use function oihana\arango\db\functions\strings\startsWith;
use function oihana\core\strings\func;
use function oihana\core\strings\predicate;

/**
 * This trait defines the date filter helpers.
 * ### Configure
 * Defines the 'filters' property in the model (Documents) definition.
 * ```
 * Models::PLACES => fn( ContainerInterface $container ) => new Documents
 * (
 *     $container ,
 *     Collections::PLACES ,
 *     [
 *         ...
 *         AQL::FILTERS =>
 *         [
 *              Prop::NAME => FilterType::STRING ,
 *              ...
 *         ]
 *         ...
 * ```
 * @example
 * ```
 * ?filter={ "key":"name" , "val":"ekameleon" }
 * ```
 *
 * Filter with the basic operators
 * ```
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"eq"    } // equals (default)
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"ne"    } // not equals
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"gt"    } // greater than
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"ge"    } // greater than or equals
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"lt"    } // less than
 * ?filter={ "key":"name" , "val":"ekameleon"      , "op":"le"    } // less than or equals
 * ?filter={ "key":"name" , "val":"eka%"           , "op":"like"  } // like
 * ?filter={ "key":"name" , "val":"eka%"           , "op":"nlike" } // not like
 * ?filter={ "key":"name" , "val":"leon"           , "op":"ew"    } // ends with   -> RIGHT(doc.name, CHAR_LENGTH("leon")) == "leon"
 * ?filter={ "key":"name" , "val":"ekam"           , "op":"sw"    } // starts with -> STARTS_WITH(doc.name, "ekam")
 * ?filter={ "key":"name" , "val":["eka","meleon"] , "op":"in"    } // in TODO
 * ?filter={ "key":"name" , "val":["eka","meleon"] , "op":"nin"   } // not in TODO
 * ```
 *
 * Use functions to transform the document property before the conditional evaluation.
 * ```
 * ?filter={ "key":"name" , "val":"EKAMELEON" , "alt":"upper" } // UPPER(value) == "EKAMELEON"
 * ?filter={ "key":"name" , "val":"ekameleon" , "alt":"lower" } // LOWER(value) == "ekameleon"
 * ?filter={ "key":"name" , "val":"ekameleon" , "alt":"trim" , type:0 } // TRIM(value,type)
 * ?filter={ "key":"name" , "val":9           , "alt":"length" } // LENGTH(value) == 9
 * ```
 */
trait HasFilterString
{
    /**
     * Builds an `ew` (ends with) string filter.
     *
     * AQL has no `ENDS_WITH` function, so the suffix is matched literally with
     * `RIGHT(key, CHAR_LENGTH(value)) == value` — no `LIKE` pattern, nothing to
     * escape, symmetric to the literal `sw` / `STARTS_WITH` form. The value is
     * bound once and reused; `alt` stays available on both sides (e.g. the
     * `{key:lower, val:true}` mirror yields `RIGHT(LOWER(doc.x), …) == LOWER(@v)`,
     * a case-insensitive ends-with).
     *
     * ```aql
     * RIGHT(doc.name, CHAR_LENGTH(@value)) == @value
     * ```
     *
     * @param array $init The filter init (`op` = `ew`).
     * @param array|null $binds The bind variables, populated by reference.
     * @param string $doc The document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterEndsWith( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ):string
    {
        $key   = $this->prepareFilterKey( $init , $doc ) ;
        $value = $this->prepareFilterValue( $init , $binds ) ;

        return predicate
        (
            func( StringFunction::RIGHT , [ $key , charLength( $value ) ] ) ,
            Comparator::EQUAL ,
            $value
        ) ;
    }

    /**
     * Prepares the filter clause with a string attribute.
     *
     * @param array $init
     * @param array|null $binds
     * @param string $doc
     * @return string
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterString( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ):string
    {
        return match ( $init[ FilterParam::OP ] ?? null )
        {
            FilterComparator::BETWEEN => $this->prepareFilterBetween( $init , $binds , $doc , fn( $value , &$binds ) => $this->bind( $value , $binds ) , false ) ,
            FilterComparator::SW      => startsWith( $this->prepareFilterKey( $init , $doc ) , $this->prepareFilterValue( $init , $binds ) ) ,
            FilterComparator::EW      => $this->prepareFilterEndsWith( $init , $binds , $doc ) ,
            default                   => predicate
            (
                $this->prepareFilterKey( $init , $doc ) ,
                $this->prepareFilterComparator( $init ) ,
                $this->prepareFilterValue( $init , $binds )
            ) ,
        } ;
    }
}