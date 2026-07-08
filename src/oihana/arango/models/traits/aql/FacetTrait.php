<?php

namespace oihana\arango\models\traits\aql;

use Exception;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\aql\facets\HasFacetArrayComplex;
use oihana\arango\models\traits\aql\facets\HasFacetEdge;
use oihana\arango\models\traits\aql\facets\HasFacetEdgeAggregate;
use oihana\arango\models\traits\aql\facets\HasFacetEdgeComplex;
use oihana\arango\models\traits\aql\facets\HasFacetField;
use oihana\arango\models\traits\aql\facets\HasFacetIn;
use oihana\arango\models\traits\aql\facets\HasFacetJoin;
use oihana\arango\models\traits\aql\facets\HasFacetJoinAggregate;
use oihana\arango\models\traits\aql\facets\HasFacetJoinComplex;
use oihana\arango\models\traits\aql\facets\HasFacetList;
use oihana\arango\models\traits\aql\facets\HasFacetListField;
use oihana\enums\Boolean;
use oihana\enums\Char;

use function oihana\arango\models\helpers\isAttributeAuthorized;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\core\strings\predicates;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait FacetTrait
{
    use HasFacetArrayComplex  ,
        HasFacetEdge          ,
        HasFacetEdgeAggregate ,
        HasFacetEdgeComplex   ,
        HasFacetField         ,
        HasFacetIn            ,
        HasFacetJoin          ,
        HasFacetJoinAggregate ,
        HasFacetJoinComplex   ,
        HasFacetList          ,
        HasFacetListField     ;

    /**
     * The facet settings.
     */
    public ?array $facets = [] ;

    /**
     * The 'facets' parameter constant.
     */
    public const string FACETS = 'facets' ;

    /**
     * Initialize the 'facets' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeFacets( array $init = [] ):static
    {
        $this->facets = $init[ self::FACETS ] ?? $this->facets ;
        return $this ;
    }

    /**
     * Prepare the query with AQL facets definitions.
     * @param array|null $init
     * @param ?array $binds
     * @param string $docRef
     * @param string $logicalOperator
     * @return ?string
     */
    protected function prepareFacets
    (
        ?array $init ,
        ?array &$binds          = null ,
        string $docRef          = AQL::DOC ,
        string $logicalOperator = Logic::AND
    )
    :?string
    {
        $facets = $init[ Arango::FACETS ] ?? null ;
        if( is_array( $facets ) && is_array( $this->facets ) )
        {
            // $this->logger->info( $this . ' prepareFacets : ' . json_encode( $facets ) ) ;
            $predicates = [] ;
            foreach( $facets as $key => $value )
            {
                $facet = $this->facets[ $key ] ?? null ;
                if( !is_null( $facet ) )
                {
                    // Permission gate: a facet on a field hidden from the projection
                    // (Field::REQUIRES, inherited from $fields or declared on the facet)
                    // is NEUTRALISED to `false` — a hidden field is not filterable through
                    // a facet (no facet oracle). Never dropped: dropping an ANDed facet
                    // would loosen the result, itself a leak.
                    if ( !isAttributeAuthorized( $key , $this->fields ?? null , $init ) || !isAuthorized( $facet , $init ) )
                    {
                        $predicates[] = Boolean::FALSE ;
                        continue ;
                    }

                    $type = $facet[ Facet::TYPE ] ?? null ;

                    // A malformed facet (invalid bind name, reflection error, …) must
                    // never break the whole query: log it and skip this facet. This
                    // mirrors the lenient `?filter=` behaviour.
                    try
                    {
                        $predicates[] = match ( $type )
                        {
                            Facet::ARRAY_COMPLEX        => $this->prepareFacetArrayComplex    ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::EDGE                 => $this->prepareFacetEdge            ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::EDGE_AGGREGATE       => $this->prepareFacetEdgeAggregate   ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::EDGE_COMPLEX         => $this->prepareFacetEdgeComplex     ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::IN                   => $this->prepareFacetIn              ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::JOIN                 => $this->prepareFacetJoin            ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::JOIN_AGGREGATE       => $this->prepareFacetJoinAggregate   ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::JOIN_COMPLEX         => $this->prepareFacetJoinComplex     ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::LIST                 => $this->prepareFacetList            ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::LIST_FIELD           => $this->prepareFacetListField       ( $key , $value , $binds , $facet , $docRef ) ,
                            Facet::LIST_FIELD_SORTED    => $this->prepareFacetListFieldSorted ( $key , $value , $binds , $facet , $docRef ) ,
                            /* Facet::FIELD */ default  => $this->prepareFacetField           ( $key , $value , $binds , $facet , $docRef ) ,
                        };
                    }
                    catch( Exception $e )
                    {
                        $this->logger?->warning( $this . " prepareFacets failed, the '" . $key . "' facet is not valid, " . $e->getMessage() ) ;
                    }
                }
            }

            return predicates( $predicates , $logicalOperator ) ;
        }
        return Char::EMPTY ;
    }
}