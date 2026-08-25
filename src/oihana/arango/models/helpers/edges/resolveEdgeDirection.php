<?php

namespace oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use oihana\reflect\exceptions\ConstantException;

/**
 * Resolves the traversal direction of an edge relation: which way its edges are
 * followed from the document that declares them.
 *
 * The direction is not a detail of the AQL — it decides whether the traversal
 * finds anything at all. A relation whose edges **leave** the document (`doc` is
 * the `_from`) reaches its vertices `OUTBOUND`; one whose edges point **at** it
 * reaches them `INBOUND`. Follow the wrong way and the traversal is perfectly
 * valid and matches nothing, so the relation projects an empty array in `200`
 * without a word.
 *
 * Two rules, and each is deliberate:
 *
 * - the default is {@see Traversal::OUTBOUND}, which every edge surface of the
 *   library already applies — the projection, the count, the grouped dimension,
 *   the hierarchical filters, the tree helpers and the write path;
 * - an unknown value is **refused**, never quietly replaced. {@see Traversal::get()}
 *   was used here before, and it falls back on its default when it does not
 *   recognise a value: a mistyped `'OUTBOUD'` compiled to `OUTBOUND` without a
 *   word, so a typo on an inbound relation produced exactly the empty projection
 *   the option exists to prevent. {@see Traversal::validate()} answers instead.
 *
 * A malformed declaration is a server-side mistake, so it is loud. A key coming
 * from the request is a different matter and keeps being dropped in silence —
 * the frontier the library draws everywhere else.
 *
 * @param array $definition The relation definition. Reads `AQL::DIRECTION`.
 *
 * @return string The validated direction keyword.
 *
 * @throws ConstantException When the declared direction is not a `Traversal` keyword.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\edges\resolveEdgeDirection;
 *
 * resolveEdgeDirection( [] ) ;                                        // 'OUTBOUND' (the default)
 * resolveEdgeDirection( [ AQL::DIRECTION => Traversal::INBOUND ] ) ;  // 'INBOUND'
 * resolveEdgeDirection( [ AQL::DIRECTION => 'OUTBOUD' ] ) ;           // throws
 * ```
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function resolveEdgeDirection( array $definition ) : string
{
    $direction = $definition[ AQL::DIRECTION ] ?? Traversal::OUTBOUND ;

    Traversal::validate( $direction ) ;

    return $direction ;
}
