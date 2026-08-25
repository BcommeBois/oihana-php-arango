<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\enums\FacetParam;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;

use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the per-request number of buckets each counted dimension keeps
 * (see {@see \oihana\arango\models\traits\queries\FacetCountsQueryTrait}).
 *
 * Driven by `?facetCountsLimit=`, which takes a **positive integer** or the
 * {@see FacetParam::ALL} keyword. It overrides the `Facet::LIMIT` declared on
 * the facet — a **default**, not a ceiling — so a request may raise it, lower
 * it, or cancel it outright:
 *
 * ```
 * ?facetCounts=keywords                        // the declaration decides
 * ?facetCounts=keywords&facetCountsLimit=25    // the 25 biggest buckets
 * ?facetCounts=keywords&facetCountsLimit=all   // every bucket
 * ```
 *
 * There is no ceiling to enforce, and that is a property of how the counts are
 * built rather than an oversight: the `COLLECT` computes every bucket whatever
 * the limit, which only trims what travels. A request asking for more buckets
 * than exist receives the ones that exist — the behaviour it would get with no
 * limit at all.
 *
 * **The keyword never reaches the model.** It is translated here into `false`,
 * the model-level way to say "explicitly unlimited", which the model tells apart
 * from an absent parameter ("use the declaration"). The same separation
 * {@see PrepareGroupTrait::prepareGroup()} keeps between the HTTP vocabulary and
 * the model's own.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
trait PrepareFacetCountsLimitTrait
{
    /**
     * Resolves the per-request bucket limit for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::FACET_COUNTS_LIMIT]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return int|false|null The number of buckets to keep, `false` for every
     *                        bucket, or null when the declaration decides.
     *
     * @throws RequestValidationException When the request sent something that is
     *                                    neither a positive integer nor `all`.
     */
    protected function prepareFacetCountsLimit( ?Request $request , array $args = [] , ?array &$params = null ) :int|false|null
    {
        $limit = $args[ Arango::FACET_COUNTS_LIMIT ] ?? null ;

        if ( isset( $request ) )
        {
            $value = getQueryParam( $request , Arango::FACET_COUNTS_LIMIT ) ;
            if ( is_string( $value ) && $value !== Char::EMPTY )
            {
                $params[ Arango::FACET_COUNTS_LIMIT ] = $value ;
                $limit = $value ;
            }
        }

        if ( $limit === null || $limit === false || is_int( $limit ) )
        {
            return $limit ; // already model-level (a base option, or nothing sent).
        }

        if ( is_string( $limit ) && strtolower( trim( $limit ) ) === FacetParam::ALL )
        {
            return false ; // "all" — every bucket, cancelling any declared limit.
        }

        // A digits-only string is the normal shape from the wire. Anything else
        // — a float, a negative, `0`, a word — is refused: the caller wrote
        // something the API cannot read, and `0` in particular would mean the
        // opposite of what it says (see FacetCountsQueryTrait).
        if ( is_string( $limit ) && ctype_digit( $limit ) && (int) $limit > 0 )
        {
            return (int) $limit ;
        }

        throw new RequestValidationException( sprintf
        (
            'Invalid "%s": %s. Expected a positive integer (the number of buckets to keep) or "%s" for every bucket.' ,
            Arango::FACET_COUNTS_LIMIT ,
            is_scalar( $limit ) ? var_export( $limit , true ) : get_debug_type( $limit ) ,
            FacetParam::ALL ,
        )) ;
    }
}
