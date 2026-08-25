<?php

namespace oihana\arango\models\helpers\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use oihana\reflect\exceptions\ConstantException;

/**
 * Resolves the traversal direction of a linked facet: which way its edges are
 * followed from the listed document.
 *
 * The direction is not a detail of the AQL — it decides whether the facet finds
 * anything at all. A model whose edges **leave** the document (`doc` is the
 * `_from`) reaches its vertices `OUTBOUND`; one whose edges point **at** it
 * reaches them `INBOUND`. Follow the wrong way and the traversal is perfectly
 * valid and matches nothing, so the facet answers empty buckets in `200`
 * without a word — the shape of silent degradation this library refuses.
 *
 * Three rules, and each is deliberate:
 *
 * - the default is {@see Traversal::INBOUND}, which is what every linked facet
 *   compiled before this option existed — so a declaration that says nothing
 *   keeps its query byte for byte;
 * - {@see Traversal::ANY} is accepted, and means what it says: linked in either
 *   direction. It is the right answer for a relation that is not oriented —
 *   with the caveat that a document linked *both* ways to the same vertex is
 *   then reached twice, so `Facet::DISTINCT` earns its keep;
 * - an unknown value is **refused**, never quietly replaced. `Traversal::get()`
 *   would have fallen back on the default, turning a typo into empty buckets —
 *   the very failure the option exists to close — so {@see Traversal::validate()}
 *   answers instead.
 *
 * @param array $facet The facet definition. Reads `AQL::DIRECTION`.
 *
 * @return string The validated direction keyword.
 *
 * @throws ConstantException When the declared direction is not a `Traversal` keyword.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\facets\resolveFacetDirection;
 *
 * resolveFacetDirection( [] ) ;                                        // 'INBOUND'  (the default)
 * resolveFacetDirection( [ AQL::DIRECTION => Traversal::OUTBOUND ] ) ; // 'OUTBOUND'
 * resolveFacetDirection( [ AQL::DIRECTION => 'sideways' ] ) ;          // throws
 * ```
 *
 * @package oihana\arango\models\helpers\facets
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function resolveFacetDirection( array $facet ) : string
{
    $direction = $facet[ AQL::DIRECTION ] ?? Traversal::INBOUND ;

    Traversal::validate( $direction ) ;

    return $direction ;
}
