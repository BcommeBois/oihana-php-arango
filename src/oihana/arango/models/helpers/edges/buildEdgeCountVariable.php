<?php

namespace oihana\arango\models\helpers\edges;

use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;

/**
 * Generates a string of multiple AQL 'LET' statements for calculate
 * the number of edges of a specific document.
 *
 * **The count must answer the same question as the list.** A count and a
 * `Filter::EDGES` projection normally share one definition — the registry's string
 * shortcut (`'descendantsCount' => 'descendants'`) is the idiomatic way to say so —
 * therefore every part of that declaration shaping *which* vertices are walked has
 * to be read here exactly as {@see buildEdgeSubquery()} reads it. Three did not use
 * to be, each producing a number the rows contradicted:
 *
 * | Declaration | List | Count, before |
 * |---|---|---|
 * | `AQL::MAX_DEPTH => 5` over `a ─┬─ b ── c` / `└─ e ── f` | `[ b , c , e , f ]` → 4 | **2** (direct children only) |
 * | depth 1 with a duplicated `a → c` edge | `[ b , c ]` → 2 | **3** (counted twice) |
 * | `1..5` over the diamond `a → b → d` / `a → c → d` | `[ b , c , d ]` → 3 | **6** |
 *
 * All three are now read through the shared helpers — {@see resolveEdgeDepthRange()},
 * {@see resolveEdgeVertexScope()} and {@see edgeTraversalOptions()} — so the count
 * and the list cannot drift again. `AQL::WHERE` filters the counted loop and
 * `AQL::PRUNE` stops it, both compiled against the inner vertex.
 *
 * Nothing is emitted for a key that is not declared, so a definition without depth,
 * scope or prune produces the historical single-level count — plus the traversal
 * options, which is the one intentional change to the emitted AQL of an existing
 * definition (and the one that makes a duplicated edge stop being counted twice).
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
 * @throws UnexpectedValueException      If `AQL::MIN_DEPTH` is declared without `AQL::MAX_DEPTH`,
 *                                       or `AQL::PRUNE => true` has no `AQL::WHERE` to negate.
 * @throws UnsupportedOperationException If an `AQL::WHERE` / `AQL::PRUNE` condition descriptor is malformed.
 * @throws ValidationException           If an `AQL::WHERE` / `AQL::PRUNE` condition attribute name is unsafe.
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

    // The count walks the SAME declaration as the list, so it reads it through the
    // same three shared doors — the depth range, the row scope (AQL::WHERE and
    // AQL::PRUNE) and the traversal options. Anything read here differently would
    // put a number next to rows that contradict it.
    [ $minDepth , $maxDepth ] = resolveEdgeDepthRange ( $definition ) ;
    [ $condition , $prune ]   = resolveEdgeVertexScope( $definition , $innerVertex ) ;

    $filter = $condition !== null ? aqlFilter( $condition ) : null ;

    // LET $name = LENGTH( FOR <name>_v IN [min..max] OUTBOUND startVertex edgeCollection
    //                       [PRUNE ...] OPTIONS { ... } [FILTER ...] RETURN <name>_v )
    $expression = length( compile(
    [
        aqlTraversal
        ([
            AQL::DIRECTION       => $direction ,
            AQL::EDGE_COLLECTION => $edgeCollection ,
            AQL::START_VERTEX    => $startVertex ,
            AQL::VERTEX_REF      => $innerVertex ,
            AQL::MIN_DEPTH       => $minDepth ,
            AQL::MAX_DEPTH       => $maxDepth ,
            AQL::PRUNE           => $prune ,
            AQL::OPTIONS         => edgeTraversalOptions() ,
        ]) ,
        $filter ,
        aqlReturn ( $innerVertex )
    ])) ;

    return aqlLet( $varName , $expression , useParentheses: true ) ;
}