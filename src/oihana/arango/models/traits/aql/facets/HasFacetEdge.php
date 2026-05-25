<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\exceptions\BindException;
use org\schema\constants\Prop;
use ReflectionException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetEdge
{
    /**
     * Prepare a facet condition with an edge definition.
     *
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
     * ```
     * AQL::FACETABLE =>
     * [
     *     Prop::LOCATION =>
     *     [
     *        Facet::TYPE => Facet::EDGE,
     *        Facet::EDGE => 'organizations_places'
     *     ]
     * ]
     * ```
     * Use the facet :
     * ```
     * ?facets={"location":1234}
     * ```
     */
    protected function prepareFacetEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        $docRef = AQL::DOC_PREFIX . $key ;
        $fields = $facet[ AQL::FIELDS ] ?? Prop::_KEY ;
        $edge   = $facet[ AQL::EDGE   ] ?? null ;
        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER doc_$key.$fields == @$key RETURN doc_$key._key ) > 0
        return greaterThan( length
        ([
            aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] )    ,
            aqlFilter( equal( key( $fields , $docRef ) , $this->bind( (string) $value , $binds , $key ) ) ) ,
            aqlReturn( key( Prop::_KEY , $docRef ) )
        ])
        , 0 ) ;
    }
}