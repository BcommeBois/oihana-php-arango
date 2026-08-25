<?php

namespace oihana\arango\models\helpers\edges;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;

use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;
use oihana\exceptions\UnsupportedOperationException;

use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Compiles the grouping dimension of a **relation**: the value a document is
 * grouped by when that value lives on the document at the other end of an edge.
 *
 * The expression is a sub-query written **inline in the `COLLECT`**, and it has
 * to be, because a grouped query never projects. The `LET` a projected relation
 * emits — the one a relational *sort* names — is produced by `returnFields()`,
 * which a grouped query does not call: `doc` is consumed by the `COLLECT`, so
 * there is nothing to project and no variable to reach for. The dimension
 * therefore carries its own traversal:
 *
 * ```aql
 * COLLECT author = FIRST( FOR author_v IN OUTBOUND doc articles_authors RETURN author_v.name )
 * AGGREGATE total = SUM( doc.amount )
 * ```
 *
 * `FIRST()` is what makes the dimension a **scalar**, and it is why only a
 * singular relation may be grouped on. The two alternatives were measured, and
 * neither is acceptable:
 *
 * - keeping the sub-query as an **array** groups by the *combination* — three
 *   buckets (`["Alice"]`, `["Alice","Zoe"]`, `["Zoe"]`) for two authors;
 * - unwinding the relation with a `FOR` before the `COLLECT` duplicates the
 *   document once per related vertex, which **inflates every other aggregate of
 *   the same `COLLECT`**: over three documents worth 10, 20 and 30, a `SUM`
 *   answers 70 where the truth is 60, because the two-author document is counted
 *   twice.
 *
 * The declaration is read through the **same shared doors** as the list — the
 * depth range, the row scope (`AQL::WHERE` / `AQL::PRUNE`) and the traversal
 * options — so a grouped dimension describes exactly the relation the list
 * projects. Read differently, it would put a label next to rows that contradict it.
 *
 * @param string $key The dimension key; names the traversal variable (`<key>_v`).
 * @param array $definition The edge definition (`AQL::MODEL`, `AQL::DIRECTION`, …).
 * @param array|string $path The field of the related document carrying the value.
 * @param string $startVertex The document the traversal departs from.
 * @param ContainerInterface|null $container Resolves an `AQL::MODEL` given by container id.
 *
 * @return string The `FIRST( FOR … RETURN … )` expression.
 *
 * @throws BindException
 * @throws ConstantException
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws UnsupportedOperationException
 * @throws ValidationException
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function buildEdgeGroupExpression
(
    string              $key         ,
    array               $definition  ,
    array|string        $path        ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null
)
: string
{
    [ , $edgeCollection , $direction ] = resolveEdgeContext( $definition , $container ) ;

    $vertex = $key . '_v' ;

    [ $minDepth , $maxDepth ] = resolveEdgeDepthRange ( $definition ) ;
    [ $condition , $prune ]   = resolveEdgeVertexScope( $definition , $vertex ) ;

    return first( compile
    ([
        aqlTraversal
        ([
            AQL::DIRECTION       => $direction ,
            AQL::EDGE_COLLECTION => $edgeCollection ,
            AQL::START_VERTEX    => $startVertex ,
            AQL::VERTEX_REF      => $vertex ,
            AQL::MIN_DEPTH       => $minDepth ,
            AQL::MAX_DEPTH       => $maxDepth ,
            AQL::PRUNE           => $prune ,
            AQL::OPTIONS         => edgeTraversalOptions() ,
        ]) ,
        $condition !== null ? aqlFilter( $condition ) : null ,
        aqlReturn( key( compile( $path , '.' ) , $vertex ) ) ,
    ]) ) ;
}
