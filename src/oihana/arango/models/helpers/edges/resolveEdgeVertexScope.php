<?php

namespace oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\fields\buildWhenCondition;
use function oihana\arango\db\operators\logicalNot;

/**
 * Compiles the **row scope** an edge definition declares over the vertices it
 * traverses — the `[ $filter , $prune ]` pair, both already AQL predicate strings
 * targeting `$vertexRef`, or `null` when nothing is declared.
 *
 * Two keys, answering two different questions:
 *
 * - **`AQL::WHERE`** — WHICH vertices the relation yields. Compiled from the
 *   `Field::WHEN` grammar, so a value may hold an `aqlBindRef()` and the retained set
 *   is decided at query time (a bind bound to `[]` retains nothing, an absent bind
 *   fails the query — never "no filter"). Emitted as a `FILTER` after the traversal.
 * - **`AQL::PRUNE`** — whether the walk STOPS there. A `FILTER` only filters the
 *   traversal's output: on a ranged relation the walk still descends *through* a
 *   masked vertex, so its descendants keep being projected. `true` reuses the
 *   `AQL::WHERE` predicate negated ("hide it and its descent"); a condition of its
 *   own covers the case where stopping is not hiding. A condition is compiled rather
 *   than read as a boolean — anything written there is truthy, so it would otherwise
 *   be silently swapped for the negated `AQL::WHERE`. `false` means OFF, like an
 *   absent key, so a `AQL::PRUNE => $flag` toggle works both ways.
 *
 * The two are emitted **together** and neither replaces the other: `PRUNE` stops the
 * walk *after* visiting, so the vertex it stops on is still returned unless the
 * `FILTER` removes it.
 *
 * This lives in its own helper because **the list and the count must read the
 * declaration the same way** — a count that scoped differently from the list would
 * announce a number the rows contradict, which is the whole class of bug these keys
 * exist to close.
 *
 * @param array  $definition The edge definition (reads `AQL::WHERE` / `AQL::PRUNE`).
 * @param string $vertexRef  The AQL variable of the traversed vertex both predicates target.
 *
 * @return array{0: string|null, 1: string|null} The `[ $filter , $prune ]` predicates.
 *
 * @throws UnexpectedValueException      If `AQL::PRUNE => true` has no `AQL::WHERE` to negate.
 * @throws UnsupportedOperationException If a condition descriptor is malformed.
 * @throws ValidationException           If a condition attribute name is unsafe.
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function resolveEdgeVertexScope( array $definition , string $vertexRef ) :array
{
    $where  = $definition[ AQL::WHERE ] ?? null ;
    $filter = $where !== null ? buildWhenCondition( $where , $vertexRef ) : null ;

    $prune = $definition[ AQL::PRUNE ] ?? null ;

    // `false` means OFF, exactly like an absent key. The key accepts a boolean, so a
    // host naturally writes `AQL::PRUNE => $cutDescendants` — and a false flag must
    // not blow up the query build. Only `true` triggers the negation below; anything
    // else is compiled as a condition, so a malformed value still fails loud.
    if ( $prune === false )
    {
        $prune = null ;
    }

    if ( $prune === true )
    {
        // Nothing to negate is a wiring error, not "no pruning": staying silent would
        // leave the masked descent projected, the very thing the key closes.
        if ( $filter === null )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, AQL::PRUNE => true has no AQL::WHERE condition to negate.'
            ) ;
        }

        $prune = logicalNot( $filter , true ) ;
    }
    else if ( $prune !== null )
    {
        $prune = buildWhenCondition( $prune , $vertexRef ) ;
    }

    return [ $filter , $prune ] ;
}
