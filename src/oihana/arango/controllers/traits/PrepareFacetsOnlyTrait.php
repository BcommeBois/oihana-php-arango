<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the `facetsOnly` flag of a list query.
 *
 * Driven by `?facetsOnly=true` (any {@see filter_var()} boolean form: `true`,
 * `1`, `yes`, `on`). When truthy, the controller skips the document-fetch query
 * entirely: the list returns an empty result set, while an exact `total` (from
 * {@see \oihana\arango\models\traits\documents\DocumentsCountTrait::count()}) and,
 * when `?facetCounts=…` is also present, the per-value facet counts are still
 * returned. This is the "counts sidebar without the documents" mode.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait PrepareFacetsOnlyTrait
{
    /**
     * Resolves the `facetsOnly` flag for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::FACETS_ONLY]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return bool True when only the counts (no documents) are requested.
     */
    protected function prepareFacetsOnly( ?Request $request , array $args = [] , ?array &$params = null ) :bool
    {
        $facetsOnly = filter_var( $args[ Arango::FACETS_ONLY ] ?? false , FILTER_VALIDATE_BOOLEAN ) ;

        if ( isset( $request ) )
        {
            $value = getQueryParam( $request , Arango::FACETS_ONLY ) ;
            if ( is_string( $value ) && $value !== Char::EMPTY )
            {
                $params[ Arango::FACETS_ONLY ] = $value ;
                $facetsOnly = filter_var( $value , FILTER_VALIDATE_BOOLEAN ) ;
            }
        }

        return $facetsOnly ;
    }
}
