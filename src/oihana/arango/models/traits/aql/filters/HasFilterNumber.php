<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
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
 *              Prop::CAPACITY => FilterType::NUMBER ,
 *              ...
 *         ]
 *         ...
 * ```
 * @example
 * ```
 * ?filter={ "key":"price" , "val":100 , "op":"ge"}
 * ```
 */
trait HasFilterNumber
{
    /**
     * Prepares the filter clause with a string attribute.
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    protected function prepareFilterNumber( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ):string
    {
        return predicate
        (
            $this->prepareFilterKey( $init , $doc ) ,
            $this->prepareFilterComparator( $init ) ,
            $this->prepareFilterValue( $init , $binds )
        ) ;
    }
}