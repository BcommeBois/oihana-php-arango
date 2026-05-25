<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use org\schema\constants\Prop;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetEdgeComplex
{
    /**
     * Prepares a edge complex facet.
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     *
     * @example
     * Set the facetable definition in the model :
     * ```php
     * AQL::FACETABLE =>
     * [
     *     Prop::NUMBERS =>
     *     [
     *         Facet::TYPE => Facet::EDGE_COMPLEX,
     *         Facet::EDGE => 'livestocks_has_numbers'
     *     ]
     * ]
     * ```
     *
     * Use the facet :
     * ```
     * ?facets={"numbers":{"value":"459875642"}}
     * ```
     */
    protected function prepareFacetEdgeComplex
    (
        string    $key ,
        mixed   $value ,
        array  &$binds ,
        array   $facet ,
        string    $doc
    )
    :string
    {
        $docRef  = AQL::DOC_REF . $key ;
        $edge    = $facet[ AQL::EDGE ] ?? null ;
        $filters = [] ;
        foreach( $value as $subKey => $s )
        {
            $filters[] = equal
            (
                key( $subKey , $docRef ) ,
                $this->bind( $s , $binds , $key . Char::UNDERLINE . $subKey )
            ) ;
        }
        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER ...$filters RETURN doc_$key._key ) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] )    ,
            aqlFilter ( predicates( $filters ,  Logic::AND ) ) ,
            aqlReturn ( key( Prop::_KEY , $docRef ) )
        ]) , 0 ) ;
    }
}