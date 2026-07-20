<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
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
 *              Prop::ACTIVE => FilterType::BOOL ,
 *         ]
 *         ...
 * ```
 * @example
 * ```
 * ?filter={ "key":"flag" , "val":true  }
 * ?filter={ "key":"flag" , "val":false }
 * ```
 */
trait HasFilterBoolean
{
    /**
     * Prepares the filter clause with a boolean attribute.
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
    protected function prepareFilterBoolean( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ):string
    {
        return predicate
        (
            $this->prepareFilterKey( $init , $doc ) ,
            $this->prepareFilterComparator( $init ) ,
            $this->prepareFilterBooleanValue( $init , $binds ) ,
        ) ;
    }

    /**
     * Prepare the filter clause with a specific boolean value to evaluates.
     * @param array|null $init
     * @param array|null $binds
     * @return string
     * @throws BindException
     */
    protected function prepareFilterBooleanValue( ?array $init = [] , ?array &$binds = null ):string
    {
        return $this->bind( ( $init[ FilterParam::VAL ] ?? null ) === true , $binds ) ;
    }
}