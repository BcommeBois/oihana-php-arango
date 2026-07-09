<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the `metaOnly` flag of a list query.
 *
 * Driven by `?metaOnly=true` (any {@see filter_var()} boolean form: `true`, `1`,
 * `yes`, `on`). When truthy, the controller skips the document-fetch query
 * entirely: the list returns an empty result set, while the response *metadata*
 * — an exact `total` (from {@see \oihana\arango\models\traits\documents\DocumentsCountTrait::count()}),
 * and, when requested, the facet counts (`?facetCounts=`) and the numeric bounds
 * (`?bounds=`) — is still computed. This is the "give me the sidebar, not the
 * documents" mode.
 *
 * It supersedes the counts-only {@see PrepareFacetsOnlyTrait} (`?facetsOnly=`),
 * which stays a truthy alias.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait PrepareMetaOnlyTrait
{
    /**
     * Resolves the `metaOnly` flag for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::META_ONLY]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return bool True when only the metadata (no documents) is requested.
     */
    protected function prepareMetaOnly( ?Request $request , array $args = [] , ?array &$params = null ) :bool
    {
        $metaOnly = filter_var( $args[ Arango::META_ONLY ] ?? false , FILTER_VALIDATE_BOOLEAN ) ;

        if ( isset( $request ) )
        {
            $value = getQueryParam( $request , Arango::META_ONLY ) ;
            if ( is_string( $value ) && $value !== Char::EMPTY )
            {
                $params[ Arango::META_ONLY ] = $value ;
                $metaOnly = filter_var( $value , FILTER_VALIDATE_BOOLEAN ) ;
            }
        }

        return $metaOnly ;
    }
}
