<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\operators\isMatch;
use function oihana\arango\db\operators\notMatch;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetField
{
    /**
     * Prepare the facets based on specific document's properties.
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @return string
     * @throws BindException
     * @example
     * Set the facetable definition in the model :
     * ```
     * AQL::FACETABLE =>
     * [
     *     Prop::ID          => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => '_key' ] ,
     *     Prop::WITH_STATUS => [ Facet::TYPE => Facet::FIELD ]
     * ]
     * ```
     * Display only the documents with the withStatus property equals to 'under_review' :
     * ```
     * ?facets={"withStatus":"under_review"}
     * ?facets={"withStatus":"draft,under_review"}
     * ```
     * Use the '-' prefix to excludes all documents with the withStatus properties :
     * ```
     * ?facets={"withStatus":"-draft"} // exclude the draft value
     * ?facets={"withStatus":"-under_review,-draft"} // exclude two negative values
     * ```
     * Special case with the 'id' property :
     * ```
     * ?facets={"id":"25256"} // the id property target the internal '_key' document property.
     * ```
     */
    protected function prepareFacetField( string $key , mixed $value , array &$binds , array $facet , string $doc ):string
    {
        $values = explode( Char::COMMA , $value ) ;
        if( count( $values ) > 0  )
        {
            $conditions = [] ;
            $logic      = Logic::OR ;

            $property = $facet[ Facet::PROPERTY ] ?? $key ;

            foreach( $values as $subKey => $value )
            {
                $negative    = !empty( $value ) && strlen( $value ) > 1 && $value[0] == Char::HYPHEN ;
                $value       = $negative ? ltrim( $value ,Char::HYPHEN ) : $value ;

                $left  = key( $property , $doc ) ;
                $right = $this->bind( $value , $binds , $key . Char::UNDERLINE . $subKey ) ;

                $conditions[] = $negative ? notMatch( $left , $right ) : isMatch( $left , $right ) ; // TODO test it

                if( $negative )
                {
                    $logic = Logic::AND ;
                }
            }

            if( count( $conditions ) > 0 )
            {
                return betweenParentheses( predicates( $conditions , $logic ) );
            }
        }
        return  Char::EMPTY ;
    }
}