<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\enums\Facet;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Prop;

use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\toArray;
use function oihana\core\strings\betweenBrackets;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetListField
{
    /**
     * @param string $key
     * @param mixed $value
     * @param $binds
     * @param array $facet
     * @param string $doc
     * @param bool $sortable
     * @return string
     * @throws BindException
     */
    protected function prepareFacetListField( string $key , mixed $value , &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        $values = explode( Char::COMMA , $value ) ;
        if( count( $values ) > 0 )
        {
            $facets = [] ;

            $property = $facet[ Facet::PROPERTY ] ?? $key ;
            if( $property == Prop::ID )
            {
                $property = Prop::_KEY ; // Reserved keyword : id => _key
            }

            $docProp = key( $property , $doc ) ;

            foreach( $values as $subKey => $value )
            {
                $facets[] = $this->bind( $value , $binds , $key . Char::UNDERLINE . $subKey ) ;
            }

            $facets = betweenBrackets( compile( $facets , Char::COMMA ) ) ;

            // TO_ARRAY([array[0],array[1],...array[n-1]] ) ANY IN $doc.$property
            // [ SORT POSITION([array[0],array[1],...array[n-1]] , $doc.$property , true ) ]
            return compile
            ([
                toArray( $facets ) ,
                Traversal::ANY , Comparator::IN , $docProp ,
                $sortable ? compile( [ Operation::SORT , position( $facets , $docProp , true ) ] ) : null
            ]);
        }
        return Char::EMPTY ;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     */
    protected function prepareFacetListFieldSorted( string $key , mixed $value , &$binds , array $facet , string $doc ):string
    {
        return $this->prepareFacetListField( $key , $value , $binds , $facet , $doc , true ) ;
    }
}