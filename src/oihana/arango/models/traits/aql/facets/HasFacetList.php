<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Prop;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetList
{
    use HasFacetListField ;

    /**
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     *
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     *
     * @example
     * Set the facetable definition in the model :
     * ```
     * AQL::FACETABLE =>
     * [
     *     Prop::KEYWORDS =>
     *     [
     *         Facet::TYPE     => Facet::LIST ,
     *         Facet::PROPERTY => Prop::KEYWORDS
     *     ]
     * ]
     * ```
     * Use the facet :
     * ```
     * ?facets={"keywords":"key1,key2"}
     * ```
     */
    protected function prepareFacetList( string $key , mixed $value , array &$binds , array $facet , string $doc ):string
    {
        if( is_array( $value ) && count( $value ) == 1 && array_key_exists( Prop::LENGTH , $value ) )
        {
            $property = $facet[ Facet::PROPERTY ] ?? Prop::_KEY ;
            $docRef   = AQL::DOC_PREFIX . Prop::LENGTH ;
            // LENGTH( FOR doc_length IN $this->collection FILTER $doc._id IN doc_length.$property LIMIT 1 RETURN 1 ) == $value[ Prop::LENGTH ]
            return equal
            (
                length( compile
                ([
                    aqlFor    ( [ AQL::DOC_REF => $docRef , AQL::IN => $this->collection ] ) ,
                    aqlFilter ( in( key( Prop::_ID , $doc ) , key( $property , $docRef ) ) ) ,
                    aqlLimit  ( 1 ) , // TODO test it !! // Operation::LIMIT , '1' ,
                    aqlReturn ( 1 )
                ])) ,
                $value[ Prop::LENGTH ]
            );
        }
        else if( is_string( $value ) )
        {
            return $this->prepareFacetListField( $key , $value , $binds , $facet , $doc ) ;
        }
        return Char::EMPTY ;
    }
}