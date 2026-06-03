<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use org\schema\constants\Prop;
use ReflectionException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Builds the AQL filter fragment for an {@see Facet::EDGE} facet: it keeps
 * documents linked (or, when negated, not linked) to a target vertex through
 * an inbound edge traversal. Composed into the model via {@see FacetTrait}.
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
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
     * ?facets={"location":1234}                 // linked to vertex 1234
     * ?facets={"location":"1234,5678"}          // linked to 1234 OR 5678
     * ?facets={"location":"-1234"}              // NOT linked to 1234
     * ?facets={"location":"1234,-5678"}         // linked to 1234 AND not linked to 5678
     * ```
     *
     * Values are comma-separated. A leading `-` negates a term: positive terms
     * are OR-ed inside a single existential traversal (`LENGTH(...) > 0`), while
     * negated terms exclude the document (`LENGTH(...) == 0`).
     */
    protected function prepareFacetEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        $docRef = AQL::DOC_PREFIX . $key ;
        $fields = $facet[ AQL::FIELDS ] ?? Prop::_KEY ;
        $edge   = $facet[ AQL::EDGE   ] ?? null ;

        $for    = aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] ) ;
        $return = aqlReturn( key( Prop::_KEY , $docRef ) ) ;
        $field  = key( $fields , $docRef ) ;

        $positives = [] ;
        $negatives = [] ;

        foreach( explode( Char::COMMA , (string) $value ) as $index => $term )
        {
            $negative = $term !== Char::EMPTY && $term[ 0 ] === Char::HYPHEN ;
            $term     = $negative ? ltrim( $term , Char::HYPHEN ) : $term ;
            $bind     = $this->bind( $term , $binds , $key . Char::UNDERLINE . $index ) ;

            if( $negative ) { $negatives[] = equal( $field , $bind ) ; }
            else            { $positives[] = equal( $field , $bind ) ; }
        }

        $clauses = [] ;

        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER ...positives RETURN doc_$key._key ) > 0
        if( !empty( $positives ) )
        {
            $clauses[] = greaterThan( length( [ $for , aqlFilter( predicates( $positives , Logic::OR ) ) , $return ] ) , 0 ) ;
        }

        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER ...negatives RETURN doc_$key._key ) == 0
        if( !empty( $negatives ) )
        {
            $clauses[] = equal( length( [ $for , aqlFilter( predicates( $negatives , Logic::OR ) ) , $return ] ) , 0 ) ;
        }

        $result = predicates( $clauses , Logic::AND ) ;

        return count( $clauses ) > 1 ? betweenParentheses( $result ) : $result ;
    }
}