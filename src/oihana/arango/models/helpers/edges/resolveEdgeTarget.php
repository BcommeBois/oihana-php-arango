<?php

namespace oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\Traversal;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

/**
 * Resolves the vertex model a traversal reaches — the model whose fields are
 * projected, whose `Field::REQUIRES` gate the projection, and whose own
 * relations the walk continues through.
 *
 * An edge has two ends, and the direction says which one the walk lands on:
 * `INBOUND` reaches the `_from` model, `OUTBOUND` the `_to` model. That much was
 * already written, three times, as a binary ternary — and a binary ternary has
 * no room for the third keyword:
 *
 * ```php
 * $documents = $direction == Traversal::INBOUND ? $model->from : $model->to ; // ANY silently lands on `to`
 * ```
 *
 * {@see Traversal::ANY} reaches **both** ends, so on a heterogeneous relation no
 * single model describes what comes back: the vertices of the far side were
 * projected with the near side's fields and, worse, gated by the near side's
 * permissions — a field masked on one collection served through the other. It is
 * therefore **refused** rather than resolved to half of it.
 *
 * `ANY` stays perfectly valid where it is meaningful — a **self-referential**
 * relation, both ends on the same collection (`user_follows`, a thesaurus), which
 * is the case it exists for. It then keeps resolving to the `_to` end, the one the
 * ternary picked, so an unambiguous declaration compiles byte for byte as before.
 * Both ends being unwired is left alone too: the model declares no vertex model at
 * all, and the callers that tolerate that keep their behaviour.
 *
 * @param Edges  $model     The edge model carrying the two vertex ends.
 * @param string $direction A validated `Traversal` keyword, as returned by
 *                          {@see resolveEdgeDirection()}.
 *
 * @return Documents|null The reached vertex model, or `null` when that end is not wired.
 *
 * @throws UnexpectedValueException When `ANY` cannot designate a single reached model.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\edges\resolveEdgeTarget;
 *
 * resolveEdgeTarget( $articlesAuthors , Traversal::OUTBOUND ) ; // the `authors` model
 * resolveEdgeTarget( $articlesAuthors , Traversal::INBOUND  ) ; // the `articles` model
 * resolveEdgeTarget( $userFollows     , Traversal::ANY      ) ; // the `users` model (both ends)
 * resolveEdgeTarget( $articlesAuthors , Traversal::ANY      ) ; // throws — two different ends
 * ```
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function resolveEdgeTarget( Edges $model , string $direction ) : ?Documents
{
    if( $direction === Traversal::INBOUND )
    {
        return $model->from ;
    }

    if( $direction === Traversal::OUTBOUND )
    {
        return $model->to ;
    }

    if( $model->from?->collection !== $model->to?->collection )
    {
        throw new UnexpectedValueException( sprintf
        (
            '%s failed, the edge collection "%s" is declared with %s but its two ends are not the same collection ("%s" and "%s"): a projection reaches one vertex model, so declare %s or %s instead.' ,
            __FUNCTION__ ,
            $model->collection ,
            Traversal::ANY ,
            $model->from?->collection ?? 'null' ,
            $model->to?->collection   ?? 'null' ,
            Traversal::INBOUND ,
            Traversal::OUTBOUND ,
        )) ;
    }

    return $model->to ;
}
