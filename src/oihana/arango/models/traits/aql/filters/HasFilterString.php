<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use function oihana\arango\db\functions\strings\startsWith;
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
 * ?filter={ "key":"name" , "val":"leon"           , "op":"ew"    } // TODO ends with
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
        $op = $init[ FilterParam::OP ] ?? null ;

        if ( $op === FilterComparator::BETWEEN )
        {
            return $this->prepareFilterBetween( $init , $binds , $doc , fn( $value , &$binds ) => $this->bind( $value , $binds ) , false ) ;
        }

        // `sw` (starts with) is a function-form operator, not an infix comparator:
        // STARTS_WITH(key, value). The prefix is matched literally (no wildcards),
        // so the value is bound as-is; alt stays available on both sides.
        if ( $op === FilterComparator::SW )
        {
            return startsWith
            (
                $this->prepareFilterKey( $init , $doc ) ,
                $this->prepareFilterValue( $init , $binds )
            ) ;
        }

        return predicate
        (
            $this->prepareFilterKey( $init , $doc ) ,
            $this->prepareFilterComparator( $init ) ,
            $this->prepareFilterValue( $init , $binds )
        ) ;
    }
}