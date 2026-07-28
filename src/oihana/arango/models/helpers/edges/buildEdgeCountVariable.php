<?php

namespace oihana\arango\models\helpers\edges;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\fields\buildWhenCondition;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;

/**
 * Generates a string of multiple AQL 'LET' statements for calculate
 * the number of edges of a specific document.
 *
 * When the definition declares `AQL::WHERE`, the predicate is compiled against the
 * inner vertex and emitted as a `FILTER` inside the counted loop, so the count and
 * the list of {@see buildEdgeSubquery()} agree — a count ignoring the predicate
 * would announce "5" beside a list showing 3. Absent → no `FILTER`, byte-identical
 * output.
 *
 * @param string|null $name
 * @param array $definition
 * @param string $startVertex
 * @param ContainerInterface|null $container
 *
 * @return string|null
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws BindException
 * @throws ConstantException
 * @throws UnsupportedOperationException If the `AQL::WHERE` condition descriptor is malformed.
 * @throws ValidationException           If the `AQL::WHERE` condition attribute name is unsafe.
 */
function buildEdgeCountVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
)
:?string
{
    [ , $edgeCollection , $direction ] = resolveEdgeContext( $definition , $container ) ;

    $varName = $definition[ AQL::UNIQUE ] ?? $name ;

    // The inner count loop must NOT reuse the shared 'vertex' name: when this count
    // is projected through a vertex traversal (Edges::getVertices()), the outer loop
    // is already named 'vertex', which would trigger an "assigned multiple times" AQL
    // error. We derive a unique inner variable from $varName (itself a randomKey in
    // the live flow), keeping it deterministic so the $name of the LET never moves.
    $innerVertex = ( $varName ?: AQL::VERTEX ) . '_v' ;

    // AQL::WHERE restricts which traversed vertices the relation projects, so the
    // count MUST honour it too: a count that ignored the predicate would announce
    // "5" beside a list showing 3 — the divergence is the bug, not the filtering.
    // Same grammar as the list ({@see buildEdgeSubquery()}), compiled against the
    // inner vertex; absent → no FILTER, byte-identical output.
    $where  = $definition[ AQL::WHERE ] ?? null ;
    $filter = $where !== null ? aqlFilter( buildWhenCondition( $where , $innerVertex ) ) : null ;

    // LET $name = LENGTH( FOR <name>_v IN OUTBOUND startVertex edgeCollection [FILTER ...] RETURN <name>_v )
    $expression = length( compile(
    [
        aqlTraversal
        ([
            AQL::DIRECTION       => $direction ,
            AQL::EDGE_COLLECTION => $edgeCollection ,
            AQL::START_VERTEX    => $startVertex ,
            AQL::VERTEX_REF      => $innerVertex ,
        ]) ,
        $filter ,
        aqlReturn ( $innerVertex )
    ])) ;

    return aqlLet( $varName , $expression , useParentheses: true ) ;
}