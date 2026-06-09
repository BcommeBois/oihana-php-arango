<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the list of facet dimensions for which per-value bucket counts are
 * computed alongside the document list (see
 * {@see \oihana\arango\models\traits\documents\DocumentsFacetCountsTrait::facetCounts()}).
 *
 * Driven by `?facetCounts=key1,key2` (CSV). Each key must be a configured facet
 * (`Arango::FACETS`); unknown keys are ignored at the model layer.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait PrepareFacetCountsTrait
{
    /**
     * Resolves the facet-count dimensions for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::FACET_COUNTS]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return array|null The list of facet keys, or null when none requested.
     */
    protected function prepareFacetCounts( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        $dimensions = $args[ Arango::FACET_COUNTS ] ?? [] ;
        if ( is_string( $dimensions ) )
        {
            $dimensions = array_map( 'trim' , explode( Char::COMMA , $dimensions ) ) ;
        }
        if ( !is_array( $dimensions ) )
        {
            $dimensions = [] ;
        }

        if ( isset( $request ) )
        {
            $value = getQueryParam( $request , Arango::FACET_COUNTS ) ;
            if ( is_string( $value ) && $value !== Char::EMPTY )
            {
                $params[ Arango::FACET_COUNTS ] = $value ;
                $dimensions = array_map( 'trim' , explode( Char::COMMA , $value ) ) ;
            }
        }

        $dimensions = array_values( array_filter( $dimensions , fn( $d ) => is_string( $d ) && $d !== Char::EMPTY ) ) ;

        return empty( $dimensions ) ? null : $dimensions ;
    }
}
