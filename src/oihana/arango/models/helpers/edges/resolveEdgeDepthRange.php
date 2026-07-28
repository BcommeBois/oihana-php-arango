<?php

namespace oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;

/**
 * Resolves the traversal depth range declared by an edge definition — the
 * `[ $minDepth , $maxDepth ]` pair handed to {@see \oihana\arango\db\operations\aqlTraversal()}.
 *
 * A self-referential relation (a thesaurus, a category tree, an org chart) can
 * project several levels in a single traversal through `AQL::MAX_DEPTH`. The rules:
 *
 * - **Neither declared → `[ null , null ]`**, the traversal stays at depth 1 and the
 *   emitted AQL is byte-for-byte the un-ranged one.
 * - **`AQL::MAX_DEPTH` alone** defaults the lower bound to `1` — the natural `1..N`.
 * - **`AQL::MIN_DEPTH` alone is refused.** ArangoDB requires a bounded range, and an
 *   unbounded traversal over a self-referential edge risks a runaway cycle.
 *
 * This lives in its own helper because **the list and the count must read the
 * declaration the same way**. They did not: `buildEdgeCountVariable()` ignored the
 * range entirely, so a definition declaring `AQL::MAX_DEPTH => 5` produced a list of
 * the whole descent beside a count of the direct children — measured live as `4`
 * rows under a count saying `2`. A shared door makes that divergence impossible to
 * reintroduce, including the refusal rule.
 *
 * @param array $definition The edge definition (reads `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH`).
 *
 * @return array{0: int|null, 1: int|null} The `[ $minDepth , $maxDepth ]` pair.
 *
 * @throws UnexpectedValueException If `AQL::MIN_DEPTH` is declared without `AQL::MAX_DEPTH`.
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function resolveEdgeDepthRange( array $definition ) :array
{
    $minDepth = $definition[ AQL::MIN_DEPTH ] ?? null ;
    $maxDepth = $definition[ AQL::MAX_DEPTH ] ?? null ;

    if ( $minDepth !== null && $maxDepth === null )
    {
        throw new UnexpectedValueException
        (
            __FUNCTION__ . ' failed, a ranged edge projection requires AQL::MAX_DEPTH (an unbounded traversal is not allowed).'
        ) ;
    }

    if ( $maxDepth !== null && $minDepth === null )
    {
        $minDepth = 1 ;
    }

    return [ $minDepth , $maxDepth ] ;
}
